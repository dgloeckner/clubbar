<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Domain;

use App\Modules\Settlements\Enums\SettlementMethod;
use App\Shared\Exceptions\BusinessRuleReason;

/**
 * May this settlement still be cancelled, and if not, why not?
 *
 * One question, one answer, asked of a raw `settlements` row so that both the
 * service (which throws) and the DTO (which reports `is_cancellable` to the
 * admin UI) read the same rule rather than each carrying half of it.
 *
 * **A settlement can be cancelled while no money has moved** — ruling #142 as
 * generalised by #163. Per method:
 *
 * | method          | cancellable                                  | because |
 * |-----------------|----------------------------------------------|---------|
 * | `direct_debit`  | until submitted, execution date as a backstop | the money moves when the file reaches the bank |
 * | `bank_transfer` | never                                        | the money already arrived |
 * | `write_off`     | always                                       | no money ever moves |
 *
 * Note what is deliberately *not* a blocker: `exported_at`. Generating the
 * pain.008 file is not sending it, and locking there would strand the ordinary
 * case of downloading a file, spotting a wrong amount and never submitting it —
 * leaving the treasurer to reverse money that never moved (#81, #142 §1).
 */
final class CancellationGate
{
    /**
     * The reason this settlement cannot be cancelled, or null when it can.
     *
     * Stringable, so a caller that only wants the English sentence still gets
     * it from `(string) CancellationGate::blocker($row)`.
     *
     * @param array<string, mixed> $settlement A `settlements` row.
     * @param string|null $today Injectable for tests; defaults to the current date.
     */
    public static function blocker(array $settlement, ?string $today = null): ?BlockedReason
    {
        if (!empty($settlement['is_cancelled'])) {
            return new BlockedReason(
                BusinessRuleReason::SETTLEMENT_ALREADY_CANCELLED,
                'This settlement has already been cancelled.',
            );
        }

        // Rows written before #163 carry no method; they were all direct debits.
        $method = SettlementMethod::tryFrom((string) ($settlement['method'] ?? ''))
            ?? SettlementMethod::DIRECT_DEBIT;

        if ($method === SettlementMethod::WRITE_OFF) {
            return null;
        }

        if ($method === SettlementMethod::BANK_TRANSFER) {
            return new BlockedReason(
                BusinessRuleReason::SETTLEMENT_BANK_TRANSFER_NOT_CANCELLABLE,
                'This settlement records money the member has already transferred, so cancelling it '
                . 'would not un-move anything. Reverse it instead.',
            );
        }

        if (!empty($settlement['submitted_at'])) {
            $submittedOn = self::asDate($settlement['submitted_at']);

            return new BlockedReason(
                BusinessRuleReason::SETTLEMENT_SUBMITTED_NOT_CANCELLABLE,
                'This settlement was submitted to the bank on ' . $submittedOn
                . ', so the money has moved. Reverse it instead.',
                ['submitted_on' => $submittedOn],
            );
        }

        $executionDate = self::asDate($settlement['execution_date'] ?? null);
        if ($executionDate !== null && $executionDate < ($today ?? date('Y-m-d'))) {
            return new BlockedReason(
                BusinessRuleReason::SETTLEMENT_EXECUTION_DATE_PASSED,
                'This settlement\'s execution date (' . $executionDate . ') has passed, so the bank has '
                . 'almost certainly collected it. Reverse it instead.',
                ['execution_date' => $executionDate],
            );
        }

        return null;
    }

    /** @param array<string, mixed> $settlement A `settlements` row. */
    public static function isCancellable(array $settlement, ?string $today = null): bool
    {
        return self::blocker($settlement, $today) === null;
    }

    /** Both DATE and DATETIME columns reach here; only the day matters. */
    private static function asDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }
}
