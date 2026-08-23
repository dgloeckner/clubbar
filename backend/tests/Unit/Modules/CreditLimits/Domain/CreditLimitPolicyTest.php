<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\CreditLimits\Domain;

use App\Modules\CreditLimits\Domain\CreditLimitPolicy;
use App\Modules\CreditLimits\Domain\CreditLimitStatus;
use PHPUnit\Framework\TestCase;

/**
 * The one place the override-or-default rule is expressed on this side
 * (ADR-0047 rule 1). Its twin lives in Dart, in the terminal, which has to
 * decide the same thing with nothing reachable.
 *
 * The distinction these tests exist to protect is `NULL` versus `0`:
 * `NULL` means "follow the club", `0` means "no ceiling for this member", and
 * normalising either into the other silently grants or revokes credit.
 */
class CreditLimitPolicyTest extends TestCase
{
    private function policy(int $defaultCents = 10_000, int $warnPercent = 80): CreditLimitPolicy
    {
        return new CreditLimitPolicy($defaultCents, $warnPercent);
    }

    public function test_a_member_without_an_override_follows_the_club(): void
    {
        $limit = $this->policy()->forMember(null);

        $this->assertSame(10_000, $limit->limitCents);
        $this->assertSame(8_000, $limit->warnAtCents());
    }

    public function test_raising_the_club_default_lifts_a_member_who_inherits(): void
    {
        // The whole point of NULL: a member never singled out moves with the
        // club, which a defaulted column would not do.
        $this->assertSame(15_000, $this->policy(15_000)->forMember(null)->limitCents);
    }

    public function test_an_override_wins_over_the_club_default(): void
    {
        $this->assertSame(5_000, $this->policy()->forMember(5_000)->limitCents);
        $this->assertSame(20_000, $this->policy()->forMember(20_000)->limitCents);
    }

    /**
     * The failure this feature most needs to avoid: an emptied form field
     * arriving as `0` would read as "unlimited", not as "back to the club
     * default".
     */
    public function test_zero_and_null_are_different_answers(): void
    {
        $policy = $this->policy();

        $this->assertFalse($policy->forMember(0)->isEnforced(), '0 means unlimited');
        $this->assertTrue($policy->forMember(null)->isEnforced(), 'NULL means follow the club');
    }

    public function test_an_uncapped_member_stays_uncapped_when_the_club_default_changes(): void
    {
        $this->assertFalse($this->policy(50_000)->forMember(0)->isEnforced());
    }

    /**
     * The near-limit panel must not short-circuit on the club default being
     * off: a member carrying an override still has a ceiling, and is still
     * being refused at the bar.
     */
    public function test_a_member_with_an_override_has_a_limit_even_when_the_club_has_none(): void
    {
        $limit = $this->policy(0)->forMember(5_000);

        $this->assertTrue($limit->isEnforced());
        $this->assertSame(4_000, $limit->warnAtCents());
        $this->assertSame(CreditLimitStatus::EXCEEDED, $limit->status(5_001));
    }

    public function test_a_club_that_caps_nobody_caps_everybody_who_inherits(): void
    {
        $this->assertFalse($this->policy(0)->forMember(null)->isEnforced());
    }

    /**
     * ADR-0047 decision 4: one knob per member, and it is the ceiling. The band
     * is always the club's share of whatever ceiling the member ends up with.
     */
    public function test_the_warning_band_is_the_clubs_whatever_the_ceiling(): void
    {
        $policy = $this->policy(10_000, 50);

        $this->assertSame(5_000, $policy->forMember(null)->warnAtCents());
        $this->assertSame(1_000, $policy->forMember(2_000)->warnAtCents());
    }

    public function test_the_club_default_is_the_same_rule_with_no_override(): void
    {
        $policy = $this->policy(12_000, 75);

        $this->assertEquals($policy->forMember(null), $policy->clubDefault());
    }

    /**
     * What ships when no configuration has been read — the constants this epic
     * replaces, so a fail-soft path degrades to today's behaviour rather than
     * to no limit at all.
     */
    public function test_the_shipped_policy_is_the_constant_it_replaces(): void
    {
        $shipped = CreditLimitPolicy::shipped();

        $this->assertSame(10_000, $shipped->defaultLimitCents);
        $this->assertSame(80, $shipped->warnThresholdPercent);
    }

    public function test_the_terminal_and_the_backend_still_agree_about_the_numbers(): void
    {
        // terminal-frontend/lib/config/app_config.dart carries these as the
        // fallback it enforces before its first /sync/config poll.
        $this->assertSame(10_000, CreditLimitPolicy::DEFAULT_LIMIT_CENTS);
        $this->assertSame(80, CreditLimitPolicy::DEFAULT_WARN_THRESHOLD_PERCENT);
    }
}
