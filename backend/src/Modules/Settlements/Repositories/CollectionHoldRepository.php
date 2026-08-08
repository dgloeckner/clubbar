<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Repositories;

use App\Shared\Logging\Logger;
use App\Shared\Repository\UnsettledTransactions;
use PDO;

/**
 * The collection hold — "do not debit this member until someone has looked at
 * it" (schema 007, ruling #148 §3).
 *
 * The columns live on `members` because the hold is a property of the member,
 * but nothing about them belongs to member administration: they are written by
 * a bank return and read by the settlement preview, so they are owned here
 * rather than in `MembersRepository`. The two touch strictly disjoint columns.
 *
 * Hold and clear are both idempotent overwrites of the same six columns, which
 * is what keeps the record honest: `held_at`/`held_by_admin_id` always describe
 * the current hold, `cleared_at`/`cleared_by_admin_id` the most recent release.
 * The audit log carries the history.
 */
class CollectionHoldRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    /**
     * Stop the next run collecting from this member.
     *
     * Called inside the reversal's DB transaction — the hold and the reversal
     * that caused it commit together or not at all (ruling #148 §8).
     */
    public function place(string $memberId, string $reason, string $adminUserId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE members
                SET collection_hold = 1,
                    collection_hold_reason = ?,
                    held_at = CURRENT_TIMESTAMP,
                    held_by_admin_id = ?,
                    cleared_at = NULL,
                    cleared_by_admin_id = NULL
              WHERE id = ?'
        );
        $stmt->execute([mb_substr($reason, 0, 500), $adminUserId, $memberId]);

        $this->logger->info('Member placed on collection hold', ['member_id' => $memberId]);
    }

    /**
     * Let the next run collect from this member again (ruling #148 §5).
     *
     * `collection_hold_reason` is kept: the reason the hold existed is still
     * the answer to "why was this member skipped in March?", and `cleared_at`
     * already says the hold is over.
     */
    public function clear(string $memberId, string $adminUserId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE members
                SET collection_hold = 0,
                    cleared_at = CURRENT_TIMESTAMP,
                    cleared_by_admin_id = ?
              WHERE id = ?'
        );
        $stmt->execute([$adminUserId, $memberId]);

        $this->logger->info('Collection hold cleared', ['member_id' => $memberId]);
    }

    /**
     * Every member currently on hold, with what they still owe.
     *
     * Soft-deleted members are excluded — a hold on a record that no longer
     * exists is not something anyone can act on.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllHeld(): array
    {
        $stmt = $this->db->query(
            'SELECT m.id AS member_id, m.first_name, m.last_name, m.collection_hold_reason,
                    m.held_at, m.held_by_admin_id, a.display_name AS admin_display_name,
                    COALESCE((SELECT SUM(t.amount_cents) FROM transactions t
                               WHERE t.member_id = m.id AND ' . UnsettledTransactions::UNSETTLED . '), 0) AS balance_cents
               FROM members m
               LEFT JOIN admin_users a ON a.id = m.held_by_admin_id
              WHERE m.collection_hold = 1 AND m.deleted_at IS NULL
              ORDER BY m.held_at DESC'
        );

        return $stmt->fetchAll();
    }
}
