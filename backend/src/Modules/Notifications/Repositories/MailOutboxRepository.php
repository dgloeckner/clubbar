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
     * Sort keys the queue page may order by, mapped to their columns.
     *
     * A whitelist because the value reaches an `ORDER BY`, where a bound
     * parameter cannot go. `status` is here because "show me what failed" is
     * the question this page exists to answer, and sorting is the cheapest way
     * to ask it when the filter is already spent on a kind.
     *
     * @var array<string,string>
     */
    public const SORTABLE = [
        'queued_at' => 'o.queued_at',
        'sent_at' => 'o.sent_at',
        'status' => 'o.status',
        'kind' => 'o.kind',
        'recipient' => 'o.recipient',
    ];

    /**
     * The queue as an admin browses it (#407).
     *
     * Joined rather than resolved per row: a page of fifty would otherwise be
     * fifty-one queries, and the name is the column somebody actually scans.
     * `LEFT JOIN` throughout — a row addressed to an admin has no member, a row
     * addressed to a member has no admin, and an anonymised member has neither
     * name nor address left while the row must still render.
     *
     * `o.id` is the tiebreaker on every sort. `queued_at` has second
     * granularity and a settlement queues its whole batch inside one, so an
     * order without it lets a page boundary repeat or skip a row between two
     * requests — the classic pagination bug that only shows up on page two.
     *
     * @param array<string,mixed> $filters `kind`, `status`, `subject_id`, `search`
     * @return list<array<string,mixed>>
     */
    public function search(array $filters, int $limit, int $offset, string $sortKey, string $sortOrder): array
    {
        [$where, $params] = self::conditions($filters);
        $column = self::SORTABLE[$sortKey] ?? self::SORTABLE['queued_at'];
        $direction = strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC';

        $stmt = $this->db->prepare(
            'SELECT o.*,
                    m.first_name AS member_first_name,
                    m.last_name  AS member_last_name,
                    a.display_name AS admin_display_name
               FROM mail_outbox o
               LEFT JOIN members m ON m.id = o.member_id
               LEFT JOIN admin_users a ON a.id = o.admin_user_id
              WHERE ' . $where . '
              ORDER BY ' . $column . ' ' . $direction . ', o.id ASC
              LIMIT ? OFFSET ?'
        );

        $position = 1;
        foreach ($params as $value) {
            $stmt->bindValue($position++, $value);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $filters As {@see search()}. */
    public function countMatching(array $filters): int
    {
        [$where, $params] = self::conditions($filters);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
               FROM mail_outbox o
               LEFT JOIN members m ON m.id = o.member_id
              WHERE ' . $where
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The `WHERE` both of the two queries above share.
     *
     * Kept in one place because a count that filters differently from the page
     * it counts produces a pagination footer that lies — and it lies quietly,
     * on page two.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<string>}
     */
    private static function conditions(array $filters): array
    {
        $clauses = ['1=1'];
        $params = [];

        foreach (['kind' => 'o.kind', 'status' => 'o.status', 'subject_id' => 'o.subject_id'] as $key => $column) {
            $value = $filters[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $clauses[] = $column . ' = ?';
                $params[] = $value;
            }
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            // The address and the name, because those are the two things
            // somebody arrives holding: "did Erika get hers?" and "what went to
            // this address?".
            $clauses[] = '(o.recipient LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ?)';
            $term = '%' . trim($search) . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        return [implode(' AND ', $clauses), $params];
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

    /**
     * @return string|null The `sent_at` that was written, or null if the row is
     *         gone. The caller copies it onto the durable settlement-side record
     *         (#408), and reading it back rather than stamping a PHP timestamp
     *         is what keeps the two from disagreeing: `NOW()` is the database's
     *         clock, and on a shared host it is not always the web server's.
     */
    public function markSent(string $id, ?string $messageId): ?string
    {
        $stmt = $this->db->prepare(
            'UPDATE mail_outbox
                SET status = ?, sent_at = NOW(), message_id = ?, last_error = NULL,
                    attempts = attempts + 1, claim_token = NULL, claimed_at = NULL
              WHERE id = ?'
        );
        $stmt->execute([MailStatus::SENT->value, $messageId, $id]);

        $read = $this->db->prepare('SELECT sent_at FROM mail_outbox WHERE id = ?');
        $read->execute([$id]);
        $sentAt = $read->fetchColumn();

        return $sentAt === false || $sentAt === null ? null : (string) $sentAt;
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

    /* ─────────────────────── Erasure and retention (#408) ─────────────────────── */

    /**
     * Take a member's address out of every row that holds it (ADR-0029).
     *
     * Keyed on `member_id`, and that is the whole substance of the method. The
     * shortcut is to key on `subject_id` — which happens to be the member for
     * some kinds and is the *settlement* for a Vorabankündigung, so an erasure
     * written that way would clear the rows nobody was worried about and leave
     * every announcement address in place. The column is the one the foreign
     * key is on for exactly this reason.
     *
     * The address is replaced with the empty string rather than the row being
     * deleted: `recipient` is `NOT NULL`, and more to the point the row is the
     * record that a message was queued for this member. Erasure removes the
     * contact data, not the fact that the club wrote to somebody — the same
     * split anonymisation already makes on `members` itself, where the row
     * survives with its name nulled.
     *
     * @return int Rows whose address was cleared.
     */
    public function eraseMemberRecipients(string $memberId): int
    {
        $stmt = $this->db->prepare(
            "UPDATE mail_outbox SET recipient = '' WHERE member_id = ? AND recipient <> ''"
        );
        $stmt->execute([$memberId]);

        return $stmt->rowCount();
    }

    /**
     * Close out anything still queued for a member being erased.
     *
     * Without this the erasure would leave a `pending` row addressed to an empty
     * string, which the drain would then claim, fail to send, and report as a
     * delivery failure against a member who no longer exists — a fabricated
     * problem, on a queue whose failures are supposed to mean something.
     *
     * `superseded` rather than `failed` because nothing went wrong: the message
     * was withdrawn, which is what that status already means everywhere else in
     * this table (a cancellation retiring an announcement that never left).
     *
     * @return int Rows superseded.
     */
    public function supersedePendingForMember(string $memberId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE mail_outbox
                SET status = ?, claim_token = NULL, claimed_at = NULL
              WHERE member_id = ? AND status = ?'
        );
        $stmt->execute([MailStatus::SUPERSEDED->value, $memberId, MailStatus::PENDING->value]);

        return $stmt->rowCount();
    }

    /**
     * Delete delivered rows of one kind older than a cutoff (#408).
     *
     * Three predicates, and each of them is load-bearing:
     *
     * - `status = 'sent'` — pruning is not a way to lose a message that never
     *   went out. A `failed` row is the record that somebody was not reached and
     *   is kept at any age; so are `pending` and `superseded`.
     * - `sent_at < cutoff` — the cutoff comes from
     *   {@see \App\Modules\Notifications\Services\MailRetention}, per kind, so
     *   the policy is in one place and this method holds none of it.
     * - `LIMIT` — the run this is called from has a wall-clock budget, and the
     *   first prune after an upgrade can face years of queue.
     *
     * @return int Rows deleted.
     */
    public function pruneSent(MailKind $kind, string $sentBefore, int $limit): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM mail_outbox
              WHERE kind = ? AND status = ? AND sent_at IS NOT NULL AND sent_at < ?
              LIMIT ?'
        );
        $stmt->bindValue(1, $kind->value);
        $stmt->bindValue(2, MailStatus::SENT->value);
        $stmt->bindValue(3, $sentBefore);
        $stmt->bindValue(4, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }
}
