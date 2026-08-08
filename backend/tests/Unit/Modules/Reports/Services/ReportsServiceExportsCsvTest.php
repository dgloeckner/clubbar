<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reports\Services;

use App\Modules\Reports\Services\ReportsService;
use PHPUnit\Framework\TestCase;

/**
 * The member-ranking and terminal-activity exports (#94). The page used to
 * append `format=csv` to the list endpoints, which the backend ignored — the
 * download was named .csv and contained JSON. These are the CSV bodies the
 * dedicated /export endpoints now return.
 */
class ReportsServiceExportsCsvTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rankingCsv(array $rows, bool $anonymize = false): string
    {
        $service = $this->getMockBuilder(ReportsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getMemberRanking'])
            ->getMock();

        $service->method('getMemberRanking')->willReturn(['data' => $rows]);

        return $service->exportMemberRankingCsv(null, null, $anonymize, 25);
    }

    /**
     * @param array<string, mixed> $activity
     */
    private function activityCsv(array $activity): string
    {
        $service = $this->getMockBuilder(ReportsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTerminalActivity'])
            ->getMock();

        $service->method('getTerminalActivity')->willReturn($activity + [
            'sessions' => [],
            'hourly_distribution' => [],
            'terminals' => [],
        ]);

        return $service->exportTerminalActivityCsv('2026-01-01', '2026-01-31');
    }

    // ── Member ranking ──────────────────────────────────────────────────────

    public function test_ranking_writes_semicolons_and_euros(): void
    {
        $csv = $this->rankingCsv([
            ['rank' => 1, 'member_name' => 'Anna Meier', 'total_amount_cents' => 123456, 'transaction_count' => 40],
            ['rank' => 2, 'member_name' => 'Ben Schulz', 'total_amount_cents' => 500, 'transaction_count' => 2],
        ]);

        $this->assertSame(
            "Rank;Member;Total EUR;Transactions\n"
            . "1;Anna Meier;1234.56;40\n"
            . "2;Ben Schulz;5.00;2\n",
            $csv
        );
    }

    public function test_ranking_quotes_a_member_name_containing_the_delimiter(): void
    {
        $csv = $this->rankingCsv([
            ['rank' => 1, 'member_name' => 'Meier; Hans', 'total_amount_cents' => 100, 'transaction_count' => 1],
        ]);

        $this->assertStringContainsString('1;"Meier; Hans";1.00;1', $csv);
    }

    public function test_ranking_carries_the_anonymized_names_it_was_given(): void
    {
        $csv = $this->rankingCsv([
            ['rank' => 1, 'member_name' => 'Member 1', 'total_amount_cents' => 100, 'transaction_count' => 1],
        ], anonymize: true);

        $this->assertStringContainsString('1;Member 1;1.00;1', $csv);
        $this->assertStringNotContainsString('Meier', $csv);
    }

    public function test_ranking_writes_a_header_alone_when_nobody_bought_anything(): void
    {
        $this->assertSame("Rank;Member;Total EUR;Transactions\n", $this->rankingCsv([]));
    }

    // ── Terminal activity ───────────────────────────────────────────────────

    public function test_activity_carries_all_three_blocks_of_the_report(): void
    {
        $csv = $this->activityCsv([
            'sessions' => [[
                'date' => '2026-01-05',
                'start_time' => '18:00:00',
                'end_time' => '22:30:00',
                'transaction_count' => 12,
                'revenue_cents' => 4250,
            ]],
            'hourly_distribution' => [
                ['hour' => 9, 'transaction_count' => 0],
                ['hour' => 18, 'transaction_count' => 7],
            ],
            'terminals' => [[
                'id' => 'abc',
                'name' => 'Tresen 1',
                'transaction_count' => 12,
                'last_sync_at' => '2026-01-05T22:31:00Z',
            ]],
        ]);

        $this->assertSame(
            "Sessions\n"
            . "Date;Start;End;Transactions;Revenue EUR\n"
            . "2026-01-05;18:00:00;22:30:00;12;42.50\n"
            . "\nHourly Distribution\n"
            . "Hour;Transactions\n"
            . "09:00;0\n"
            . "18:00;7\n"
            . "\nTerminals\n"
            . "Terminal;Transactions;Last Sync\n"
            . "Tresen 1;12;2026-01-05T22:31:00Z\n",
            $csv
        );
    }

    public function test_activity_writes_an_empty_cell_for_a_terminal_that_never_synced(): void
    {
        $csv = $this->activityCsv([
            'terminals' => [[
                'id' => 'abc',
                'name' => 'Tresen 1',
                'transaction_count' => 3,
                'last_sync_at' => null,
            ]],
        ]);

        $this->assertStringContainsString("Tresen 1;3;\n", $csv);
    }

    public function test_activity_quotes_a_terminal_name_containing_the_delimiter(): void
    {
        $csv = $this->activityCsv([
            'terminals' => [[
                'id' => 'abc',
                'name' => 'Tresen; oben',
                'transaction_count' => 1,
                'last_sync_at' => null,
            ]],
        ]);

        $this->assertStringContainsString('"Tresen; oben";1;', $csv);
    }

    public function test_activity_still_labels_every_block_when_there_is_no_data(): void
    {
        $csv = $this->activityCsv([]);

        $this->assertSame(
            "Sessions\n"
            . "Date;Start;End;Transactions;Revenue EUR\n"
            . "\nHourly Distribution\n"
            . "Hour;Transactions\n"
            . "\nTerminals\n"
            . "Terminal;Transactions;Last Sync\n",
            $csv
        );
    }
}
