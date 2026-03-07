<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Modules\Reports\Services\ReportsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
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

        $filename = "report-{$reportType}-" . date('Y-m-d') . '.csv';
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
            ->withStatus(200);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
