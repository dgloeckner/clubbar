<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Domain;

use App\Shared\Exceptions\BusinessRuleReason;

/**
 * May this settlement be reversed, and if not, why not? (ruling #148 §7)
 *
 * The exact mirror of {@see CancellationGate}, and deliberately expressed *as*
 * that mirror rather than as a second rule set that happens to agree with it:
 *
 * - **Cancellation** undoes a run whose money never moved.
 * - **Reversal** records that money which *did* move has come back.
 *
 * So a settlement is one or the other, never both, and the two gates cannot
 * drift apart because there is only one rule — the second gate asks the first.
 * A cancelled settlement is neither: nothing was ever collected, so there is
 * nothing for a bank to return.
 *
 * Concretely this makes reversal available exactly where #81 sends the
 * treasurer: a direct debit that was marked submitted, or whose execution date
 * has passed, and a `bank_transfer` whose money already arrived. A `write_off`
 * never moved a cent, so it is cancelled, never reversed.
 */
final class ReversalGate
{
    /**
     * The reason this settlement cannot be reversed, or null when it can.
     *
     * Stringable, so a caller that only wants the English sentence still gets
     * it from `(string) ReversalGate::blocker($row)`.
     *
     * @param array<string, mixed> $settlement A `settlements` row.
     * @param string|null $today Injectable for tests; defaults to the current date.
     */
    public static function blocker(array $settlement, ?string $today = null): ?BlockedReason
    {
        if (!empty($settlement['is_cancelled'])) {
            return new BlockedReason(
                BusinessRuleReason::SETTLEMENT_CANCELLED_NOT_REVERSIBLE,
                'This settlement was cancelled, so it never collected anything. There is nothing to reverse.',
            );
        }

        if (CancellationGate::isCancellable($settlement, $today)) {
            return new BlockedReason(
                BusinessRuleReason::SETTLEMENT_NOT_YET_COLLECTED,
                'This settlement has not moved any money yet, so there is nothing for the bank to return. '
                . 'Cancel it instead.',
            );
        }

        return null;
    }

    /** @param array<string, mixed> $settlement A `settlements` row. */
    public static function isReversible(array $settlement, ?string $today = null): bool
    {
        return self::blocker($settlement, $today) === null;
    }
}
