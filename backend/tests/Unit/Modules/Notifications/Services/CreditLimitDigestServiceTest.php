<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\CreditLimits\Domain\CreditLimitPolicy;
use App\Modules\CreditLimits\Domain\CreditLimitStatus;
use App\Modules\CreditLimits\Repositories\NearLimitRepository;
use App\Modules\CreditLimits\Services\CreditLimitConfigService;
use App\Modules\Notifications\Services\CreditLimitDigestService;
use PHPUnit\Framework\TestCase;

/**
 * Turning near-limit rows into a digest's worth of content (ADR-0047).
 *
 * The query itself is the dashboard's and is tested where it lives. What is
 * tested here is everything the service adds on top of it — the per-row ceiling
 * resolution, the totals, and the cap that must never be silent.
 */
class CreditLimitDigestServiceTest extends TestCase
{
    /**
     * The ceiling in a line is the one the *member* is held to, not the club's.
     *
     * The row carries `limit_cents` already resolved by the query
     * (`COALESCE(m.credit_limit_cents, :default_limit)`), and the service runs
     * it back through `CreditLimitPolicy::forMember()` rather than trusting it
     * raw, so one rule answers the question everywhere (ADR-0047 rule 1).
     */
    public function test_a_line_carries_the_ceiling_that_applies_to_that_member(): void
    {
        $report = $this->collect(
            policy: new CreditLimitPolicy(10_000, 80),
            rows: [
                ['id' => 'm1', 'name' => 'Anna Schmidt', 'balance_cents' => 9_000, 'limit_cents' => 10_000],
                ['id' => 'm2', 'name' => 'Bert Klein', 'balance_cents' => 45_000, 'limit_cents' => 50_000],
            ],
        );

        $this->assertCount(2, $report->lines);
        $this->assertSame(10_000, $report->lines[0]->limitCents);
        $this->assertSame(50_000, $report->lines[1]->limitCents, 'the override, not the club default');
        $this->assertSame(10_000, $report->clubDefaultLimitCents);
        $this->assertSame(80, $report->warnThresholdPercent);
    }

    public function test_the_share_of_the_ceiling_is_reported_per_member(): void
    {
        $report = $this->collect(
            policy: new CreditLimitPolicy(10_000, 80),
            rows: [
                ['id' => 'm1', 'name' => 'Anna', 'balance_cents' => 9_000, 'limit_cents' => 10_000],
                ['id' => 'm2', 'name' => 'Bert', 'balance_cents' => 45_000, 'limit_cents' => 50_000],
            ],
        );

        $this->assertSame(90, $report->lines[0]->percentOfLimit);
        $this->assertSame(90, $report->lines[1]->percentOfLimit);
    }

    /**
     * Landing exactly on the ceiling is still allowed (UC-T12), so only a tab
     * strictly past it is `exceeded` — the same boundary the terminal holds.
     */
    public function test_only_a_tab_past_the_ceiling_counts_as_exceeded(): void
    {
        $report = $this->collect(
            policy: new CreditLimitPolicy(10_000, 80),
            rows: [
                ['id' => 'm1', 'name' => 'On the line', 'balance_cents' => 10_000, 'limit_cents' => 10_000],
                ['id' => 'm2', 'name' => 'Over it', 'balance_cents' => 10_100, 'limit_cents' => 10_000],
            ],
        );

        $this->assertSame(CreditLimitStatus::APPROACHING, $report->lines[0]->status);
        $this->assertSame(CreditLimitStatus::EXCEEDED, $report->lines[1]->status);
        $this->assertSame(1, $report->exceededCount);
    }

    public function test_the_total_is_the_sum_of_the_listed_tabs(): void
    {
        $report = $this->collect(
            policy: new CreditLimitPolicy(10_000, 80),
            rows: [
                ['id' => 'm1', 'name' => 'Anna', 'balance_cents' => 9_000, 'limit_cents' => 10_000],
                ['id' => 'm2', 'name' => 'Bert', 'balance_cents' => 8_500, 'limit_cents' => 10_000],
            ],
        );

        $this->assertSame(17_500, $report->totalOwedCents);
    }

