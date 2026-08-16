<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Repositories;

use App\Shared\Repository\UnsettledTransactions;
use DateTimeImmutable;
use PDO;

/**
 * What a member's Deckel consisted of at a period boundary (ADR-0039).
 *
 * Two reads, deliberately, rather than one query that does everything:
 *
 * 1. {@see linesAsOf()} — the member's own transactions that were unsettled at
 *    the boundary, with the product each one names.
 * 2. {@see originals()} — the handful of *already settled* transactions that an
 *    orphaned Storno points back at, so that line can name what it reverses.
 *
 * The second exists because of the one case ADR-0039 decision 4 calls out: a
 * Storno whose original was collected in an earlier settlement has nothing left
 * to net against and stands alone as a negative line. Its original is by
 * definition **not** in the first result set — that is what makes it orphaned —
 * so naming it needs a second look, and folding that into the first query would
 * mean stating the dated predicate twice in one statement, with its four bound
 * values interleaved. Written as two reads it is legible, and the second one
 * usually does not run at all.
 *
 * Nothing here nets, caps or totals. That is
 * {@see \App\Modules\Notifications\Services\DeckelStatementService}'s job, and
 * it happens once over one set so that lines and total cannot disagree.
 */
class DeckelStatementRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    /**
     * Every transaction of this member that was unsettled at `$boundary`.
     *
     * Chronological, and by id within the same instant so two runs over the
     * same data produce the same statement — a terminal syncing a round of
     * drinks writes several rows with one `occurred_at`.
     *
     * @return list<array<string,mixed>>
     */
    public function linesAsOf(string $memberId, DateTimeImmutable $boundary): array
    {
        [$predicate, $params] = UnsettledTransactions::unsettledAsOf($boundary);

        $stmt = $this->db->prepare(
            'SELECT t.id, t.amount_cents, t.occurred_at, t.transaction_type, t.notes,
                    t.related_transaction_id, p.names AS product_names
               FROM transactions t
               LEFT JOIN products p ON p.id = t.product_id
              WHERE t.member_id = ? AND ' . $predicate . '
              ORDER BY t.occurred_at ASC, t.id ASC'
        );
        $stmt->execute([$memberId, ...$params]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The transactions an orphaned Storno reverses, keyed by id.
     *
     * `settlement_date` is the settlement that held the original **at the
     * boundary** — the same window {@see UnsettledTransactions::unsettledAsOf()}
     * uses, so the mail cannot name a settlement that had not been created yet
     * or one that was already cancelled. It is what turns „Storno" into
     * „Storno Bier (aus Abrechnung 07/2026)", which is the difference between a
     * negative line a member can place and one they have to ask about.
     *
     * `MAX()` collapses the history rather than picking from it: #142 keeps a
     * row for every settlement that ever claimed a transaction, and the filter
     * above already leaves at most one of them live.
     *
     * @param list<string> $ids
     * @return array<string, array{product_names: ?string, notes: ?string, settlement_date: ?string}>
     */
    public function originals(array $ids, DateTimeImmutable $boundary): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id): bool => is_string($id) && $id !== '')));
        if ($ids === []) {
            return [];
        }

        $at = $boundary->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->db->prepare(
            'SELECT t.id, t.notes, p.names AS product_names,
                    (SELECT MAX(s.settlement_date)
                       FROM settlement_items si
                       JOIN settlements s ON s.id = si.settlement_id
                      WHERE si.transaction_id = t.id
                        AND s.created_at <= ?
                        AND (s.cancelled_at > ? OR (s.cancelled_at IS NULL AND s.is_cancelled = 0))
                    ) AS settlement_date
               FROM transactions t
               LEFT JOIN products p ON p.id = t.product_id
              WHERE t.id IN (' . $placeholders . ')'
        );
        $stmt->execute([$at, $at, ...$ids]);

        $originals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $originals[(string) $row['id']] = [
                'product_names' => $row['product_names'] ?? null,
                'notes' => $row['notes'] ?? null,
                'settlement_date' => $row['settlement_date'] ?? null,
            ];
        }

        return $originals;
    }
}
