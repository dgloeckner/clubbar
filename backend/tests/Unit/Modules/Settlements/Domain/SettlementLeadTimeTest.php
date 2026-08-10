<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Domain;

use App\Modules\Settlements\Domain\SettlementLeadTime;
use PHPUnit\Framework\TestCase;

/**
 * The SEPA lead time, and the anchor it is measured from (ADR-0009, issue #113).
 *
 * The point of every test below is the anchor. The rule was once written as
 * `execution_date >= settlement_date + 7 days` against a `settlement_date` that
 * arrived in the request body, so the caller chose where the seven days were
 * counted from. Now only the server's calendar day does.
 */
class SettlementLeadTimeTest extends TestCase
{
    public function test_the_lead_time_is_seven_calendar_days(): void
    {
        $this->assertSame(7, SettlementLeadTime::DAYS);
        $this->assertSame('2026-08-12', SettlementLeadTime::earliestDate('2026-08-05'));
    }

    public function test_the_earliest_date_is_calendar_days_and_may_be_a_closing_day(): void
    {
        // 2026-08-02 + 7 = Sunday 2026-08-09, the day reported in issue #11.
        $this->assertSame('2026-08-09', SettlementLeadTime::earliestDate('2026-08-02'));
        $this->assertSame('2026-08-10', SettlementLeadTime::earliestBusinessDay('2026-08-02'));
    }

    /**
     * Good Friday through Easter Monday 2026 is four consecutive closing days,
     * so the roll can cost four more than the seven.
     */
    public function test_the_roll_clears_the_easter_closing_run(): void
    {
        $this->assertSame('2026-04-03', SettlementLeadTime::earliestDate('2026-03-27'));
        $this->assertSame('2026-04-07', SettlementLeadTime::earliestBusinessDay('2026-03-27'));
    }

    public function test_the_seventh_day_is_accepted_and_the_sixth_is_not(): void
    {
        $this->assertTrue(SettlementLeadTime::isSatisfiedBy('2026-08-12', '2026-08-05'));
        $this->assertTrue(SettlementLeadTime::isSatisfiedBy('2026-08-13', '2026-08-05'));
        $this->assertFalse(SettlementLeadTime::isSatisfiedBy('2026-08-11', '2026-08-05'));
    }

    public function test_a_date_in_the_past_never_satisfies_the_lead_time(): void
    {
        $this->assertFalse(SettlementLeadTime::isSatisfiedBy('2026-07-01', '2026-08-05'));
    }

    /**
     * The regression itself: a backdated anchor no longer moves the boundary.
     *
     * Under the old rule `settlement_date = 2026-07-01` made 2026-07-08 — five
     * weeks in the past — a "valid" execution date, and members were debited
     * with no pre-notification period at all.
     */
    public function test_a_past_anchor_cannot_be_supplied_to_shorten_the_lead_time(): void
    {
        $today = '2026-08-05';

        $this->assertFalse(SettlementLeadTime::isSatisfiedBy('2026-07-08', $today));
        $this->assertFalse(SettlementLeadTime::isSatisfiedBy('2026-08-06', $today));
    }

    /**
     * What the server suggests is by construction what the server accepts —
     * on every day of the year, including the ones where the roll adds days.
     */
    public function test_the_suggested_business_day_always_passes_the_check(): void
    {
        $day = new \DateTimeImmutable('2026-01-01');

        for ($i = 0; $i < 400; $i++) {
            $today = $day->modify("+{$i} days")->format('Y-m-d');
            $suggested = SettlementLeadTime::earliestBusinessDay($today);

            $this->assertTrue(
                SettlementLeadTime::isSatisfiedBy($suggested, $today),
                "The minimum date suggested on {$today} ({$suggested}) must pass the lead-time check",
            );
        }
    }

    public function test_today_normalises_to_a_plain_date(): void
    {
        $this->assertSame('2026-08-05', SettlementLeadTime::today('2026-08-05'));
        $this->assertSame(date('Y-m-d'), SettlementLeadTime::today());
    }

    /** The message names the date a caller should retry with, not just the rule. */
    public function test_the_violation_message_names_the_earliest_acceptable_date(): void
    {
        $message = SettlementLeadTime::violationMessage('2026-08-05');

        $this->assertStringContainsString('7 days', $message);
        $this->assertStringContainsString('2026-08-12', $message);
    }
}
