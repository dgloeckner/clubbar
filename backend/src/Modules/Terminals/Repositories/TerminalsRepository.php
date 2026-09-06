<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Repositories;

use App\Shared\Security\CredentialLifecycle;
use App\Shared\Utils\Uuid;
use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class TerminalsRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, (SELECT MAX(tx.occurred_at) FROM transactions tx WHERE tx.created_by_terminal_id = t.id) AS last_transaction_at
             FROM terminals t WHERE t.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByDeviceId(string $deviceId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM terminals WHERE device_id = ? LIMIT 1');
        $stmt->execute([$deviceId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The authentication lookup (#106).
     *
     * Expiry is enforced here rather than in the middleware so that no caller
     * can authenticate a terminal without it, and the check is fail-closed: a
     * row that carries a token hash but no `token_expires_at` does not
     * authenticate. Every path that issues a token sets the column, so the only
     * rows that can be in that state are ones nobody meant to be usable.
     */
    public function findByTokenHash(string $sha256): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM terminals
              WHERE api_token_hash = ?
                AND is_active = 1
                AND token_expires_at IS NOT NULL
                AND token_expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([$sha256]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The other half of the authentication lookup: a token an admin issued but
     * nobody has used yet (#395).
     *
     * Held to exactly the same conditions as the active one — active terminal,
     * expiry present, expiry in the future — because this row is about to
     * *become* the active credential, and a pending token that would not pass
     * findByTokenHash() must not pass by being new.
     */
    public function findByPendingTokenHash(string $sha256): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM terminals
              WHERE pending_token_hash = ?
                AND is_active = 1
                AND pending_token_expires_at IS NOT NULL
                AND pending_token_expires_at > NOW()
              LIMIT 1'
        );
        $stmt->execute([$sha256]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Promote a pending token to active — the overlap rotation's closing move
     * (#395). The old hash is overwritten in the same statement that installs
     * the new one, so the credential it replaces is retired at the same instant
     * its successor starts working; there is no window in which three tokens,
     * or none, authenticate.
     *
     * Guarded on the hash as well as the id so two syncs arriving together
     * cannot both promote: the second `UPDATE` matches no row, and its caller
     * falls back to the ordinary active lookup, which the first has just filled
     * in. Returns the promoted row, or null when it lost that race.
     */
    public function promotePendingToken(string $id, string $sha256): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE terminals
                SET api_token_hash           = pending_token_hash,
                    token_issued_at          = pending_token_issued_at,
                    token_expires_at         = pending_token_expires_at,
                    pending_token_hash       = NULL,
                    pending_token_issued_at  = NULL,
                    pending_token_expires_at = NULL,
                    updated_at               = ?
              WHERE id = ? AND pending_token_hash = ?'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $id, $sha256]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        $this->logger->info('Terminal pending token promoted', ['id' => $id]);
        return $this->findById($id);
    }

    /**
     * Stage a replacement token without disturbing the one in the field (#395).
     *
     * The lifetime is measured from now, not from the promotion, so a token an
     * admin prepares and forgets does not sit around indefinitely waiting to
     * start a fresh year. Any earlier pending token is overwritten: the last
     * one an admin wrote down is the only one that should still work.
     */
    public function issuePendingToken(string $id, string $sha256, int $ttlDays): ?array
    {
        $days = self::ttlDays($ttlDays);

        $stmt = $this->db->prepare(
            "UPDATE terminals
                SET pending_token_hash       = ?,
                    pending_token_issued_at  = NOW(),
                    pending_token_expires_at = NOW() + INTERVAL {$days} DAY,
                    updated_at               = ?
              WHERE id = ?"
        );
        $stmt->execute([$sha256, date('Y-m-d H:i:s'), $id]);

        if ($stmt->rowCount() === 0 && $this->findById($id) === null) {
            return null;
        }

        $this->logger->info('Terminal pending token issued', ['id' => $id, 'ttl_days' => $days]);
        return $this->findById($id);
    }

    /**
     * Counterpart of findByTokenHash() for a token that once was valid.
     *
     * Used only to tell an expired token apart from an unknown one in the 401,
     * so the operator of a terminal that stopped syncing learns to rotate it
     * instead of hunting a typo. It never returns a terminal that authenticates.
     *
     * Covers the pending column too: a replacement token that was prepared and
     * then left unused past its own expiry aged out just the same, and telling
     * its operator "unknown token" would send them hunting a typo in a token
     * that was never wrong.
     */
    public function findExpiredByTokenHash(string $sha256): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM terminals
              WHERE is_active = 1
                AND (
                     (api_token_hash = ? AND (token_expires_at IS NULL OR token_expires_at <= NOW()))
                  OR (pending_token_hash = ? AND (pending_token_expires_at IS NULL OR pending_token_expires_at <= NOW()))
                )
              LIMIT 1'
        );
        $stmt->execute([$sha256, $sha256]);
        return $stmt->fetch() ?: null;
    }

    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM terminals ORDER BY created_at DESC')->fetchAll();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? Uuid::v4();
        $now = date('Y-m-d H:i:s');
        $ttlDays = self::ttlDays($data['token_ttl_days'] ?? null);

        $stmt = $this->db->prepare(
            "INSERT INTO terminals (id, name, device_id, api_token_hash, token_issued_at, token_expires_at, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW() + INTERVAL {$ttlDays} DAY, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $data['name'],
            $data['device_id'],
            $data['api_token_hash'],
            ($data['is_active'] ?? true) ? 1 : 0,
            $now,
            $now,
        ]);

        $this->logger->info('Terminal created', ['id' => $id]);
        return $this->findById($id);
    }

    public function updateById(string $id, array $data): ?array
    {
        $allowed = [
            'name', 'device_id', 'api_token_hash', 'is_active', 'last_sync_at',
            'token_issued_at', 'token_expires_at',
            'pending_token_hash', 'pending_token_issued_at', 'pending_token_expires_at',
        ];
        [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
        $values[] = date('Y-m-d H:i:s');
        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE terminals SET {$set}, updated_at = ? WHERE id = ?");
        $stmt->execute($values);

        $this->logger->info('Terminal updated', ['id' => $id]);
        return $this->findById($id);
    }

    /**
     * Stamp the sync, and — when the terminal said so — what it is running
     * (ADR-0054).
     *
     * One UPDATE, not two: this runs on *every* authenticated terminal request,
     * and a second write per sync to record a string that changes a few times a
     * year would be the most-executed statement in the system paying for the
     * rarest fact in it.
     *
     * Fail-open is the whole contract of the version columns. `$version` is
     * whatever the caller could make of the header, and a null leaves both
     * version columns exactly as they were — so an old terminal, a proxy that
     * strips headers, or a build that predates the header keeps selling drinks
     * and simply reports nothing. `$blockedVersion` is cleared by a null,
     * because a terminal that stops sending it has cleared its own block, and a
     * stale alarm nobody can dismiss is worse than none.
     */
    public function updateLastSync(string $id, ?string $version = null, ?string $blockedVersion = null): bool
    {
        $now = date('Y-m-d H:i:s');

        if ($version === null) {
            $stmt = $this->db->prepare('UPDATE terminals SET last_sync_at = ?, updated_at = ? WHERE id = ?');
            return $stmt->execute([$now, $now, $id]);
        }

        $stmt = $this->db->prepare(
            'UPDATE terminals
                SET last_sync_at = ?, reported_version = ?, reported_version_at = ?, blocked_version = ?, updated_at = ?
              WHERE id = ?'
        );
        return $stmt->execute([$now, $version, $now, $blockedVersion, $now, $id]);
    }

    public function listPaginated(int $limit, int $offset, ?bool $isActive = null): array
    {
        $where = [];
        $params = [];

        if ($isActive !== null) {
            $where[] = 'is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM terminals {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            "SELECT t.*, (SELECT MAX(tx.occurred_at) FROM transactions tx WHERE tx.created_by_terminal_id = t.id) AS last_transaction_at
             FROM terminals t {$whereClause} ORDER BY t.created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Sanitise a token lifetime before it is interpolated into an INTERVAL.
     *
     * MariaDB rejects a placeholder as the quantity of an INTERVAL, so the
     * value is inlined — casting to a positive int is what keeps that safe.
     * A missing or non-positive lifetime falls back to the shared credential
     * cryptoperiod (ADR-0036) rather than producing a token that is born
     * expired — the same figure AppConfig defaults to.
     */
    private static function ttlDays(mixed $ttlDays): int
    {
        $days = (int) $ttlDays;

        return $days > 0 ? $days : CredentialLifecycle::LIFETIME_DAYS;
    }
}
