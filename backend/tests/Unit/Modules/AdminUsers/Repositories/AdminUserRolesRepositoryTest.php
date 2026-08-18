<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Repositories;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminUserRolesRepository;
use App\Shared\Logging\Logger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The role set of an account, at the seam that stores it (ADR-0044, #514).
 *
 * Like `AdminUsersRepositoryTest` next door, this runs the real SQL against an
 * in-memory SQLite database rather than the Docker MariaDB instance — the
 * queries are plain and portable, and the *schema* rules (the role enum, the
 * cascade) are asserted where they are actually enforced, in
 * `Tests\Feature\Database\AdminUserRolesSchemaTest`.
 */
class AdminUserRolesRepositoryTest extends TestCase
{
    private PDO $db;
    private AdminUserRolesRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE admin_user_roles (
                admin_user_id CHAR(36) NOT NULL,
                role VARCHAR(32) NOT NULL,
                granted_at TIMESTAMP NULL,
                PRIMARY KEY (admin_user_id, role)
            )'
        );
        $this->db->exec(
            'CREATE TABLE admin_users (
                id CHAR(36) PRIMARY KEY,
                is_active INTEGER NOT NULL DEFAULT 1
            )'
        );

        $this->repository = new AdminUserRolesRepository($this->db, $this->createMock(Logger::class));
    }

    private function account(string $id, bool $active = true): void
    {
        $this->db->prepare('INSERT INTO admin_users (id, is_active) VALUES (?, ?)')
            ->execute([$id, $active ? 1 : 0]);
    }

    public function test_an_account_with_no_rows_holds_no_roles(): void
    {
        $this->assertSame([], $this->repository->rolesFor('nobody'));
    }

    public function test_a_written_role_set_is_read_back(): void
    {
        $this->repository->replace('admin-1', [AdminRole::ADMIN]);

        $this->assertSame([AdminRole::ADMIN], $this->repository->rolesFor('admin-1'));
    }

    /**
     * The reason roles are a relation and not a `role` column (ADR-0044 §4):
     * a Kassenwart covering the drinks list while the Getränkewart is away must
     * be able to do both without being promoted to `admin`.
     */
    public function test_an_account_holds_both_lesser_roles_at_once(): void
    {
        $this->repository->replace('admin-1', [AdminRole::KASSENWART, AdminRole::GETRAENKEWART]);

        $this->assertSame(
            [AdminRole::KASSENWART, AdminRole::GETRAENKEWART],
            $this->repository->rolesFor('admin-1'),
        );
    }

    public function test_roles_come_back_in_a_stable_order_regardless_of_how_they_were_written(): void
    {
        $this->repository->replace('admin-1', [AdminRole::GETRAENKEWART, AdminRole::ADMIN]);

        $this->assertSame(
            [AdminRole::ADMIN, AdminRole::GETRAENKEWART],
            $this->repository->rolesFor('admin-1'),
            'the enum declaration order is the order the panel and the audit log read',
        );
    }

    public function test_replacing_a_set_drops_the_roles_no_longer_in_it(): void
    {
        $this->repository->replace('admin-1', [AdminRole::ADMIN]);
        $this->repository->replace('admin-1', [AdminRole::KASSENWART]);

        $this->assertSame([AdminRole::KASSENWART], $this->repository->rolesFor('admin-1'));
    }

    /**
     * Writing the set an account already holds must not collide with itself:
     * the primary key on (admin_user_id, role) refuses a second copy, and
     * `PATCH` bodies that re-submit an unchanged role set are routine.
     */
    public function test_writing_an_unchanged_set_is_not_an_error(): void
    {
        $this->repository->replace('admin-1', [AdminRole::ADMIN, AdminRole::KASSENWART]);
        $this->repository->replace('admin-1', [AdminRole::ADMIN, AdminRole::KASSENWART]);

        $this->assertSame(
            [AdminRole::ADMIN, AdminRole::KASSENWART],
            $this->repository->rolesFor('admin-1'),
        );
    }

    public function test_one_accounts_roles_are_untouched_by_another_accounts_write(): void
    {
        $this->repository->replace('admin-1', [AdminRole::ADMIN]);
        $this->repository->replace('admin-2', [AdminRole::GETRAENKEWART]);

        $this->assertSame([AdminRole::ADMIN], $this->repository->rolesFor('admin-1'));
        $this->assertSame([AdminRole::GETRAENKEWART], $this->repository->rolesFor('admin-2'));
    }

    /**
     * A row written by hand, or one left behind by a role that a later release
     * removed, must not become a role object nobody can reason about.
     */
    public function test_a_stored_value_that_is_not_a_known_role_is_ignored_on_read(): void
    {
        $this->db->prepare('INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)')
            ->execute(['admin-1', 'superuser']);

        $this->assertSame([], $this->repository->rolesFor('admin-1'));
    }

    /**
     * The list page needs every account's roles without one query per row.
     */
    public function test_roles_are_resolved_for_several_accounts_at_once(): void
    {
        $this->repository->replace('admin-1', [AdminRole::ADMIN]);
        $this->repository->replace('admin-2', [AdminRole::KASSENWART, AdminRole::GETRAENKEWART]);

        $this->assertSame(
            [
                'admin-1' => [AdminRole::ADMIN],
                'admin-2' => [AdminRole::KASSENWART, AdminRole::GETRAENKEWART],
                'admin-3' => [],
            ],
            $this->repository->rolesForMany(['admin-1', 'admin-2', 'admin-3']),
        );
    }

    public function test_resolving_roles_for_an_empty_id_list_asks_the_database_nothing(): void
    {
        $this->assertSame([], $this->repository->rolesForMany([]));
    }

    /**
     * The guard rail the service layer builds "never neuter the last admin"
     * on: how many *active* accounts hold a given role right now.
     */
    public function test_counting_active_holders_of_a_role(): void
    {
        $this->account('admin-1');
        $this->account('admin-2');
        $this->repository->replace('admin-1', [AdminRole::ADMIN]);
        $this->repository->replace('admin-2', [AdminRole::ADMIN, AdminRole::KASSENWART]);

        $this->assertSame(2, $this->repository->countActiveHolders(AdminRole::ADMIN));
        $this->assertSame(1, $this->repository->countActiveHolders(AdminRole::KASSENWART));
        $this->assertSame(0, $this->repository->countActiveHolders(AdminRole::GETRAENKEWART));
    }

    public function test_a_deactivated_holder_does_not_count(): void
    {
        $this->account('admin-1', active: false);
        $this->repository->replace('admin-1', [AdminRole::ADMIN]);

        $this->assertSame(0, $this->repository->countActiveHolders(AdminRole::ADMIN));
    }
}
