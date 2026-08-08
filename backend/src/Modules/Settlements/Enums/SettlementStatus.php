<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Enums;

/**
 * Where a settlement stands, derived — never stored (ruling #148 §6).
 *
 * There is no `status` column and there must not be one. Every fact this
 * status reports already exists somewhere authoritative: `is_cancelled`,
 * `submitted_at`, `exported_at`, and the `settlement_reversals` rows. A stored
 * status would be a fifth copy that drifts from the four, and the drift would
 * be invisible — a settlement reading "submitted" while every member of it has
 * been reversed is exactly the kind of lie that costs money.
 *
 * The ladder is evaluated top down, most-final first:
 *
 * | status            | when                                              |
 * |-------------------|---------------------------------------------------|
 * | `cancelled`       | `is_cancelled` — the run never moved money        |
 * | `fully_reversed`  | every settled member has a reversal row           |
 * | `partly_reversed` | at least one, but not all, have one               |
 * | `submitted`       | `submitted_at` — the file reached the bank        |
 * | `exported`        | `exported_at` — a file was generated              |
 * | `draft`           | none of the above                                 |
 */
enum SettlementStatus: string
{
    case CANCELLED = 'cancelled';
    case FULLY_REVERSED = 'fully_reversed';
    case PARTLY_REVERSED = 'partly_reversed';
    case SUBMITTED = 'submitted';
    case EXPORTED = 'exported';
    case DRAFT = 'draft';

    public function label(): string
    {
        return match ($this) {
            self::CANCELLED => 'Cancelled',
            self::FULLY_REVERSED => 'Fully reversed',
            self::PARTLY_REVERSED => 'Partly reversed',
            self::SUBMITTED => 'Submitted to the bank',
            self::EXPORTED => 'Exported',
            self::DRAFT => 'Draft',
        };
    }

    /**
     * @param array<string, mixed> $settlement A `settlements` row.
     * @param int $reversedMemberCount How many members of this settlement have a reversal row.
     * @param int $settledMemberCount How many distinct members the settlement covers.
     */
    public static function derive(array $settlement, int $reversedMemberCount, int $settledMemberCount): self
    {
        if (!empty($settlement['is_cancelled'])) {
            return self::CANCELLED;
        }

        if ($reversedMemberCount > 0) {
            // A settlement with no items left to count members from cannot be
            // "fully" anything; treat a reversal against it as partial rather
            // than inventing completeness out of a zero.
            return $settledMemberCount > 0 && $reversedMemberCount >= $settledMemberCount
                ? self::FULLY_REVERSED
                : self::PARTLY_REVERSED;
        }

        if (!empty($settlement['submitted_at'])) {
            return self::SUBMITTED;
        }

        if (!empty($settlement['exported_at'])) {
            return self::EXPORTED;
        }

        return self::DRAFT;
    }
}
