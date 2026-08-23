<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\CreditLimits\Domain;

use App\Modules\CreditLimits\Domain\CreditLimit;
use App\Modules\CreditLimits\Domain\CreditLimitStatus;
use PHPUnit\Framework\TestCase;

/**
 * Where a resolved credit limit's line sits (#385, ADR-0046).
 *
 * Moved here from `Modules/Dashboard/Domain` when the limit became
 * configurable: the rules are unchanged, but the numbers are now carried by an
 * instance rather than compiled in as constants, because a static class cannot
 * hold a value that differs per member.
 *
 * Every case below is one the terminal's own `CreditLimitCheck` tests already
 * pin down. They are repeated on this side of the wire because ADR-0046
 * decision 2 keeps the resolution rule in two languages on purpose, and this is
 * half of what makes that duplication safe.
 */
class CreditLimitTest extends TestCase
{
    private const LIMIT = 10_000;

    private function limit(int $limitCents = self::LIMIT, int $warnPercent = 80): CreditLimit
    {
        return new CreditLimit($limitCents, $warnPercent);
    }

    public function test_the_warning_band_starts_at_the_configured_share_of_the_limit(): void
    {
        $this->assertSame(8_000, $this->limit()->warnAtCents());
    }

    public function test_the_band_boundary_is_a_whole_cent_rounded_down(): void
    {
        // Integer division, as at the terminal — both sides must round the
        // boundary cent the same way, or the dashboard lists a member the
        // terminal has not warned yet. 70 % of 3333 is 2333.1.
        $this->assertSame(2_333, $this->limit(3_333, 70)->warnAtCents());
    }

    public function test_a_tab_below_the_band_is_no_concern(): void
    {
        $this->assertSame(CreditLimitStatus::OK, $this->limit()->status(7_999));
    }

    public function test_the_band_is_inclusive_at_its_lower_edge(): void
    {
        $this->assertSame(CreditLimitStatus::APPROACHING, $this->limit()->status(8_000));
    }

    public function test_landing_exactly_on_the_limit_is_still_only_approaching(): void
    {
        // UC-T12: the terminal blocks what would go *past* the limit, so a
        // member sitting exactly on it has not been cut off yet.
        $this->assertSame(CreditLimitStatus::APPROACHING, $this->limit()->status(self::LIMIT));
    }

    public function test_one_cent_past_the_limit_is_exceeded(): void
    {
        $this->assertSame(CreditLimitStatus::EXCEEDED, $this->limit()->status(self::LIMIT + 1));
    }

    public function test_a_member_in_credit_has_no_limit_problem(): void
    {
        $this->assertSame(CreditLimitStatus::OK, $this->limit()->status(-5_000));
        $this->assertSame(0, $this->limit()->percentOfLimit(-5_000));
    }

    public function test_the_share_of_the_limit_is_a_whole_percentage(): void
    {
        $this->assertSame(0, $this->limit()->percentOfLimit(0));
        $this->assertSame(80, $this->limit()->percentOfLimit(8_000));
        $this->assertSame(96, $this->limit()->percentOfLimit(9_555));
        $this->assertSame(100, $this->limit()->percentOfLimit(self::LIMIT));
    }

    public function test_a_tab_over_the_limit_reports_more_than_a_full_share(): void
    {
        // Not clamped: a member at 120 % is a different conversation from one at
        // exactly 100 %, and the number is what makes that visible.
        $this->assertSame(120, $this->limit()->percentOfLimit(12_000));
    }

    /**
     * ADR-0046 decision 3: zero is a ceiling nobody hits, not a ceiling of
     * zero. The same convention `CreditLimitCheck` and the Deckelauszug already
     * use.
     */
    public function test_a_ceiling_of_zero_means_unlimited(): void
    {
        $unlimited = $this->limit(0);

        $this->assertFalse($unlimited->isEnforced());
        $this->assertSame(CreditLimitStatus::OK, $unlimited->status(500_000));
        $this->assertSame(0, $unlimited->percentOfLimit(500_000));
    }

    public function test_an_unenforced_limit_warns_at_its_own_ceiling(): void
    {
        // Equal to the ceiling rather than a share of it, which is what keeps
        // "is this member in the band?" false for every tab.
        $this->assertSame(0, $this->limit(0)->warnAtCents());
    }

    public function test_a_ceiling_carries_its_own_numbers(): void
    {
        $limit = $this->limit(20_000, 90);

        $this->assertTrue($limit->isEnforced());
        $this->assertSame(20_000, $limit->limitCents);
        $this->assertSame(18_000, $limit->warnAtCents());
        $this->assertSame(CreditLimitStatus::OK, $limit->status(17_999));
    }
}
