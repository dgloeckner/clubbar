<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Domain;

use App\Modules\Settlements\Enums\SettlementStatus;

/**
 * The `status` list filter, expressed as the same ladder {@see SettlementStatus::derive()}
 * walks in PHP (#377).
 *
 * A filter over a derived status cannot be a naive column test. `submitted`
 * does not mean "`submitted_at` is set" — a submitted run that the bank
 * returned in part derives as `partly_reversed`, and a
 * `WHERE submitted_at IS NOT NULL` pill would hand it back under "Submitted"
 * while the badge in that very row said "Partly reversed". Badge and pill
 * disagreeing about one settlement is exactly the silent drift ADR-0032 exists
 * to prevent, so every rung here excludes the rungs above it, in the same
 * order `derive()` evaluates them:
 *
 * | status            | predicate                                              |
 * |-------------------|--------------------------------------------------------|
 * | `cancelled`       | `is_cancelled`                                         |
 * | `fully_reversed`  | live, reversals exist, and they cover every member      |
 * | `partly_reversed` | live, reversals exist, but not all members             |
 * | `submitted`       | live, no reversals, `submitted_at`                     |
 * | `exported`        | live, no reversals, no `submitted_at`, `exported_at`   |
 * | `draft`           | live and none of the above                             |
 *
 * Two values are accepted that are not statuses:
 *
 * - `reversed` merges the two reversal rungs. It is the pill a treasurer
 *   actually asks for — "where did money come back?" — and does not yet care
 *   how much of it did. The badge still tells them apart.
 * - `active` is the pre-#377 vocabulary, kept as a deprecated alias for "not
 *   cancelled" so bookmarked URLs and the existing suite keep working. It is
 *   no longer offered as a pill.
 *
 * Anything else — `all`, an empty string, an unknown word — filters nothing,
 * which is the behaviour the endpoint had before this class existed.
 */
final class SettlementStatusFilter
{
    /** Both reversal rungs at once — the "money came back" pill. */
    public const REVERSED = 'reversed';

    /** Deprecated alias for "not cancelled", kept for bookmarked URLs. */
    public const ACTIVE = 'active';

    /**
     * The values the settlements page offers as pills, in pill order. They
     * partition the unfiltered list: every settlement matches exactly one.
     *
     * @var list<string>
     */
    public const PILLS = [
        SettlementStatus::DRAFT->value,
        SettlementStatus::EXPORTED->value,
        SettlementStatus::SUBMITTED->value,
        self::REVERSED,
        SettlementStatus::CANCELLED->value,
    ];

    /**
     * How many members of a settlement carry a reversal, and how many it
     * covers at all — the two counts the status ladder turns on. Correlated
     * subqueries rather than joins, for the reason
     * {@see \App\Modules\Settlements\Repositories\SettlementsRepository::STATUS_COUNTS}
     * gives: a join would multiply the two counts by each other.
     */
    private const REVERSED_COUNT =
        '(SELECT COUNT(*) FROM settlement_reversals sr WHERE sr.settlement_id = s.id)';
    private const SETTLED_COUNT =
        '(SELECT COUNT(DISTINCT si.member_id) FROM settlement_items si WHERE si.settlement_id = s.id)';

    /** A settlement that was never called off — every rung below `cancelled`. */
    private const LIVE = 's.is_cancelled = 0';

    /**
     * The WHERE fragment for one filter value, or null when it filters nothing.
     *
     * The fragment carries no bound parameters: every value in it is a literal
     * of this class's own making, never anything the caller supplied.
     */
    public static function sql(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        if ($status === self::ACTIVE) {
            return self::LIVE;
        }

        if ($status === self::REVERSED) {
            return self::LIVE . ' AND ' . self::REVERSED_COUNT . ' > 0';
        }

        return match (SettlementStatus::tryFrom($status)) {
            SettlementStatus::CANCELLED => 's.is_cancelled = 1',
            // "Every member reversed", with the same guard `derive()` uses: a
            // settlement covering no members cannot be *fully* anything, so a
            // reversal against it falls to `partly_reversed` below.
            SettlementStatus::FULLY_REVERSED => self::LIVE
                . ' AND ' . self::REVERSED_COUNT . ' > 0'
                . ' AND ' . self::SETTLED_COUNT . ' > 0'
                . ' AND ' . self::REVERSED_COUNT . ' >= ' . self::SETTLED_COUNT,
            SettlementStatus::PARTLY_REVERSED => self::LIVE
                . ' AND ' . self::REVERSED_COUNT . ' > 0'
                . ' AND NOT (' . self::SETTLED_COUNT . ' > 0'
                . ' AND ' . self::REVERSED_COUNT . ' >= ' . self::SETTLED_COUNT . ')',
            SettlementStatus::SUBMITTED => self::LIVE
                . ' AND ' . self::REVERSED_COUNT . ' = 0'
                . ' AND s.submitted_at IS NOT NULL',
            SettlementStatus::EXPORTED => self::LIVE
                . ' AND ' . self::REVERSED_COUNT . ' = 0'
                . ' AND s.submitted_at IS NULL'
                . ' AND s.exported_at IS NOT NULL',
            SettlementStatus::DRAFT => self::LIVE
                . ' AND ' . self::REVERSED_COUNT . ' = 0'
                . ' AND s.submitted_at IS NULL'
                . ' AND s.exported_at IS NULL',
            null => null,
        };
    }
}
