<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * How often this club sends a Deckelauszug (ADR-0039 decision 3).
 *
 * Club-wide and nothing else. There is deliberately **no per-member opt-out**:
 * this system has no member login, so an opt-out would mean a public endpoint,
 * a token, an unsubscribe link and a preference record — a larger feature than
 * the statement itself. `off` is the off-ramp, and it belongs to whoever runs
 * the club.
 *
 * ### Why there is no `weekly`
 *
 * For a different reason than {@see CronInterval}'s refusal of the same word.
 * Nothing breaks at a weekly statement; it simply stops being the thing the ADR
 * describes. Fifty-two itemised statements a year makes "predictable" read as
 * "relentless", and a tab that settles monthly does not move fast enough to
 * report on weekly. The value is absent rather than validated away, so the
 * question cannot be reopened by editing a rule list.
 */
enum StatementCadence: string
{
    /** No statements. The only control a club has, and it is a real one. */
    case OFF = 'off';

    case MONTHLY = 'monthly';

    case QUARTERLY = 'quarterly';

    /**
     * What a fresh installation is configured with (ADR-0039 decision 3).
     *
     * Note that this is the *column* default, and that migration 039 then sets
     * the singleton row to {@see OFF}. See that migration for why an upgrade
     * must not start mailing a live membership as a side effect of running
     * migrations, and #463 (P12) for what turns it back on.
     */
    public const DEFAULT = self::MONTHLY;

    /**
     * Read a stored value, falling back rather than throwing.
     *
     * A row written before migration 039 and a value this build does not know
     * are the same situation, and the safe answer to it is not the default: it
     * is {@see OFF}. Being wrong in the other direction mails an entire
     * membership on the strength of a column nobody could parse.
     */
    public static function fromDeclared(mixed $value): self
    {
        return self::tryFrom(is_string($value) ? $value : '') ?? self::OFF;
    }

    public function isEnabled(): bool
    {
        return $this !== self::OFF;
    }
}
