<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reports\Controllers;

use App\Modules\Reports\Controllers\AdminController;
use App\Modules\Reports\Services\ReportsService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The report export endpoints (#94).
 *
 * The admin panel used to ask the *list* endpoints for `format=csv`, a
 * parameter nothing read, and saved the JSON answer under a .csv name. Every
 * report now has an /export sibling, and what it returns has to be announced
 * as a CSV download rather than as JSON.
 */
class AdminControllerExportTest extends TestCase
{
    private ReportsService $service;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(ReportsService::class);
        $this->controller = new AdminController($this->service);
    }

    /**
     * @param array<string, string> $query
     */
    private function request(string $path, array $query = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withQueryParams($query);
    }

    private function body(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }

    private function assertCsvDownload(ResponseInterface $response, string $filenameStem): void
    {
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame(
            'attachment; filename="' . $filenameStem . '-' . date('Y-m-d') . '.csv"',
            $response->getHeaderLine('Content-Disposition')
        );
    }

    // ── Member ranking ──────────────────────────────────────────────────────

    public function test_it_returns_the_member_ranking_as_a_csv_download(): void
    {
        $this->service->method('exportMemberRankingCsv')->willReturn("Rank;Member\n1;Anna\n");

        $response = $this->controller->exportMemberRanking(
            $this->request('/api/admin/reports/member-ranking/export'),
            new Response()
        );

        $this->assertCsvDownload($response, 'report-member-ranking');
        $this->assertSame("Rank;Member\n1;Anna\n", $this->body($response));
    }

    public function test_it_passes_the_ranking_filters_through(): void
    {
        $this->service->expects($this->once())
            ->method('exportMemberRankingCsv')
            ->with('2026-01-01', '2026-01-31', true, 50)
            ->willReturn('');

        $this->controller->exportMemberRanking(
            $this->request('/api/admin/reports/member-ranking/export', [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
                'anonymize' => 'true',
                'limit' => '50',
            ]),
            new Response()
        );
    }

    public function test_the_ranking_export_defaults_to_named_members_and_the_default_limit(): void
    {
        $this->service->expects($this->once())
            ->method('exportMemberRankingCsv')
            ->with(null, null, false, 25)
            ->willReturn('');

        $this->controller->exportMemberRanking(
            $this->request('/api/admin/reports/member-ranking/export'),
            new Response()
        );
    }

    // ── Terminal activity ───────────────────────────────────────────────────

    public function test_it_returns_terminal_activity_as_a_csv_download(): void
    {
        $this->service->method('exportTerminalActivityCsv')->willReturn("Sessions\nDate\n");

        $response = $this->controller->exportTerminalActivity(
            $this->request('/api/admin/reports/terminal-activity/export', [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
            ]),
            new Response()
        );

        $this->assertCsvDownload($response, 'report-terminal-activity');
        $this->assertSame("Sessions\nDate\n", $this->body($response));
    }

    public function test_it_passes_the_terminal_filter_through(): void
    {
        $this->service->expects($this->once())
            ->method('exportTerminalActivityCsv')
            ->with('2026-01-01', '2026-01-31', 'terminal-7')
            ->willReturn('');

        $this->controller->exportTerminalActivity(
            $this->request('/api/admin/reports/terminal-activity/export', [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
                'terminal_id' => 'terminal-7',
            ]),
            new Response()
        );
    }

    public function test_the_terminal_activity_export_rejects_a_missing_date_range(): void
    {
        $this->service->expects($this->never())->method('exportTerminalActivityCsv');

        $response = $this->controller->exportTerminalActivity(
            $this->request('/api/admin/reports/terminal-activity/export', ['date_from' => '2026-01-01']),
            new Response()
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('date_from and date_to are required', $this->body($response));
    }

    // ── Standard reports keep their existing behaviour ──────────────────────

    public function test_the_standard_report_export_still_announces_its_report_type(): void
    {
        $this->service->method('exportCsv')->willReturn("Dimension\n");

        $response = $this->controller->exportReport(
            $this->request('/api/admin/reports/revenue/export'),
            new Response(),
            ['reportType' => 'revenue']
        );

        $this->assertCsvDownload($response, 'report-revenue');
    }

    public function test_an_unknown_report_type_is_a_validation_error(): void
    {
        $this->service->method('exportCsv')
            ->willThrowException(new \InvalidArgumentException('Invalid report type: nope'));

        $response = $this->controller->exportReport(
            $this->request('/api/admin/reports/nope/export'),
            new Response(),
            ['reportType' => 'nope']
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('Invalid report type: nope', $this->body($response));
    }
}
