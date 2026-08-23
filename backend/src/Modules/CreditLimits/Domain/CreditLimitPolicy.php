<?php

declare(strict_types=1);

namespace App\Modules\CreditLimits\Domain;

/**
 * The club's settings, and the one place the override-or-default rule is
 * expressed on this side of the wire (ADR-0046 rule 1).
 *
 * Its twin lives in Dart. The terminal decides at checkout with no backend
 * reachable, so it cannot ask this class and has to coalesce the same two
 * values itself. ADR-0046 decision 2 accepts that duplication *because it is
 * one line on each side*: the moment this method grows a second condition, the
 * two copies stop being trivially comparable. Anything that needs a limit —
 * controller, service, query — asks {@see forMember()} rather than reaching for
 * the numbers.
 */
final readonly class CreditLimitPolicy
{
    /**
     * What ships when nothing has been configured, and what the terminal
     * enforces before its first `/sync/config` poll — the constants this epic
     * replaces (`terminal-frontend/lib/config/app_config.dart`).
     */
    public const DEFAULT_LIMIT_CENTS = 10_000;
    public const DEFAULT_WARN_THRESHOLD_PERCENT = 80;

    /**
     * The largest ceiling that can be set, club-wide or per member: €100,000.
     *
     * Not a business rule so much as a fat-finger stop — a Deckel of that size
     * is a conversation, not a setting. Shared by the club rule and the
     * per-member one so the two bounds cannot drift apart.
     */
    public const MAX_LIMIT_CENTS = 10_000_000;

    public function __construct(
        public int $defaultLimitCents,
        public int $warnThresholdPercent,
    ) {}

    /** The compiled-in defaults, for callers with no database to read. */
    public static function shipped(): self
    {
        return new self(self::DEFAULT_LIMIT_CENTS, self::DEFAULT_WARN_THRESHOLD_PERCENT);
    }

    /**
     * The ceiling that applies to one member.
     *
     * `NULL` means inherit — the member was never singled out and moves with
     * the club default, which is what a club-level default is for. `0` is a
     * different answer: a deliberate "no ceiling for this member", which
     * survives a change to the club default rather than following it.
     *
     * The warning band is always the club's, applied to whatever ceiling the
     * member ends up with (ADR-0046 decision 4).
     */
    public function forMember(?int $overrideCents): CreditLimit
    {
        return new CreditLimit($overrideCents ?? $this->defaultLimitCents, $this->warnThresholdPercent);
    }

    /** The club's own ceiling — the same rule, with nobody singled out. */
    public function clubDefault(): CreditLimit
    {
        return $this->forMember(null);
    }
}
