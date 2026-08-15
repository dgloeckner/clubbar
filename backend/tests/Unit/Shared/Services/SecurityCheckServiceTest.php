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

    /**
     * The delivery rows are appended by this service rather than produced by
     * the engine, because the engine is dependency-free by contract and they
     * need the database (#406). Without the collaborator the report is the
     * shorter one `install.php` and the package smoke test get — and it must
     * still be a report rather than a crash.
     */
    public function test_delivery_rows_are_absent_when_no_mail_check_is_wired(): void
    {
        $ids = array_map(static fn($finding) => $finding->id, $this->report()->findings);

        $this->assertNotContains('mail_transport', $ids);
        $this->assertNotEmpty($ids);
    }

    public function test_delivery_rows_are_appended_when_the_mail_check_is_wired(): void
    {
        $mailCheck = $this->createMock(\App\Modules\Notifications\Services\MailDeliveryCheck::class);
        $mailCheck->method('findings')->willReturn([
            \App\Shared\Security\SecurityFinding::pass('mail_transport', 'delivery', 'Mail can leave', 'smtp'),
        ]);

        $report = (new SecurityCheckService(new AppConfig(), $mailCheck))
            ->check(['DOCUMENT_ROOT' => sys_get_temp_dir()]);

        $ids = array_map(static fn($finding) => $finding->id, $report->findings);
        $this->assertContains('mail_transport', $ids);
        $this->assertSame(count($report->findings), array_sum(array_filter($report->summary, 'is_int')));
    }

    /**
     * This endpoint's job is to report on a possibly broken installation. A
     * database that has gone away must not turn it into a 500 — that is the one
     * moment somebody needs to read it.
     */
    public function test_a_failing_mail_check_does_not_take_the_report_down(): void
    {
        $mailCheck = $this->createMock(\App\Modules\Notifications\Services\MailDeliveryCheck::class);
        $mailCheck->method('findings')->willThrowException(new \RuntimeException('the database went away'));

        $report = (new SecurityCheckService(new AppConfig(), $mailCheck))
            ->check(['DOCUMENT_ROOT' => sys_get_temp_dir()]);

        $this->assertNotEmpty($report->findings);
    }

    /** The backfill row is gone, and must not come back as a stale "unknown". */
    public function test_there_is_no_iban_backfill_finding_any_more(): void
    {
        $ids = array_map(static fn($finding) => $finding->id, $this->report()->findings);

        $this->assertNotContains('iban-encryption-backfill', $ids);
    }
}
