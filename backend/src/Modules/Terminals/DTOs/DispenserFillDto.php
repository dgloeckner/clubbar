<?php

declare(strict_types=1);

namespace App\Modules\Terminals\DTOs;

/**
 * How full the hopper probably is (#955).
 *
 * **Arithmetic, not a sensor.** The dispenser's *empty* switch is a factory
 * option this unit does not have, so nothing on the machine can say it is
 * running out (owner decision 7 of #944 removed `hopper_low` from the protocol
 * rather than publish a value that always reads "fine"). What the backend does
 * have is both halves of a subtraction: a counted refill, and every token sold
 * since — one `purchase` row per token, carrying the terminal that sold it.
 *
 * Four properties of this object are load-bearing.
 *
 * 1. **Tokens are counted as rows, never summed from `dispenser_actual`.** A
 *    dispense of five tokens writes five rows, each carrying that operation's
 *    `dispenser_requested`/`dispenser_actual` totals — summing the column would
 *    multiply the operation by its own size (25 for those five).
 * 2. **An absent count is not a zero.** `soldSince` is null where nobody
 *    counted, and then `estimatedLeft` is null too. A reading assembled from
 *    `?? 0` would print a confident "0 sold, full hopper" for a figure nobody
 *    looked up.
 * 3. **The estimate never goes negative.** Below zero it is *exhausted*, and
 *    the number would be false precision about a drift nobody measured.
 * 4. **The threshold is always present**, even with no refill recorded: it is a
 *    stored setting with a NOT NULL default, and it is what the panel offers
 *    for editing. The estimate around it is what is missing, not the setting.
 */
final readonly class DispenserFillDto
{
    /** Matches the column default in migration 071 — roughly one busy evening's tail. */
    public const DEFAULT_LOW_THRESHOLD = 20;

    public function __construct(
        /** When the hopper was last counted into (UTC). Null = never recorded. */
        public ?string $refilledAt,
        /** What was counted in then. Null with {@see $refilledAt}. */
        public ?int $refillTokens,
        /**
         * Tokens this terminal has sold since that moment, by `occurred_at` —
         * when the bar sold it, not when the row reached this server. A sale
         * made before a refill and uploaded after it belongs to the old load.
         *
         * Null means *not counted on this code path*, which is not zero.
         */
        public ?int $soldSince,
        /** Warn at or below this. Always set; the column is NOT NULL. */
        public int $lowThreshold,
    ) {}

    /**
     * Read the three stored columns plus a count of the sales since.
     *
     * @param array<string, mixed> $row      a `terminals` row
     * @param int|null             $soldSince null where the caller did not count
     */
    public static function fromRow(array $row, ?int $soldSince): self
    {
        $refilledAt = $row['dispenser_refilled_at'] ?? null;
        $refillTokens = $row['dispenser_refill_tokens'] ?? null;

        return new self(
            refilledAt: is_string($refilledAt) && $refilledAt !== '' ? $refilledAt : null,
            refillTokens: $refillTokens === null ? null : (int) $refillTokens,
            // Counting sales "since never" is not zero sales, it is no anchor
            // at all — so the count is dropped with the refill it belonged to.
            soldSince: $refilledAt === null ? null : $soldSince,
            lowThreshold: isset($row['dispenser_low_threshold'])
                ? (int) $row['dispenser_low_threshold']
                : self::DEFAULT_LOW_THRESHOLD,
        );
    }

    /**
     * Tokens probably left, floored at zero — or null when there is nothing to
     * estimate from (no refill recorded, or nobody counted the sales).
     */
    public function estimatedLeft(): ?int
    {
        if ($this->refillTokens === null || $this->soldSince === null) {
            return null;
        }

        return max(0, $this->refillTokens - $this->soldSince);
    }

    /** At or below zero: the load this estimate describes is used up. */
    public function isExhausted(): bool
    {
        $left = $this->estimatedLeft();

        return $left !== null && $left <= 0;
    }

    /**
     * At or below the warning threshold — *including* exhausted, which is the
     * low state at its end rather than a separate condition.
     */
    public function isLow(): bool
    {
        $left = $this->estimatedLeft();

        return $left !== null && $left <= $this->lowThreshold;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'refilled_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->refilledAt),
            'refill_tokens' => $this->refillTokens,
            'sold_since' => $this->soldSince,
            // Null is "no estimate", and the panel must render it as such. It
            // is never rounded up into a zero, which would read as an empty
            // hopper rather than as an unanswered question.
            'estimated_left' => $this->estimatedLeft(),
            'low_threshold' => $this->lowThreshold,
        ];
    }
}
