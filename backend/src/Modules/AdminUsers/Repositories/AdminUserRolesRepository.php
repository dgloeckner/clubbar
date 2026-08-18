<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Repositories;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Shared\Logging\Logger;
use PDO;

/**
 * An admin account's role set (ADR-0044, #514).
 *
 * Its own repository rather than three more methods on `AdminUsersRepository`,
 * because it is its own table and, from #519 on, its own read path: the
 * middleware resolves the caller's roles on every admin request and has no
 * interest in the account row's password hash or TOTP secret.
 *
 * Every read goes through `AdminRole::fromValues()`, so a value the enum does
 * not name — a hand-edited row, or a role a later release removed — comes back
 * as no role rather than as something the caller has to guess about.
 */
class AdminUserRolesRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    /** @return list<AdminRole> */
    public function rolesFor(string $adminUserId): array
    {
        $stmt = $this->db->prepare('SELECT role FROM admin_user_roles WHERE admin_user_id = ?');
        $stmt->execute([$adminUserId]);

        return AdminRole::fromValues($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The same question for a page of accounts, in one query.
     *
     * Every id asked for appears in the result, including the ones holding
     * nothing — a caller iterating a list must not have to distinguish "no
     * roles" from "not asked about".
     *
     * @param list<string> $adminUserIds
     * @return array<string, list<AdminRole>>
     */
    public function rolesForMany(array $adminUserIds): array
    {
        if ($adminUserIds === []) {
            return [];
        }

        $byId = array_fill_keys($adminUserIds, []);

        $placeholders = implode(',', array_fill(0, count($adminUserIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT admin_user_id, role FROM admin_user_roles WHERE admin_user_id IN ({$placeholders})"
        );
        $stmt->execute(array_values($adminUserIds));

        $values = [];
        foreach ($stmt->fetchAll() as $row) {
            $values[$row['admin_user_id']][] = $row['role'];
        }

        foreach ($values as $id => $roleValues) {
            $byId[$id] = AdminRole::fromValues($roleValues);
        }

        return $byId;
    }

    /**
     * How many *active* accounts hold this role right now.
     *
     * The read `AdminUsersService` builds its "never neuter the last admin"
     * guard on (#548): a role can be revoked from an inactive account without
     * tripping it, since an inactive account grants nobody anything today.
     */
    public function countActiveHolders(AdminRole $role): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM admin_user_roles r
             JOIN admin_users u ON u.id = r.admin_user_id
             WHERE r.role = ? AND u.is_active = 1'
        );
        $stmt->execute([$role->value]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Make the account's roles exactly this set.
     *
     * Delete-then-insert inside a transaction, rather than a diff: the set is
     * at most three rows, and "what the account holds afterwards" is then a
     * property of the argument alone rather than of the argument plus whatever
     * was there before. The transaction is what keeps a failed insert from
     * leaving an account holding nothing.
     *
     * @param list<AdminRole> $roles
     */
    public function replace(string $adminUserId, array $roles): void
    {
        $this->db->beginTransaction();

        try {
            $this->db->prepare('DELETE FROM admin_user_roles WHERE admin_user_id = ?')
                ->execute([$adminUserId]);

            $insert = $this->db->prepare(
                'INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)'
            );
            // Round-tripped through the enum so a caller handing the same role
            // twice writes one row rather than colliding with the primary key.
            foreach (AdminRole::fromValues(AdminRole::toValues($roles)) as $role) {
                $insert->execute([$adminUserId, $role->value]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->logger->info('Admin user roles replaced', [
            'id' => $adminUserId,
            'roles' => AdminRole::toValues($roles),
        ]);
    }
}