    public function test_nobody_near_their_limit_is_an_empty_report(): void
    {
        $report = $this->collect(new CreditLimitPolicy(10_000, 80), []);

        $this->assertTrue($report->isEmpty());
        $this->assertSame(0, $report->count());
        $this->assertSame(0, $report->totalOwedCents);
    }

    /**
     * A short list is its own total, and the second query is not run.
     *
     * Not a micro-optimisation: `countNearLimit()` aggregates every unsettled
     * transaction in the club, and this service is called on every scheduler
     * tick that has the cadence on. Asking anyway would put that query on a hot
     * path that almost always has nothing to do.
     */
    public function test_a_short_list_does_not_ask_for_a_count(): void
    {
        $repository = $this->createMock(NearLimitRepository::class);
        $repository->method('findNearLimit')->willReturn([
            ['id' => 'm1', 'name' => 'Anna', 'balance_cents' => 9_000, 'limit_cents' => 10_000],
        ]);
        $repository->expects($this->never())->method('countNearLimit');

        $service = new CreditLimitDigestService($repository, $this->configService(new CreditLimitPolicy(10_000, 80)));

        $this->assertSame(0, $service->collect()->omitted);
    }

    /**
     * A full list says how many it is hiding.
     *
     * The rule this pins is "no silent caps": a list that simply stopped at a
     * hundred names reads as "that is everybody", and the club where it is not
     * is the one that most needs to know.
     */
    public function test_a_capped_list_reports_what_it_left_out(): void
    {
        $rows = [];
        for ($i = 0; $i < CreditLimitDigestService::MAX_LINES; $i++) {
            $rows[] = ['id' => 'm' . $i, 'name' => 'Member ' . $i, 'balance_cents' => 9_000, 'limit_cents' => 10_000];
        }

        $repository = $this->createMock(NearLimitRepository::class);
        $repository->method('findNearLimit')->willReturn($rows);
        $repository->method('countNearLimit')->willReturn(CreditLimitDigestService::MAX_LINES + 7);

        $service = new CreditLimitDigestService($repository, $this->configService(new CreditLimitPolicy(10_000, 80)));
        $report = $service->collect();

        $this->assertSame(CreditLimitDigestService::MAX_LINES, $report->count());
        $this->assertSame(7, $report->omitted);
    }

    /**
     * A count that came back smaller than the page — two ticks racing a
     * settlement run — reports nothing omitted rather than a negative number
     * that would render as "Another -3 members".
     */
    public function test_a_count_that_shrank_under_the_page_omits_nothing(): void
    {
        $rows = [];
        for ($i = 0; $i < CreditLimitDigestService::MAX_LINES; $i++) {
            $rows[] = ['id' => 'm' . $i, 'name' => 'Member ' . $i, 'balance_cents' => 9_000, 'limit_cents' => 10_000];
        }

        $repository = $this->createMock(NearLimitRepository::class);
        $repository->method('findNearLimit')->willReturn($rows);
        $repository->method('countNearLimit')->willReturn(3);

        $service = new CreditLimitDigestService($repository, $this->configService(new CreditLimitPolicy(10_000, 80)));

        $this->assertSame(0, $service->collect()->omitted);
    }

    /** The query is asked for the club's numbers, and for no more rows than the cap. */
    public function test_the_query_is_asked_with_the_clubs_policy(): void
    {
        $repository = $this->createMock(NearLimitRepository::class);
        $repository->expects($this->once())
            ->method('findNearLimit')
            ->with(7_500, 70, CreditLimitDigestService::MAX_LINES)
            ->willReturn([]);

        $service = new CreditLimitDigestService($repository, $this->configService(new CreditLimitPolicy(7_500, 70)));
        $service->collect();
    }

    /** @param list<array<string,mixed>> $rows */
    private function collect(CreditLimitPolicy $policy, array $rows): \App\Modules\Notifications\DTOs\CreditLimitDigestReportDto
    {
        $repository = $this->createMock(NearLimitRepository::class);
        $repository->method('findNearLimit')->willReturn($rows);
        $repository->method('countNearLimit')->willReturn(count($rows));

        return (new CreditLimitDigestService($repository, $this->configService($policy)))->collect();
    }

    private function configService(CreditLimitPolicy $policy): CreditLimitConfigService
    {
        $service = $this->createMock(CreditLimitConfigService::class);
        $service->method('policy')->willReturn($policy);

        return $service;
    }
}
