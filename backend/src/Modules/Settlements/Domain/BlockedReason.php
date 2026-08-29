<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Domain;

use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;

/**
 * Why a gate says no — in both the forms the answer is needed in.
 *
 * {@see CancellationGate} and {@see ReversalGate} are each asked twice: by the
 * service, which throws, and by {@see \App\Modules\Settlements\DTOs\SettlementDto},
 * which shows the admin why the button is disabled. Before #757 both received
 * an English sentence, so the disabled-button hint and the refusal banner were
 * the two places in the settlements screen that never translated.
 *
 * One object answers both: `message` is the English sentence the API and the
 * log keep, `reason` and `params` are what the admin panel translates. There
 * is still exactly one rule per gate — this only widens what it returns.
 *
 * Stringable so that `(string) Gate::blocker($row)` still yields the sentence.
 */
final class BlockedReason implements \Stringable
{
    /** @param array<string, string|int|float|bool|null> $params */
    public function __construct(
        public readonly BusinessRuleReason $reason,
        public readonly string $message,
        public readonly array $params = [],
    ) {}

    /** The same refusal, as the 409 the service raises. */
    public function toException(): BusinessRuleException
    {
        return new BusinessRuleException($this->reason, $this->message, $this->params);
    }

    public function __toString(): string
    {
        return $this->message;
    }
}
