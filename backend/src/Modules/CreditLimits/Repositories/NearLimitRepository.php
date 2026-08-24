<?php

declare(strict_types=1);

namespace App\Modules\CreditLimits\Repositories;

use App\Shared\Repository\UnsettledTransactions;
use PDO;

/**
 * Who is up against their ceiling — asked once, answered in one place.
 *
 * This query used to live in `DashboardRepository`, because the dashboard's
 * near-limit panel (#385) was the only thing that asked. It moved here when the
 * digest became the second asker, and the move is the point rather than
 * tidiness: **the panel and the mail must never disagree about who is near
 * their limit.** Two copies of a `HAVING` clause with a `DIV` in it is exactly
 * the shape of a disagreement nobody notices until a member is named in an
 * email the dashboard does not list.
 *
 * It sits in `CreditLimits` rather than in either consumer because that is
 * whose question it is: ADR-0047 rule 1 puts the override-or-default rule in
 * one place on this side of the wire, and this is that rule expressed as SQL.
 *
 * Rows, not meaning (Pattern 005). What a row *is* — the resolved ceiling, the
 * percentage, the status — is {@see \App\Modules\CreditLimits\Domain\CreditLimit}'s
 * to say, and both consumers ask it rather than recomputing.
 */
class NearLimitRepository
{
    /**
     * Whose tab the credit-limit list is about, and what the tab is (#385),
     * measured against each member's *own* ceiling (ADR-0047).
     *
     * One tab per active, undeleted member, summed over their unsettled
     * transactions. Shared by the list and its count because the two must
     * describe the same set of members: a count taken over a wider set would
     * promise names the list can never show.
     *
     * The threshold used to be one number the service worked out and passed in.
     * With per-member ceilings it is one per row, so the resolution moves into
     * the query: `COALESCE(m.credit_limit_cents, :default_limit)` is the same
     * rule `CreditLimitPolicy::forMember()` expresses in PHP, and
     * `m.credit_limit_cents` is functionally dependent on the `GROUP BY m.id`,
     * so selecting it and testing it in `HAVING` is legal under
     * `ONLY_FULL_GROUP_BY`.
     *
     * **`DIV`, not `/`.** Integer division, matching `intdiv()` in
     * `CreditLimit::warnAtCents()`. A decimal would move the boundary cent —
     * 70 % of 3,333 is 2,333.1 — and name a member the terminal has not warned
     * yet, which is the one figure this list must never produce.
     *
     * A ceiling of `0` is dropped: that member is unlimited, so no tab is ever
     * near it and there is no denominator to rank them by. This is the same
     * exclusion the terminal applies, written as SQL.
     *
     * Deactivated and deleted members are left out on purpose: the question the
     * list answers is who the terminal is about to stop serving, and it serves
     * neither. What they still owe is not lost — it stays in the outstanding
     * balance and in the next settlement run.
     */
    private const NEAR_LIMIT_ROWS =
        // CONCAT_WS, not CONCAT: either name column may be NULL, and CONCAT
        // would turn the whole label into NULL rather than the half we have.
        "SELECT m.id, CONCAT_WS(' ', m.first_name, m.last_name) as name,
                COALESCE(SUM(t.amount_cents), 0) as balance_cents,
                COALESCE(m.credit_limit_cents, :default_limit) as limit_cents
         FROM members m
         JOIN transactions t ON t.member_id = m.id AND " . UnsettledTransactions::UNSETTLED . '
        WHERE m.deleted_at IS NULL AND m.is_active = 1
        GROUP BY m.id
        HAVING limit_cents > 0 AND balance_cents >= limit_cents * :warn_percent DIV 100';

    public function __construct(private PDO $db) {}

    /**
     * Members in the warning band or past it, fullest Deckel first.
     *
     * Ordered by share of the ceiling rather than by amount, because with
     * per-member ceilings the biggest tab is not the most urgent one: a member
     * €10 from a €50 override is closer to being refused than one €200 into a
     * €1,000 ceiling. Ties break on the amount and then on the id, so the order
     * is total and two calls cannot interleave the same rows differently.
     *
     * @return list<array<string, mixed>>
     */
    public function findNearLimit(int $defaultLimitCents, int $warnThresholdPercent, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM (' . self::NEAR_LIMIT_ROWS . ') near_limit
             ORDER BY near_limit.balance_cents * 100.0 / near_limit.limit_cents DESC,
                      near_limit.balance_cents DESC,
                      near_limit.id ASC
             LIMIT :limit'
        );
        $stmt->bindValue('default_limit', $defaultLimitCents, PDO::PARAM_INT);
        $stmt->bindValue('warn_percent', $warnThresholdPercent, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * How many members `findNearLimit()` would return without the cap — what a
     * capped list needs to admit that it is showing only the top of a longer
     * one.
     */
    public function countNearLimit(int $defaultLimitCents, int $warnThresholdPercent): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM (' . self::NEAR_LIMIT_ROWS . ') near_limit');
        $stmt->bindValue('default_limit', $defaultLimitCents, PDO::PARAM_INT);
        $stmt->bindValue('warn_percent', $warnThresholdPercent, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
