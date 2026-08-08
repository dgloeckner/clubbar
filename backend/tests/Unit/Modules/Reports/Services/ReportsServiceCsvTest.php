<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reports\Services;

use App\Modules\Reports\DTOs\ReportDto;
use App\Modules\Reports\DTOs\ReportRowDto;
use App\Modules\Reports\Services\ReportsService;
use PHPUnit\Framework\TestCase;

/**
 * The report CSV export (#119) — the fourth CSV dialect: comma-separated,
 * money in raw cents under a header that said "(cents)", and quoting applied
 * to the dimension column only. It now goes through the shared writer like
 * everything else.
 */
class ReportsServiceCsvTest extends TestCase
{
    /**
     * @param list<ReportRowDto> $rows
     */
    private function exportWith(array $rows): string
    {
        $service = $this->getMockBuilder(ReportsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getReport'])
            ->getMock();

        $service->method('getReport')->willReturn(new ReportDto(
            reportType: 'revenue',
            filters: [],
            totalRevenueCents: 0,
            uniqueMemberCount: 0,
            transactionCount: 0,
            avgTransactionCents: 0,
            data: $rows,
            page: 1,
            perPage: 10000,
            total: count($rows),
        ));

        return $service->exportCsv('revenue', null, null);
    }

    public function test_it_names_the_money_column_for_its_unit_and_uses_semicolons(): void
    {
        $csv = $this->exportWith([new ReportRowDto('Bier', 123456, 40, 62.5)]);

        $this->assertSame(
            "Dimension;Revenue EUR;Count;% of Total\nBier;1234.56;40;62.5\n",
            $csv
        );
    }

    public function test_it_quotes_a_dimension_containing_the_delimiter(): void
    {
        $csv = $this->exportWith([new ReportRowDto('Bier; gross', 100, 1, 100.0)]);

        $this->assertStringContainsString('"Bier; gross";1.00;', $csv);
    }

    public function test_it_escapes_embedded_quotes(): void
    {
        $csv = $this->exportWith([new ReportRowDto('Bier "gross"', 100, 1, 100.0)]);

        $this->assertStringContainsString('"Bier ""gross""";1.00;', $csv);
    }

    public function test_it_writes_a_header_alone_for_an_empty_report(): void
    {
        $this->assertSame("Dimension;Revenue EUR;Count;% of Total\n", $this->exportWith([]));
    }

    public function test_it_rounds_the_percentage_the_dto_way(): void
    {
        $csv = $this->exportWith([new ReportRowDto('Bier', 100, 1, 33.333333)]);

        $this->assertStringContainsString(';33.33', $csv);
    }
}
