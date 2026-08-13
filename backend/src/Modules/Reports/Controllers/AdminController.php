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
}
