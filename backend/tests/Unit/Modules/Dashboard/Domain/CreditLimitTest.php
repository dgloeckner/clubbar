<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Dashboard\Domain;

use App\Modules\Dashboard\Domain\CreditLimit;
use PHPUnit\Framework\TestCase;

/**
 * Where the credit limit's line sits (#385).
 *
 * The dashboard's list of members near their limit is only worth anything if it
 * draws the line in the same place the terminal does — a member who appears
 * here but is served without a word, or is refused without ever appearing, is
 * worse than no list at all. Every case below is one the terminal's own
 * `CreditLimitCheck` tests already pin down; they are repeated on this side of
 * the wire because the two copies of the numbers can drift.
 */
class CreditLimitTest extends TestCase
{
    public function test_the_warning_band_starts_at_the_configured_share_of_the_limit(): void
    {
        $this->assertSame(8_000, CreditLimit::warnAtCents());
    }

    public function test_a_tab_below_the_band_is_no_concern(): void
    {
        $this->assertSame(CreditLimit::STATUS_OK, CreditLimit::status(CreditLimit::warnAtCents() - 1));
    }

    public function test_the_band_is_inclusive_at_its_lower_edge(): void
    {
        $this->assertSame(CreditLimit::STATUS_APPROACHING, CreditLimit::status(CreditLimit::warnAtCents()));
    }

    public function test_landing_exactly_on_the_limit_is_still_only_approaching(): void
    {
        // UC-T12: the terminal blocks what would go *past* the limit, so a
        // member sitting exactly on it has not been cut off yet.
        $this->assertSame(CreditLimit::STATUS_APPROACHING, CreditLimit::status(CreditLimit::LIMIT_CENTS));
    }

    public function test_one_cent_past_the_limit_is_exceeded(): void
    {
        $this->assertSame(CreditLimit::STATUS_EXCEEDED, CreditLimit::status(CreditLimit::LIMIT_CENTS + 1));
    }

    public function test_a_member_in_credit_has_no_limit_problem(): void
    {
        $this->assertSame(CreditLimit::STATUS_OK, CreditLimit::status(-5_000));
        $this->assertSame(0, CreditLimit::percentOfLimit(-5_000));
    }

    public function test_the_share_of_the_limit_is_a_whole_percentage(): void
    {
        $this->assertSame(0, CreditLimit::percentOfLimit(0));
        $this->assertSame(80, CreditLimit::percentOfLimit(8_000));
        $this->assertSame(96, CreditLimit::percentOfLimit(9_555));
        $this->assertSame(100, CreditLimit::percentOfLimit(CreditLimit::LIMIT_CENTS));
    }

    public function test_a_tab_over_the_limit_reports_more_than_a_full_share(): void
    {
        // Not clamped: a member at 120 % is a different conversation from one at
        // exactly 100 %, and the number is what makes that visible.
        $this->assertSame(120, CreditLimit::percentOfLimit(12_000));
    }

    public function test_the_backend_agrees_with_the_terminal_about_the_numbers(): void
    {
        // terminal-frontend/lib/config/app_config.dart — change both or neither.
        $this->assertSame(10_000, CreditLimit::LIMIT_CENTS);
        $this->assertSame(80, CreditLimit::WARN_THRESHOLD_PERCENT);
        $this->assertTrue(CreditLimit::isEnforced());
    }
}
