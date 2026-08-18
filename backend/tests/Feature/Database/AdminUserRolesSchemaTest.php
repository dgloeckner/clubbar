<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

/**
 * `admin_user_roles` — the relation ADR-0044 puts an account's roles in, and
 * the behaviour-preserving backfill that fills it (#514).
 *
 * Three properties are worth asserting at the schema seam rather than in a
 * service, because the schema is what actually holds them:
 *
 * - the role vocabulary is closed (a fourth role is a migration, not a typo),
 * - an account may hold several roles at once, which is why this is a relation
 *   and not a `role` column on `admin_users`,
 * - the backfill *adds* `admin` and removes nothing. ADR-0044 §Migration is
 *   explicit that a migration must never remove access, and that is a property
 *   of the statement in the file, so the statement in the file is what runs here.
 */
class AdminUserRolesSchemaTest extends SchemaTestCase
{
    /** The account `db/seed.sql` ships, which every E2E run logs in as. */
    private const SEEDED_ADMIN_ID = '123e4567-e89b-12d3-a456-426614174000';

    public function test_the_role_column_offers_exactly_the_three_roles(): void
    {
        $type = $this->db
            ->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_user_roles' AND COLUMN_NAME = 'role'"
            )
            ->fetchColumn();

        $this->assertSame("enum('admin','kassenwart','getraenkewart')", $type);
    }

    public function test_a_role_outside_the_vocabulary_is_refused(): void
    {
        $adminId = $this->createAdminUser();

        $this->assertDatabaseRefuses(
            fn () => $this->grant($adminId, 'superuser'),
            'a role the enum does not name must not be storable — ADR-0044 rejects permissions as data'
        );
    }

    public function test_an_account_holds_both_lesser_roles_at_once(): void
    {
        $adminId = $this->createAdminUser();

        $this->grant($adminId, 'kassenwart');
        $this->grant($adminId, 'getraenkewart');

        $this->assertSame(['kassenwart', 'getraenkewart'], $this->rolesOf($adminId));
    }

    public function test_the_same_role_cannot_be_granted_twice_to_one_account(): void
    {
        $adminId = $this->createAdminUser();
        $this->grant($adminId, 'kassenwart');

        $this->assertDatabaseRefuses(
            fn () => $this->grant($adminId, 'kassenwart'),
            'the primary key on (admin_user_id, role) must keep one grant per role'
        );
    }

    /**
     * Deleting an account takes its grants with it. Left behind, they would be
     * re-attached to whoever next received that UUID — and the ids here are
     * generated, so that is only a theoretical rather than an impossible event.
     */
    public function test_deleting_an_account_removes_its_grants(): void
    {
        $adminId = $this->createAdminUser();
        $this->grant($adminId, 'admin');

        $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$adminId]);

        $this->assertSame([], $this->rolesOf($adminId));
    }

    public function test_a_grant_cannot_name_an_account_that_does_not_exist(): void
    {
        $this->assertDatabaseRefuses(
            fn () => $this->grant($this->generateUuid(), 'admin'),
            'a grant must name a real account — a dangling row is a role nobody can revoke'
        );
    }

    /**
     * The behaviour-preserving property, run against the statement the
     * migration file actually carries: an account that held nothing — which is
     * every account on the day this ships — comes out holding `admin`.
     */
    public function test_the_backfill_gives_an_account_with_no_roles_the_admin_role(): void
    {
        $adminId = $this->createAdminUser();

        $this->db->exec($this->backfillStatement());

        $this->assertSame(['admin'], $this->rolesOf($adminId));
    }

    /**
     * ADR-0044 §Migration: *a migration must never remove access*. Re-running
     * the backfill over an account that already holds a role leaves that role
     * in place — it only ever adds.
     */
    public function test_the_backfill_removes_nothing_it_finds(): void
    {
        $adminId = $this->createAdminUser();
        $this->grant($adminId, 'kassenwart');

        $this->db->exec($this->backfillStatement());

        $this->assertSame(['admin', 'kassenwart'], $this->rolesOf($adminId));
    }

    /**
     * Seed parity. The migration backfills the rows that exist when it runs,
     * and `db/seed.sql` inserts its admin *afterwards* — so without its own
     * grant the account every E2E run logs in as would hold no role at all.
     */
    public function test_the_seeded_admin_account_holds_the_admin_role(): void
    {
        $seeded = $this->db->prepare('SELECT COUNT(*) FROM admin_users WHERE id = ?');
        $seeded->execute([self::SEEDED_ADMIN_ID]);

        if ((int) $seeded->fetchColumn() === 0) {
            $this->markTestSkipped('database was not seeded from db/seed.sql');
        }

        $this->assertSame(['admin'], $this->rolesOf(self::SEEDED_ADMIN_ID));
    }

    private function grant(string $adminUserId, string $role): void
    {
        $this->db->prepare('INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)')
            ->execute([$adminUserId, $role]);
    }

    /**
     * @return list<string> role values in the ENUM's declaration order — an
     *  ENUM sorts by ordinal, not alphabetically, so this is the ladder's own
     *  order (`admin`, `kassenwart`, `getraenkewart`) and matches what
     *  `AdminRole::fromValues()` returns.
     */
    private function rolesOf(string $adminUserId): array
    {
        $stmt = $this->db->prepare('SELECT role FROM admin_user_roles WHERE admin_user_id = ? ORDER BY role ASC');
        $stmt->execute([$adminUserId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * The backfill exactly as migration 044 ships it.
     *
     * Copying it into the test would assert that a copy is behaviour-preserving
     * while the file drifted; this reads the file, so an edit to the migration
     * that started demoting people fails here.
     */
    private function backfillStatement(): string
    {
        $sql = file_get_contents(__DIR__ . '/../../../db/migrations/045_admin_user_roles.sql');
        $this->assertNotFalse($sql, 'migration 044 must exist');

        // Drop `--` comment lines first: this file's header is prose, and prose
        // contains both semicolons and the word INSERT.
        $sql = implode("\n", array_filter(
            explode("\n", $sql),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
        ));

        foreach (explode(';', $sql) as $statement) {
            if (stripos(ltrim($statement), 'INSERT') === 0) {
                return $statement;
            }
        }

        $this->fail('migration 044 carries no backfill INSERT');
    }
}
