<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Repositories;

use App\Modules\Notifications\Enums\MailKind;
use App\Shared\Logging\Logger;
use PDO;

/**
 * The half of an announcement that outlives the queue (#408, ADR-0029).
 *
 * An outbox row carries two facts with two different lifetimes: *that* a member
 * was announced to, which is retention-tier evidence of the club keeping its
 * Nutzungsordnung § 7 Abs. 3 promise, and the *address* it went to, which is
 * operational-tier contact data that erasure clears and pruning removes. This
 * table holds the first one, and nothing else — see the migration header for
 * why it is a table rather than a column on `settlement_items`.
 *
 * It lives in the Settlements module because the settlement is what it is
 * evidence about, and the settlement detail is what reads it. Notifications
 * writes to it, which is the same shape as Notifications reading
 * `MembersRepository` to resolve a recipient.
 */
class SettlementAnnouncementsRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    /**
     * Record that this member's message of this kind reached the transport.
     *
     * Idempotent by the same reasoning as `MailOutboxRepository::enqueue()`, and
     * for the same reason: a message can be marked sent more than once — a
     * retry of a row whose send actually succeeded, a replayed drain — and the
     * *first* timestamp is the one that is true. `ON DUPLICATE KEY UPDATE
     * sent_at = sent_at` is a deliberate no-op rather than `INSERT IGNORE`,
     * which would also swallow a foreign key pointing at a settlement that is
     * not there.
     *
     * Called from inside the drain's per-message step rather than in a batch at
     * the end: a run killed by the host mid-batch must not lose the record for
     * messages it already sent.
     *
     * @param string $sentAt `Y-m-d H:i:s`, copied from the outbox row so the two
     *                       cannot disagree while both exist.
     */
    public function record(string $settlementId, string $memberId, MailKind $kind, string $sentAt): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO settlement_announcements (settlement_id, member_id, kind, sent_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sent_at = sent_at'
        );
        $stmt->execute([$settlementId, $memberId, $kind->value, $sentAt]);
    }

    /**
     * Every announcement recorded for one settlement.
     *
     * @return list<array{member_id: string, kind: string, sent_at: string}>
     */
    public function findBySettlementId(string $settlementId): array
    {
        $stmt = $this->db->prepare(
            'SELECT member_id, kind, sent_at
               FROM settlement_announcements
              WHERE settlement_id = ?
              ORDER BY kind ASC, sent_at ASC'
        );
        $stmt->execute([$settlementId]);

        return array_map(
            static fn(array $row): array => [
                'member_id' => (string) $row['member_id'],
                'kind' => (string) $row['kind'],
                'sent_at' => (string) $row['sent_at'],
            ],
            $stmt->fetchAll(),
        );
    }
}
