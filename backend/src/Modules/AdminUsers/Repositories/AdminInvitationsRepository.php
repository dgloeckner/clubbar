<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Repositories;

use App\Shared\Logging\Logger;
use App\Shared\Utils\Uuid;
use PDO;

/**
 * The one-time links that let a newly created admin set their own password
 * (migration 058).
 *
 * Every lookup here is by **hash**. The raw token exists in exactly two places
 * — the URL in somebody's mailbox, and the sealed `token_cipher` the mail
 * builder reads to put it there — and this class never sees it in the clear:
 * callers hash first and pass the digest. That is what keeps "the database was
 * dumped" from meaning "every pending admin account was taken over".
 *
 * Rows are never deleted. An invitation that was accepted, expired or replaced
 * is the record of how an account came to have a password, which is the half of
 * the ADR-0044 account-creation trail the audit log alone cannot give: the log
 * says a link was issued, this says whether anybody walked through it.
 */
class AdminInvitationsRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    /**
     * Write a new invitation.
     *
     * @param string $tokenHash   SHA-256 hex of the raw token — the lookup key.
     * @param string $tokenCipher `SymmetricSecretBox` ciphertext of the same
     *                            token, for the mail builder to render a link
     *                            from at send time (ADR-0038 rule 5).
     * @param string $expiresAt   `Y-m-d H:i:s`.
     *
     * @return array<string,mixed> The row as written.
     */
    public function create(
        string $adminUserId,
        string $tokenHash,
        string $tokenCipher,
        string $expiresAt,
        ?string $createdBy,
    ): array {
        $id = Uuid::v4();

        $stmt = $this->db->prepare(
            'INSERT INTO admin_user_invitations '
            . '(id, admin_user_id, token_hash, token_cipher, expires_at, created_by, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $adminUserId, $tokenHash, $tokenCipher, $expiresAt, $createdBy, date('Y-m-d H:i:s')]);

        // The token is not logged, hashed or otherwise. A log line is a file
        // somebody greps, ships to a collector and keeps for a year.
        $this->logger->info('Admin invitation issued', ['id' => $id, 'admin_user_id' => $adminUserId]);

        return $this->findById($id) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_user_invitations WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * The invitation a presented link names, whatever state it is in.
     *
     * Deliberately not "the *valid* invitation": expiry and single use are
     * decided by the service, from the row, so that "this link expired" and
     * "this link never existed" can be told apart in the log while staying one
     * answer on the wire.
     *
     * @return array<string,mixed>|null
     */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM admin_user_invitations WHERE token_hash = ? LIMIT 1');
        $stmt->execute([$tokenHash]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Mark an invitation used. Returns false when it was already accepted or
     * revoked — the guard that makes acceptance single-use even if two requests
     * carrying the same link arrive at once.
     *
     * The check is in the `WHERE` clause rather than in a preceding `SELECT`
     * for exactly that reason: a read-then-write would let both requests pass
     * the read, and the second one would silently overwrite the first admin's
     * password with the second one's.
     */
    public function markAccepted(string $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE admin_user_invitations SET accepted_at = ? '
            . 'WHERE id = ? AND accepted_at IS NULL AND revoked_at IS NULL'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Retire every live invitation for an account, so issuing a replacement
     * cannot leave two working links to it.
     *
     * @return int How many were retired.
     */
    public function revokeOutstandingFor(string $adminUserId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE admin_user_invitations SET revoked_at = ? '
            . 'WHERE admin_user_id = ? AND accepted_at IS NULL AND revoked_at IS NULL'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $adminUserId]);

        return $stmt->rowCount();
    }

    /** Whether this account has ever completed an invitation. */
    public function hasAccepted(string $adminUserId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM admin_user_invitations WHERE admin_user_id = ? AND accepted_at IS NOT NULL LIMIT 1'
        );
        $stmt->execute([$adminUserId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Accounts still waiting on an invitation, out of the ids given.
     *
     * One query for a page of accounts rather than one per row: the admin list
     * renders a "pending" marker from this, and N+1 here would be paid on every
     * keystroke of the admin search — the same reasoning
     * {@see AdminUserRolesRepository::rolesForMany()} is built on.
     *
     * "Pending" is *has been invited and has never accepted*. An account
     * predating invitations has no row here at all and is therefore not
     * pending, which is right: it was created with a password somebody already
     * passed on, and the way back in for it is a reset, not a link.
     *
     * @param list<string> $adminUserIds
     * @return array<string,bool> Keyed by admin user id, `true` for pending.
     */
    public function pendingByAdminIds(array $adminUserIds): array
    {
        if ($adminUserIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($adminUserIds), '?'));

        $stmt = $this->db->prepare(
            'SELECT admin_user_id, MAX(accepted_at IS NOT NULL) AS accepted '
            . "FROM admin_user_invitations WHERE admin_user_id IN ({$placeholders}) "
            . 'GROUP BY admin_user_id'
        );
        $stmt->execute(array_values($adminUserIds));

        $pending = [];
        foreach ($stmt->fetchAll() as $row) {
            $pending[(string) $row['admin_user_id']] = ((int) $row['accepted']) === 0;
        }

        return $pending;
    }
}
