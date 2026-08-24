<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * How often the near-limit digest reaches whoever runs the club.
 *
 * A sibling of {@see StatementCadence}, and the differences between the two are
 * the whole reason it is a separate enum rather than a reused one.
 *
 * ### Why `weekly` is here and not there
 *
 * `StatementCadence` refuses `weekly` because fifty-two itemised statements a
 * year makes "predictable" read as "relentless" — and it is addressed to the
 * entire membership, who did not ask for it and cannot turn it off.
 *
 * This one is addressed to the handful of people who run the club, about a
 * condition that changes inside a week: a tab that crossed 80 % on Tuesday is
 * refused at the bar on Saturday. A monthly-only digest would report that after
 * the member had already been turned away, which is precisely the outcome the
 * feature exists to prevent. So `weekly` is the default, and `daily` is
 * available to a busy club.
 *
 * ### Why there is no `hourly`
 *
 * The same reason {@see CronInterval} is not this enum: this is a *digest*, and
 * a digest that arrives more often than the thing it summarises changes is a
 * notification stream. A Deckel moves at the speed of a bar evening. Anybody
 * who wants the live figure has the dashboard, which is the pull half of the
 * same list and is always current.
 */
enum DigestCadence: string
{
    /** No digest. The club's off-ramp, and a real one. */
    case OFF = 'off';

    case DAILY = 'daily';

    case WEEKLY = 'weekly';

    case MONTHLY = 'monthly';

    /**
     * What a fresh installation is configured with, and — unlike
     * {@see StatementCadence::DEFAULT} — what migration 053 actually leaves the
     * singleton row holding.
     *
     * See that migration for the argument. In short: this mails the admins and
     * the Kassenwart rather than the membership, and it sends nothing at all
     * when nobody is near their ceiling, so "on by default" costs a quiet club
     * no mail and saves a busy one a member turned away at the bar.
     */
    public const DEFAULT = self::WEEKLY;

    /**
     * Read a stored value, falling back rather than throwing.
     *
     * Falls back to {@see OFF}, not to {@see DEFAULT}. A row written before
     * migration 053 and a value this build cannot parse are the same situation,
     * and the safe answer to "I do not know what this column says" is to send
     * nothing — a column nobody could read is not a mandate to start mailing.
     * That is the identical rule {@see StatementCadence::fromDeclared()}
     * follows, and it is deliberately *not* symmetric with the column default:
     * the default states what a club that has never chosen gets, this states
     * what an unreadable value gets, and they are different questions.
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
