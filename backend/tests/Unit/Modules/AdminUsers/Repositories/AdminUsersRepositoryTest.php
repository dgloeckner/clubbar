<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Repositories;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Shared\Logging\Logger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * AdminUsersRepository runs plain, portable SQL against the `admin_users`
 * table (see db/migrations/001_initial_schema.sql, 004_totp_2fa.sql,
 * 010_totp_replay_protection.sql, 029_admin_credentials_epoch.sql), so an
 * in-memory SQLite database exercises the real queries without needing the
 * Docker MariaDB instance.
 *
 * The schema below is a hand-maintained copy: a migration that adds a column
 * this repository reads or writes has to be reflected here too, or the method
 * using it fails on a table that the production database has and this one does
 * not.
 */
class AdminUsersRepositoryTest extends TestCase
{
    private PDO $db;
    private AdminUsersRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE admin_users (
                id CHAR(36) NOT NULL PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NULL,
                locale VARCHAR(10) NOT NULL DEFAULT "de",
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_login_at TIMESTAMP NULL,
                credentials_changed_at TIMESTAMP NULL,
                totp_secret VARCHAR(255) NULL,
                totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
                totp_last_timestep BIGINT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )'
        );

        // The role table of migration 045, in the same hand-maintained spirit as
        // the one above: `findActiveRecipientsWithAnyRole()` joins it, so a
        // schema without it exercises nothing.
        $this->db->exec(
            'CREATE TABLE admin_user_roles (
                admin_user_id CHAR(36) NOT NULL,
                role VARCHAR(32) NOT NULL,
                PRIMARY KEY (admin_user_id, role)
            )'
        );

        $this->repository = new AdminUsersRepository($this->db, $this->createMock(Logger::class));
        $this->repository->create([
            'id' => 'admin-1',
            'email' => 'admin@example.com',
            'password' => 'hashed',
            'display_name' => 'Test Admin',
        ]);
    }

    public function test_saveTotp_enrolls_and_records_the_confirming_time_step(): void
    {
        $this->repository->saveTotp('admin-1', 'iv:ciphertext', 12345);

        $admin = $this->repository->findById('admin-1');

        $this->assertSame('iv:ciphertext', $admin['totp_secret']);
        $this->assertSame(1, (int) $admin['totp_enabled']);
        $this->assertSame(12345, (int) $admin['totp_last_timestep']);
    }

    public function test_updateTotpLastTimestep_updates_only_that_column(): void
    {
        $this->repository->saveTotp('admin-1', 'iv:ciphertext', 100);

        $this->repository->updateTotpLastTimestep('admin-1', 101);

        $admin = $this->repository->findById('admin-1');
        $this->assertSame(101, (int) $admin['totp_last_timestep']);
        $this->assertSame('iv:ciphertext', $admin['totp_secret']);
        $this->assertSame(1, (int) $admin['totp_enabled']);
    }

    public function test_clearTotp_also_clears_the_replay_tracking_time_step(): void
    {
        $this->repository->saveTotp('admin-1', 'iv:ciphertext', 100);

        $this->repository->clearTotp('admin-1');

        $admin = $this->repository->findById('admin-1');
        $this->assertNull($admin['totp_secret']);
        $this->assertSame(0, (int) $admin['totp_enabled']);
        $this->assertNull($admin['totp_last_timestep']);
    }

    /* ─────────── The credentials epoch (PR #469, ADR-0026 amendment) ─────────── */

    /**
     * One timestamp per account is what ends every session older than a
     * credential change — no session store, no enumeration.
     */
    public function test_touchCredentialsEpoch_stamps_the_account(): void
    {
        $this->assertNull($this->repository->findById('admin-1')['credentials_changed_at']);

        $written = $this->repository->touchCredentialsEpoch('admin-1');

        $this->assertSame($written, $this->repository->findById('admin-1')['credentials_changed_at']);
    }

    /**
     * The return value is not incidental: the acting request stamps its own
     * session past this moment so it is not refused by its own write. A method
     * that wrote the epoch but reported a different one would sign the caller
     * out of the change they just made.
     */
    public function test_touchCredentialsEpoch_returns_the_timestamp_it_wrote(): void
    {
        $written = $this->repository->touchCredentialsEpoch('admin-1');

        $this->assertNotFalse(strtotime($written), 'a parseable timestamp');
        $this->assertLessThanOrEqual(1, abs(time() - strtotime($written)), 'stamped at now');
    }

    /** It moves only the account it names. */
    public function test_touchCredentialsEpoch_leaves_other_accounts_alone(): void
    {
        $this->repository->create([
            'id' => 'admin-2',
            'email' => 'other@example.com',
            'password' => 'hashed',
            'display_name' => 'Other Admin',
            'locale' => 'de',
        ]);

        $this->repository->touchCredentialsEpoch('admin-1');

        $this->assertNotNull($this->repository->findById('admin-1')['credentials_changed_at']);
        $this->assertNull($this->repository->findById('admin-2')['credentials_changed_at']);
    }

    /* ─────────── The fan-out list, narrowed to an office (#633) ─────────── */

    /**
     * The query the admin fan-out runs. Operational mail is addressed to the
     * office that can open the screen it is about (ADR-0044), so an account
     * holding only `getraenkewart` is not on the list for an `admin` kind.
     */
    public function test_findActiveRecipientsWithAnyRole_returns_only_holders_of_the_offices_asked_for(): void
    {
        $this->givenAdmin('admin-2', 'kassenwart@example.com', ['kassenwart']);
        $this->givenAdmin('admin-3', 'bar@example.com', ['getraenkewart']);
        $this->grant('admin-1', 'admin');

        $this->assertSame(
            ['admin@example.com'],
            $this->addressesFor([AdminRole::ADMIN]),
        );
        $this->assertSame(
            ['admin@example.com', 'kassenwart@example.com'],
            $this->addressesFor([AdminRole::ADMIN, AdminRole::KASSENWART]),
            'ordered by address, as the unfiltered list is'
        );
    }

    /**
     * Roles are additive and stored one row per role, so an account holding two
     * of the offices asked for joins twice. One recipient, not two identical
     * messages.
     */
    public function test_findActiveRecipientsWithAnyRole_counts_a_two_office_account_once(): void
    {
        $this->givenAdmin('admin-2', 'both@example.com', ['kassenwart', 'getraenkewart']);

        $this->assertSame(
            ['both@example.com'],
            $this->addressesFor([AdminRole::KASSENWART, AdminRole::GETRAENKEWART]),
        );
    }

    /** A deactivated account no longer runs the club, whatever it still holds. */
    public function test_findActiveRecipientsWithAnyRole_skips_a_deactivated_holder(): void
    {
        $this->givenAdmin('admin-2', 'former@example.com', ['admin'], active: false);
        $this->grant('admin-1', 'admin');

        $this->assertSame(['admin@example.com'], $this->addressesFor([AdminRole::ADMIN]));
    }

    /**
     * An account carrying no role row at all holds no office, so it is on no
     * fan-out — the fail-closed reading ADR-0044 rule 1 gives an unclassified
     * route. Migration 045 backfills every pre-roles account to `admin`, so in
     * a real installation this is a hand-edited row rather than an upgrade.
     */
    public function test_findActiveRecipientsWithAnyRole_skips_an_account_holding_nothing(): void
    {
        $this->assertSame([], $this->addressesFor([AdminRole::ADMIN]));
        $this->assertSame(
            ['admin@example.com'],
            array_column($this->repository->findActiveRecipients(), 'email'),
            'and is still resolvable by the mail builders, which name whoever a row was written to'
        );
    }

    /** No offices asked for selects nobody, never everybody. */
    public function test_findActiveRecipientsWithAnyRole_selects_nobody_for_an_empty_role_set(): void
    {
        $this->grant('admin-1', 'admin');

        $this->assertSame([], $this->repository->findActiveRecipientsWithAnyRole([]));
    }

    /** @param list<string> $roles */
    private function givenAdmin(string $id, string $email, array $roles, bool $active = true): void
    {
        $this->repository->create([
            'id' => $id,
            'email' => $email,
            'password' => 'hashed',
            'display_name' => $email,
            'is_active' => $active,
        ]);
        foreach ($roles as $role) {
            $this->grant($id, $role);
        }
    }

    private function grant(string $id, string $role): void
    {
        $this->db->prepare('INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)')
            ->execute([$id, $role]);
    }

    /**
     * @param list<AdminRole> $roles
     * @return list<string>
     */
    private function addressesFor(array $roles): array
    {
        return array_column($this->repository->findActiveRecipientsWithAnyRole($roles), 'email');
    }
}
