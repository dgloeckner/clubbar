<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Repositories;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailStatus;
use App\Shared\Logging\Logger;
use App\Shared\Utils\Uuid;
use PDO;

/**
 * The transactional outbox (ADR-0038).
 *
 * Every write here is designed to be safe inside somebody else's transaction:
 * `enqueue()` is called from within `createSettlement`'s, and it neither opens
 * nor commits one of its own. The queue and the settlement commit together or
 * neither exists — which is the whole reason a half-finalize cannot happen.
 */
class MailOutboxRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    /**
     * Queue one message, or leave the existing one alone.
     *
     * The `ON DUPLICATE KEY UPDATE id = id` is not a trick to save a lookup —
     * it is the idempotency guarantee itself. `UNIQUE (kind, settlement_id,
     * member_id)` means a repeated enqueue for the same settlement cannot
     * produce a second announcement, and the database says so without a
     * read-then-write that two concurrent requests could both pass.
     *
     * The no-op update is deliberately narrower than `INSERT IGNORE`, which
     * downgrades *every* error to a warning: a foreign key pointing at a member
     * who no longer exists would vanish silently instead of aborting the
     * settlement it belongs to.
     *
     * @return bool True when a row was inserted, false when one already existed.
     */
    public function enqueue(
        MailKind $kind,
        string $settlementId,
        string $memberId,
        string $recipient,
        string $language,
    ): bool {
        $stmt = $this->db->prepare(
            'INSERT INTO mail_outbox (id, kind, settlement_id, member_id, recipient, language, status, queued_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE id = id'
        );

        $stmt->execute([
            Uuid::v4(),
            $kind->value,
            $settlementId,
            $memberId,
            $recipient,
            $language,
            MailStatus::PENDING->value,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Close out announcements that never left the host, because the settlement
     * they announce has been cancelled.
     *
     * Only `pending` rows move. A `failed` row is already closed, and a `sent`
     * one has reached somebody — that member gets a cancellation notice
     * instead, which is the other half of `NotificationsService::cancel()`.
     *
     * @return int How many rows were superseded.
     */
    public function supersedePending(string $settlementId, MailKind $kind): int
    {
        $stmt = $this->db->prepare(
            'UPDATE mail_outbox
                SET status = ?, claim_token = NULL, claimed_at = NULL
              WHERE settlement_id = ? AND kind = ? AND status = ?'
        );
        $stmt->execute([
            MailStatus::SUPERSEDED->value,
            $settlementId,
            $kind->value,
            MailStatus::PENDING->value,
        ]);

        return $stmt->rowCount();
    }

    /**
     * The members of this settlement whose message of $kind reached the
     * transport — the only ones a cancellation notice may go to.
     *
     * @return list<string> Member ids.
     */
    public function findMemberIdsWithStatus(string $settlementId, MailKind $kind, MailStatus $status): array
    {
        $stmt = $this->db->prepare(
            'SELECT member_id FROM mail_outbox WHERE settlement_id = ? AND kind = ? AND status = ?'
        );
        $stmt->execute([$settlementId, $kind->value, $status->value]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    /** @return list<array<string,mixed>> Every queued message for one settlement, newest kind last. */
    public function findBySettlementId(string $settlementId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM mail_outbox WHERE settlement_id = ? ORDER BY kind ASC, queued_at ASC'
        );
        $stmt->execute([$settlementId]);

        return $stmt->fetchAll();
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Take ownership of up to $limit due messages and return them.
     *
     * Claim by `UPDATE`, then select by token. Deliberately **not**
     * `SELECT ... FOR UPDATE SKIP LOCKED`: that wants MariaDB 10.6+, and on
     * mass hosting the database version is the host's decision, not ours
     * (ADR-0038).
     *
     * The stale window is what stops a killed run from stranding rows forever —
     * a claim nobody has touched for $staleMinutes is up for grabs again. It is
     * also why two concurrent drains send exactly N mails and never N+1: the
     * `UPDATE` is atomic, so a row is stamped with exactly one token.
     *
     * @return list<array<string,mixed>>
     */
    public function claimBatch(int $limit, int $staleMinutes = 5): array
    {
        if ($limit < 1) {
            return [];
        }

        $token = Uuid::v4();

        $claim = $this->db->prepare(
            'UPDATE mail_outbox
                SET claim_token = ?, claimed_at = NOW()
              WHERE status = ?
                AND next_attempt_at <= NOW()
                AND (claimed_at IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
              ORDER BY queued_at ASC
              LIMIT ?'
        );
        $claim->bindValue(1, $token);
        $claim->bindValue(2, MailStatus::PENDING->value);
        $claim->bindValue(3, $staleMinutes, PDO::PARAM_INT);
        $claim->bindValue(4, $limit, PDO::PARAM_INT);
        $claim->execute();

        if ($claim->rowCount() === 0) {
            return [];
        }

        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE claim_token = ? ORDER BY queued_at ASC');
        $stmt->execute([$token]);

        return $stmt->fetchAll();
    }

    public function markSent(string $id, ?string $messageId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mail_outbox
                SET status = ?, sent_at = NOW(), message_id = ?, last_error = NULL,
                    attempts = attempts + 1, claim_token = NULL, claimed_at = NULL
              WHERE id = ?'
        );
        $stmt->execute([MailStatus::SENT->value, $messageId, $id]);
    }

    /**
     * Record a failure and decide whether there is another go.
     *
     * A transient failure below the cap goes back to `pending` with a backoff —
     * greylisting is the case this exists for, and it is ordinary operation
     * rather than an error. Anything else, or a transient one that has used up
     * its attempts, becomes `failed` with the reason the Kassenwart reads.
     *
     * @return MailStatus The status the row now carries.
     */
    public function markFailed(string $id, string $error, bool $transient, int $maxAttempts, int $backoffSeconds): MailStatus
    {
        $current = $this->findById($id);
        $attempts = (int) ($current['attempts'] ?? 0) + 1;

        $retry = $transient && $attempts < $maxAttempts;
        $status = $retry ? MailStatus::PENDING : MailStatus::FAILED;

        $stmt = $this->db->prepare(
            'UPDATE mail_outbox
                SET status = ?, attempts = ?, last_error = ?,
                    next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    claim_token = NULL, claimed_at = NULL
              WHERE id = ?'
        );
        $stmt->bindValue(1, $status->value);
        $stmt->bindValue(2, $attempts, PDO::PARAM_INT);
        // The column is TEXT, but an SMTP server can answer with a great deal
        // of prose and this string is rendered in an admin table.
        $stmt->bindValue(3, mb_substr($error, 0, 1000));
        $stmt->bindValue(4, $retry ? $backoffSeconds : 0, PDO::PARAM_INT);
        $stmt->bindValue(5, $id);
        $stmt->execute();

        if (!$retry) {
            $this->logger->warning('Mail permanently failed', ['outbox_id' => $id, 'attempts' => $attempts]);
        }

        return $status;
    }

    /**
     * Put one row back in the queue — what #407's retry button does, and the
     * only state change the UI is allowed to make (ADR-0038 rule 4).
     */
    public function resetToPending(string $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE mail_outbox
                SET status = ?, attempts = 0, last_error = NULL,
                    next_attempt_at = NOW(), claim_token = NULL, claimed_at = NULL
              WHERE id = ? AND status = ?'
        );
        $stmt->execute([MailStatus::PENDING->value, $id, MailStatus::FAILED->value]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The oldest message still waiting — the stall signal #406 alarms on. Null
     * when the queue is empty, which is the healthy answer.
     */
    public function oldestPendingQueuedAt(): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT MIN(queued_at) FROM mail_outbox WHERE status = ?'
        );
        $stmt->execute([MailStatus::PENDING->value]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
