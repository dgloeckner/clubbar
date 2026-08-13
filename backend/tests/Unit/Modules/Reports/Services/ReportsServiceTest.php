<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reports\Services;

use App\Modules\Reports\Domain\ReportFilters;
use App\Modules\Reports\Repositories\ReportsRepository;
use App\Modules\Reports\Services\ReportsService;
use PHPUnit\Framework\TestCase;

/**
 * What the reporting service decides, now that the SQL lives behind a
 * repository (#118): which reports exist, how a page is bounded, what a
 * percentage is, and where one till session ends and the next begins.
 */
class ReportsServiceTest extends TestCase
{
    private ReportsRepository $repository;
    private ReportsService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ReportsRepository::class);
        $this->service = new ReportsService($this->repository);
    }

    /**
     * @param array{total_revenue_cents?: int, transaction_count?: int, unique_member_count?: int} $overrides
     */
    private function stubSummary(array $overrides = []): void
    {
        $this->repository->method('summary')->willReturn($overrides + [
            'total_revenue_cents' => 0,
            'transaction_count' => 0,
            'unique_member_count' => 0,
        ]);
    }

    // ── Report validation ───────────────────────────────────────────────────

    public function test_an_unknown_report_type_is_refused_before_any_query_runs(): void
    {
        $this->repository->expects($this->never())->method('summary');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->getReport('everything', null, null);
    }

    public function test_an_unknown_grouping_is_refused_before_any_query_runs(): void
    {
        $this->repository->expects($this->never())->method('summary');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->getReport('revenue', null, null, 'phase-of-the-moon');
    }

    // ── Pagination ──────────────────────────────────────────────────────────

    public function test_a_page_size_over_the_cap_is_clamped_rather_than_honoured(): void
    {
        $this->stubSummary();
        $this->repository->expects($this->once())
            ->method('fetchGrouped')
            ->with($this->anything(), 'month', 'revenue', 100, 0)
            ->willReturn([]);

        $report = $this->service->getReport('revenue', null, null, 'month', null, null, 1, 5000);

        $this->assertSame(100, $report->perPage);
    }

    public function test_a_page_below_one_is_treated_as_the_first_page(): void
    {
        $this->stubSummary();
        $this->repository->expects($this->once())
            ->method('fetchGrouped')
            ->with($this->anything(), 'month', 'revenue', 25, 0)
            ->willReturn([]);

        $this->assertSame(1, $this->service->getReport('revenue', null, null, 'month', null, null, 0)->page);
    }

    public function test_the_offset_follows_the_requested_page(): void
    {
        $this->stubSummary();
        $this->repository->expects($this->once())
            ->method('fetchGrouped')
            ->with($this->anything(), 'day', 'revenue', 10, 20)
            ->willReturn([]);

        $this->service->getReport('revenue', null, null, 'day', null, null, 3, 10);
    }

    public function test_a_consumption_report_ranks_by_popularity_not_by_money(): void
    {
        $this->stubSummary();
        $this->repository->expects($this->once())
            ->method('fetchGrouped')
            ->with($this->anything(), $this->anything(), 'count', $this->anything(), $this->anything())
            ->willReturn([]);

        $this->service->getReport('consumption', null, null);
    }

    // ── Filters ─────────────────────────────────────────────────────────────

    public function test_comma_separated_ids_reach_the_repository_as_a_list(): void
    {
        $this->stubSummary();
        $this->repository->expects($this->once())
            ->method('countGroups')
            ->with($this->callback(function (ReportFilters $filters): bool {
                return $filters->categoryIds === ['c-1', 'c-2']
                    && $filters->productIds === ['p-9']
                    && $filters->dateFrom === '2026-01-01'
                    && $filters->dateTo === '2026-01-31';
            }))
            ->willReturn(0);
        $this->repository->method('fetchGrouped')->willReturn([]);

        $this->service->getReport('revenue', '2026-01-01', '2026-01-31', 'month', 'c-1,c-2', 'p-9');
    }

    public function test_an_empty_id_list_filters_on_nothing_rather_than_on_an_empty_string(): void
    {
        $filters = ReportFilters::fromRequest(null, null, '', ',,');

        $this->assertSame([], $filters->categoryIds);
        $this->assertSame([], $filters->productIds);
    }

    // ── Derived figures ─────────────────────────────────────────────────────

    public function test_each_row_carries_its_share_of_the_total(): void
    {
        $this->stubSummary(['total_revenue_cents' => 1000, 'transaction_count' => 4]);
        $this->repository->method('fetchGrouped')->willReturn([
            ['dimension' => 'Bier', 'revenue_cents' => 750, 'count' => 3],
            ['dimension' => 'Wein', 'revenue_cents' => 250, 'count' => 1],
        ]);

        $report = $this->service->getReport('revenue', null, null);

        $this->assertSame(75.0, $report->data[0]->percentOfTotal);
        $this->assertSame(25.0, $report->data[1]->percentOfTotal);
    }

    public function test_a_report_with_no_revenue_does_not_divide_by_zero(): void
    {
        $this->stubSummary();
        $this->repository->method('fetchGrouped')->willReturn([
            ['dimension' => 'Bier', 'revenue_cents' => 0, 'count' => 0],
        ]);

        $report = $this->service->getReport('revenue', null, null);

        $this->assertSame(0, $report->avgTransactionCents);
        $this->assertEqualsWithDelta(0.0, $report->data[0]->percentOfTotal, 0.0001);
    }

    public function test_the_average_transaction_is_the_total_over_the_count(): void
    {
        $this->stubSummary(['total_revenue_cents' => 1000, 'transaction_count' => 3]);
        $this->repository->method('fetchGrouped')->willReturn([]);

        $this->assertSame(333, $this->service->getReport('revenue', null, null)->avgTransactionCents);
    }

    // ── Terminal sessions ───────────────────────────────────────────────────

    public function test_no_transactions_means_no_sessions(): void
    {
        $this->assertSame([], ReportsService::groupIntoSessions([]));
    }

    public function test_back_to_back_sales_are_one_session(): void
    {
        $sessions = ReportsService::groupIntoSessions([
            ['amount_cents' => 250, 'occurred_at' => '2026-02-03 19:00:00'],
            ['amount_cents' => 350, 'occurred_at' => '2026-02-03 19:20:00'],
            ['amount_cents' => 400, 'occurred_at' => '2026-02-03 19:45:00'],
        ]);

        $this->assertCount(1, $sessions);
        $this->assertSame(3, $sessions[0]['transaction_count']);
        $this->assertSame(1000, $sessions[0]['revenue_cents']);
        $this->assertSame('19:00:00', $sessions[0]['start_time']);
        $this->assertSame('19:45:00', $sessions[0]['end_time']);
        $this->assertSame('2026-02-03', $sessions[0]['date']);
    }

    public function test_a_gap_of_more_than_half_an_hour_starts_a_new_session(): void
    {
        $sessions = ReportsService::groupIntoSessions([
            ['amount_cents' => 100, 'occurred_at' => '2026-02-03 19:00:00'],
            ['amount_cents' => 200, 'occurred_at' => '2026-02-03 19:31:00'],
        ]);

        $this->assertCount(2, $sessions);
        $this->assertSame(100, $sessions[0]['revenue_cents']);
        $this->assertSame(200, $sessions[1]['revenue_cents']);
    }

    public function test_a_gap_of_exactly_half_an_hour_keeps_the_session_open(): void
    {
        $sessions = ReportsService::groupIntoSessions([
            ['amount_cents' => 100, 'occurred_at' => '2026-02-03 19:00:00'],
            ['amount_cents' => 200, 'occurred_at' => '2026-02-03 19:30:00'],
        ]);

        $this->assertCount(1, $sessions);
    }

    public function test_the_gap_is_measured_from_the_last_sale_not_from_the_session_start(): void
    {
        // Five sales twenty minutes apart span more than half an hour end to end,
        // but never leave the till idle for half an hour.
        $sessions = ReportsService::groupIntoSessions([
            ['amount_cents' => 100, 'occurred_at' => '2026-02-03 19:00:00'],
            ['amount_cents' => 100, 'occurred_at' => '2026-02-03 19:20:00'],
            ['amount_cents' => 100, 'occurred_at' => '2026-02-03 19:40:00'],
            ['amount_cents' => 100, 'occurred_at' => '2026-02-03 20:00:00'],
            ['amount_cents' => 100, 'occurred_at' => '2026-02-03 20:20:00'],
        ]);

        $this->assertCount(1, $sessions);
        $this->assertSame('20:20:00', $sessions[0]['end_time']);
    }

    public function test_a_session_does_not_leak_its_bookkeeping_into_the_response(): void
    {
        $sessions = ReportsService::groupIntoSessions([
            ['amount_cents' => 100, 'occurred_at' => '2026-02-03 19:00:00'],
        ]);

        $this->assertSame(
            ['date', 'start_time', 'end_time', 'transaction_count', 'revenue_cents'],
            array_keys($sessions[0]),
        );
    }

    // ── Terminal activity assembly ──────────────────────────────────────────

    public function test_the_hourly_distribution_names_every_hour_including_the_quiet_ones(): void
    {
        $this->repository->method('hourlyDistribution')->willReturn([19 => 7]);

        $activity = $this->service->getTerminalActivity('2026-02-01', '2026-02-28');

        $this->assertCount(24, $activity['hourly_distribution']);
        $this->assertSame(['hour' => 0, 'transaction_count' => 0], $activity['hourly_distribution'][0]);
        $this->assertSame(['hour' => 19, 'transaction_count' => 7], $activity['hourly_distribution'][19]);
    }

    public function test_the_terminal_filter_reaches_the_repository(): void
    {
        $this->repository->expects($this->once())
            ->method('transactionsForActivity')
            ->with($this->callback(
                static fn(ReportFilters $filters): bool => $filters->terminalId === 'term-9'
                    && $filters->dateFrom === '2026-02-01'
                    && $filters->dateTo === '2026-02-28'
            ))
            ->willReturn([]);

        $this->service->getTerminalActivity('2026-02-01', '2026-02-28', 'term-9');
    }
}
