<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Modules\Reports\Services\ReportsService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    public function __construct(private ReportsService $reportsService) {}

    /**
     * GET /api/admin/reports/{reportType}
     */
    public function getReport(Request $request, Response $response, array $args): Response
    {
        $reportType = $args['reportType'] ?? '';
        $params = $request->getQueryParams();

        try {
            $report = $this->reportsService->getReport(
                reportType: $reportType,
                dateFrom: $params['date_from'] ?? null,
                dateTo: $params['date_to'] ?? null,
                groupBy: $params['group_by'] ?? 'month',
                categoryIds: $params['category_ids'] ?? null,
                productIds: $params['product_ids'] ?? null,
                page: (int) ($params['page'] ?? 1),
                perPage: (int) ($params['per_page'] ?? 25),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        return $this->json($response, $report->toArray());
    }

    /**
     * GET /api/admin/reports/{reportType}/export
     */
    public function exportReport(Request $request, Response $response, array $args): Response
    {
        $reportType = $args['reportType'] ?? '';
        $params = $request->getQueryParams();

        try {
            $csv = $this->reportsService->exportCsv(
                reportType: $reportType,
                dateFrom: $params['date_from'] ?? null,
                dateTo: $params['date_to'] ?? null,
                groupBy: $params['group_by'] ?? 'month',
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 400);
        }

        return $this->csv($response, $csv, "report-{$reportType}");
    }

    /**
     * GET /api/admin/reports/member-ranking
     */
    public function memberRanking(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        $data = $this->reportsService->getMemberRanking(
            dateFrom: $params['date_from'] ?? null,
            dateTo: $params['date_to'] ?? null,
            limit: (int) ($params['limit'] ?? 25),
        );

        return $this->json($response, $data);
    }

    /**
     * GET /api/admin/reports/terminal-activity
     */
    public function terminalActivity(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;

        if (!$dateFrom || !$dateTo) {
            return $this->json($response, ['error' => 'date_from and date_to are required'], 400);
        }

        $data = $this->reportsService->getTerminalActivity(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            terminalId: $params['terminal_id'] ?? null,
        );

        return $this->json($response, $data);
    }

    /**
     * GET /api/admin/reports/member-ranking/export
     */
    public function exportMemberRanking(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();

        $csv = $this->reportsService->exportMemberRankingCsv(
            dateFrom: $params['date_from'] ?? null,
            dateTo: $params['date_to'] ?? null,
            limit: (int) ($params['limit'] ?? 25),
        );

        return $this->csv($response, $csv, 'report-member-ranking');
    }

    /**
     * GET /api/admin/reports/terminal-activity/export
     */
    public function exportTerminalActivity(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;

        if (!$dateFrom || !$dateTo) {
            return $this->json($response, ['error' => 'date_from and date_to are required'], 400);
        }

        $csv = $this->reportsService->exportTerminalActivityCsv(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            terminalId: $params['terminal_id'] ?? null,
        );

        return $this->csv($response, $csv, 'report-terminal-activity');
    }

    /**
     * Write a CSV body with the download headers the browser needs.
     */
    private function csv(Response $response, string $csv, string $filenameStem): Response
    {
        $filename = $filenameStem . '-' . date('Y-m-d') . '.csv';
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->withStatus(200);
    }
}
