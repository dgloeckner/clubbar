<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Services\AuditService;
use App\Shared\Utils\BankingCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Covers the minimum-execution-date rule served to the admin UI (ADR-0009).
 */
class ExecutionDateInfoTest extends TestCase
{
    private function makeService(): SettlementsService
    {
        return new SettlementsService(
            $this->createMock(SettlementsRepository::class),
            $this->createMock(MembersRepository::class),
            $this->createMock(TransactionsRepository::class),
            $this->createMock(AuditService::class),
            $this->createMock(\PDO::class),
        );
    }

    public function test_minimum_date_is_seven_days_out_when_that_is_a_business_day(): void
    {
        // 2026-08-05 (Wed) + 7 = 2026-08-12 (Wed), an ordinary business day.
        $info = $this->makeService()->getExecutionDateInfo('2026-08-05');

        $this->assertSame('2026-08-12', $info->minimumDate);
        $this->assertSame(7, $info->leadTimeDays);
    }

    public function test_minimum_date_rolls_off_a_weekend(): void
    {
        // 2026-08-02 (Sun) + 7 = 2026-08-09 (Sun) — the date from issue #11.
        $info = $this->makeService()->getExecutionDateInfo('2026-08-02');

        $this->assertSame('2026-08-10', $info->minimumDate);
    }

    public function test_minimum_date_rolls_off_the_easter_closing_days(): void
    {
        // 2026-03-27 + 7 = 2026-04-03 (Good Friday), followed by the weekend
        // and Easter Monday: four consecutive closing days.
        $info = $this->makeService()->getExecutionDateInfo('2026-03-27');

        $this->assertSame('2026-04-07', $info->minimumDate);
    }

    public function test_minimum_date_rolls_off_christmas_into_the_next_year(): void
    {
        // 2026-12-18 + 7 = 2026-12-25 (Fri, closing day), then Sat/Sun.
        $info = $this->makeService()->getExecutionDateInfo('2026-12-18');

        $this->assertSame('2026-12-28', $info->minimumDate);
    }

    public function test_rule_text_describes_both_halves_of_the_constraint(): void
    {
        $info = $this->makeService()->getExecutionDateInfo('2026-08-05');

        $this->assertStringContainsString('7 calendar days', $info->rule);
        $this->assertStringContainsString('business day', $info->rule);
    }

    public function test_default_today_yields_a_valid_future_business_day(): void
    {
        $info = $this->makeService()->getExecutionDateInfo();

        $this->assertTrue(
            BankingCalendar::isBusinessDay($info->minimumDate),
            'The suggested minimum date must always be a business day'
        );
        $this->assertGreaterThanOrEqual(
            (new \DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d'),
            $info->minimumDate,
            'The suggested minimum date must respect the 7-day lead time'
        );
    }

    public function test_serialises_to_the_documented_response_shape(): void
    {
        $payload = $this->makeService()->getExecutionDateInfo('2026-08-05')->toArray();

        $this->assertSame(['minimum_date', 'lead_time_days', 'rule'], array_keys($payload));
        $this->assertSame('2026-08-12', $payload['minimum_date']);
        $this->assertSame(7, $payload['lead_time_days']);
    }
}
