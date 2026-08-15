<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Repositories;

use App\Modules\Notifications\DTOs\MailRequestDto;
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
     * it is the idempotency guarantee itself. `UNIQUE (kind, subject_id,
     * dedup_key)` means a repeated enqueue cannot produce a second message, and
     * the database says so without a read-then-write that two concurrent
     * requests could both pass. It is what makes a retried finalize harmless,
     * and what will make an expiry warning fire once per tier rather than once
     * per request that notices the tier (#438).
     *
     * The no-op update is deliberately narrower than `INSERT IGNORE`, which
     * downgrades *every* error to a warning: a foreign key pointing at a member
     * who no longer exists would vanish silently instead of aborting the
     * settlement it belongs to.
     *
     * Note what the no-op does **not** do: it never rewrites the existing row.
     * The first `recipient` wins, because that is the address the club
     * committed to writing to, and a later enqueue does not get to move it.
     *
     * @return bool True when a row was inserted, false when one already existed.
     */
    public function enqueue(MailRequestDto $request): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO mail_outbox
                (id, kind, subject_id, dedup_key, member_id, admin_user_id, recipient, language, status, queued_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE id = id'
        );

        $stmt->execute([
            Uuid::v4(),
            $request->kind->value,
            $request->subjectId,
            $request->dedupKey,
            $request->memberId,
            $request->adminUserId,
            $request->recipient,
            $request->language->value,
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
    public function supersedePending(string $subjectId, MailKind $kind): int
    {
        $stmt = $this->db->prepare(
            'UPDATE mail_outbox
                SET status = ?, claim_token = NULL, claimed_at = NULL
              WHERE subject_id = ? AND kind = ? AND status = ?'
        );
        $stmt->execute([
            MailStatus::SUPERSEDED->value,
            $subjectId,
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
    public function findMemberIdsWithStatus(string $subjectId, MailKind $kind, MailStatus $status): array
    {
        $stmt = $this->db->prepare(
            'SELECT member_id FROM mail_outbox
              WHERE subject_id = ? AND kind = ? AND status = ? AND member_id IS NOT NULL'
        );
        $stmt->execute([$subjectId, $kind->value, $status->value]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    /**
     * Every queued message about one subject — the settlement detail's read
     * (#407), and the cancellation path's.
     *
     * @return list<array<string,mixed>>
     */
    public function findBySubjectId(string $subjectId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM mail_outbox WHERE subject_id = ? ORDER BY kind ASC, queued_at ASC'
        );
        $stmt->execute([$subjectId]);

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

    /**
     * Hand a claimed row back without touching its attempt count.
     *
     * What a drain does with the rows it claimed but ran out of time for. The
     * alternative — leaving them claimed — works, because the stale window
     * eventually frees them, but it delays every one of them by five minutes
     * for no reason: nothing was tried, so nothing needs backing off.
     *
     * Deliberately not `resetToPending()`: that clears `attempts` and
     * `last_error`, which would erase the history of a message that has already
     * failed twice.
     */
    public function releaseClaim(string $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mail_outbox
                SET claim_token = NULL, claimed_at = NULL
              WHERE id = ? AND status = ?'
        );
        $stmt->execute([$id, MailStatus::PENDING->value]);
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
     * How many attempts this message has already had.
     *
     * Read by the service so the *policy* — how many goes there are and how
     * long each waits — lives with the rest of the policy
     * ({@see \App\Modules\Notifications\Services\RetrySchedule}) rather than in
     * the write below. The ladder is now a function of the attempt number, so
     * whoever picks the backoff has to know the count first.
     */
    public function attemptsFor(string $id): int
    {
        $stmt = $this->db->prepare('SELECT attempts FROM mail_outbox WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? 0 : (int) $value;
    }

    /**
     * Record a failure with the decision already made.
     *
     * A transient failure with a go left goes back to `pending` with its
     * backoff — greylisting is the case this exists for, and it is ordinary
     * operation rather than an error. Anything else becomes `failed` with the
     * reason the Kassenwart reads.
     *
     * @param int  $attempts       The count including the failure being recorded
     * @param bool $retry          Whether another attempt is scheduled
     * @param int  $backoffSeconds How long until it, ignored when not retrying
     *
     * @return MailStatus The status the row now carries.
     */
    public function markFailed(string $id, string $error, int $attempts, bool $retry, int $backoffSeconds): MailStatus
    {
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
     * The oldest message still waiting.
     *
     * Kept for the diagnosis surface, where "how long has anything been in this
     * queue?" is a question a human asks. It is deliberately **not** what the
     * alarm keys on — see {@see oldestDueAt()}.
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

    /**
     * The earliest moment at which anything in the queue became due — the stall
     * signal (ADR-0039 decision 5).
     *
     * `next_attempt_at`, not `queued_at`, and that is the entire correction:
     * a message waiting out its backoff has a due time in the *future* and
     * cannot be overdue however old it is, while ADR-0038's age-based predicate
     * fired on exactly that row every time. Null when nothing is pending, which
     * is the healthy answer and the usual one.
     */
    public function oldestDueAt(): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT MIN(next_attempt_at) FROM mail_outbox WHERE status = ?'
        );
        $stmt->execute([MailStatus::PENDING->value]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * How many messages sit in each status — the self-check's counts, and the
     * body of the heartbeat ping.
     *
     * Every status is present in the result even at zero, so a caller never has
     * to tell "no failures" apart from "the key is missing".
     *
     * @return array<string,int>
     */
    public function countsByStatus(): array
    {
        $counts = [];
        foreach (MailStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        $stmt = $this->db->query('SELECT status, COUNT(*) AS total FROM mail_outbox GROUP BY status');
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }
}
