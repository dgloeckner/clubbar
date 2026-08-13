<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Services;

use App\Shared\Config\AppConfig;
use App\Shared\Services\SecurityCheckService;
use PHPUnit\Framework\TestCase;

/**
 * The security report the admin panel renders (#247, ADR-0031 decision 3).
 *
 * This used to also cover an "IBAN encryption backfill" row, which counted
 * mandate rows still holding a plaintext IBAN. That row went with the column:
 * migration 020 dropped `mandates.iban`, so there is no longer a state for it
 * to report — the schema itself now guarantees what the finding used to check.
 *
 * What is left to assert here is the wiring: the service runs the self-check
 * against the live process and summarizes what comes back.
 */
class SecurityCheckServiceTest extends TestCase
{
    private function report(): \App\Shared\DTOs\SecurityReportDto
    {
        return (new SecurityCheckService(new AppConfig()))
            ->check(['DOCUMENT_ROOT' => sys_get_temp_dir()]);
    }

    public function test_the_report_carries_findings_and_a_generation_timestamp(): void
    {
        $report = $this->report();

        $this->assertNotEmpty($report->findings, 'a report with no findings would say nothing');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $report->generatedAt,
        );
    }

    public function test_the_summary_accounts_for_every_finding(): void
    {
        $report = $this->report();
        $summary = $report->summary;

        $counted = array_sum(array_filter($summary, 'is_int'));

        $this->assertSame(
            count($report->findings),
            $counted,
            'every finding must land in exactly one summary bucket',
        );
    }

    /** The backfill row is gone, and must not come back as a stale "unknown". */
    public function test_there_is_no_iban_backfill_finding_any_more(): void
    {
        $ids = array_map(static fn($finding) => $finding->id, $this->report()->findings);

        $this->assertNotContains('iban-encryption-backfill', $ids);
    }
}
