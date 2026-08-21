<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Repositories;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Shared\Utils\Uuid;
use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class AdminUsersRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Every active admin account, whatever office it holds (#438).
     *
     * Active only: a deactivated account is somebody who no longer runs the
     * club, and mailing them about the club's expiring credentials is both
     * useless and a small disclosure.
     *
     * Deliberately narrow — id, address and locale, and no password hash or
     * TOTP column near a mail path.
     *
     * **This one stays unfiltered on purpose.** It reads like the fan-out list
     * and is no longer used as one: #633 moved `AdminNotifier::warnAdmins()` to
     * {@see findActiveRecipientsWithAnyRole()}. What is left are the two mail
     * builders resolving a display name for the `admin_user_id` already written
     * on an outbox row, and they must keep resolving whoever was actually
     * written to — including an account whose roles have moved since, or one
     * that would not be written to today. Adding a `WHERE` clause here would
     * silently blank those names instead.
     *
     * @return list<array{id: string, email: string, locale: string, display_name: ?string}>
     */
    public function findActiveRecipients(): array
    {
        return $this->db
            ->query('SELECT id, email, locale, display_name FROM admin_users WHERE is_active = 1 ORDER BY email ASC')
            ->fetchAll();
    }

    /**
     * The same list, narrowed to the accounts holding at least one of `$roles`
     * (#633, ADR-0044).
     *
     * The fan-out list. A notice about an encryption key or a terminal
     * credential belongs to the office that can open the screen it is about,
     * and until this existed the mail channel was the one place the role model
     * did not apply — every notice reached every active account, a
     * `getraenkewart` among them.
     *
     * Roles are additive and stored one row per role, so an account holding two
     * of the roles asked for joins twice; `DISTINCT` is what keeps it one
     * recipient rather than two identical messages. An empty `$roles` selects
     * nobody rather than everybody — the same fail-closed reading ADR-0044 rule
     * 1 gives an unclassified route.
     *
     * @param list<AdminRole> $roles
     * @return list<array{id: string, email: string, locale: string, display_name: ?string}>
     */
    public function findActiveRecipientsWithAnyRole(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $values = AdminRole::toValues($roles);
        $placeholders = implode(',', array_fill(0, count($values), '?'));

        $stmt = $this->db->prepare(
            'SELECT DISTINCT a.id, a.email, a.locale, a.display_name
               FROM admin_users a
               JOIN admin_user_roles r ON r.admin_user_id = a.id
              WHERE a.is_active = 1
                AND r.role IN (' . $placeholders . ')
              ORDER BY a.email ASC'
        );
        $stmt->execute($values);

        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? Uuid::v4();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $data['email'],
            $data['password'],
            $data['display_name'],
            $data['locale'] ?? 'de',
            ($data['is_active'] ?? true) ? 1 : 0,
            $now,
            $now,
        ]);

        $this->logger->info('Admin user created', ['id' => $id]);
        return $this->findById($id);
    }

    public function updateById(string $id, array $data): ?array
    {
        $allowed = ['email', 'password_hash', 'display_name', 'locale', 'is_active', 'last_login_at'];

        // Handle password field -> password_hash mapping
        if (isset($data['password']) && !isset($data['password_hash'])) {
            $data['password_hash'] = $data['password'];
            unset($data['password']);
        }

        [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
        $values[] = date('Y-m-d H:i:s');
        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE admin_users SET {$set}, updated_at = ? WHERE id = ?");
        $stmt->execute($values);

        $this->logger->info('Admin user updated', ['id' => $id]);
        return $this->findById($id);
    }

    /**
     * Persist an encrypted TOTP secret, mark the account as enrolled, and
     * record the time-step of the code that confirmed enrollment so the very
     * next MFA login cannot replay it (#338).
     */
    public function saveTotp(string $id, string $encryptedSecret, int $timestep): void
    {
        $stmt = $this->db->prepare(
            'UPDATE admin_users SET totp_secret = ?, totp_enabled = 1, totp_last_timestep = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$encryptedSecret, $timestep, date('Y-m-d H:i:s'), $id]);
        $this->logger->info('Admin user TOTP enrolled', ['id' => $id]);
    }

    /**
     * Remove TOTP credentials and mark the account as unenrolled. Clears the
     * replay-tracking time-step too, since it is meaningless without a secret.
     */
    public function clearTotp(string $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0, totp_last_timestep = NULL, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
        $this->logger->info('Admin user TOTP reset', ['id' => $id]);
    }

    /**
     * Stamp the credentials epoch: every session on this account authenticated
     * before now is refused by `AdminSessionAuth` from the next request on.
     *
     * Deliberately not part of `updateById`'s allowed-column list — this is not
     * a field a caller sets, it is a consequence of having changed a credential,
     * and it is written by the three services that do so.
     *
     * @return string The timestamp written, so the caller can keep the acting
     *  session alive by re-stamping it to at least this moment.
     */
    public function touchCredentialsEpoch(string $id): string
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE admin_users SET credentials_changed_at = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$now, $now, $id]);
        $this->logger->info('Admin credentials epoch advanced', ['id' => $id]);

        return $now;
    }

    /**
     * Record the time-step of the most recently accepted TOTP code for this
     * admin. AuthController::mfa refuses any future code at or below it (#338).
     */
    public function updateTotpLastTimestep(string $id, int $timestep): void
    {
        $stmt = $this->db->prepare(
            'UPDATE admin_users SET totp_last_timestep = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$timestep, date('Y-m-d H:i:s'), $id]);
    }

    public function listPaginated(int $limit, int $offset, array $filters = []): array
    {
        $where = [];
        $params = [];

        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') { $where[] = 'is_active = 1'; }
            elseif ($filters['status'] === 'inactive') { $where[] = 'is_active = 0'; }
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM admin_users {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare("SELECT * FROM admin_users {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
