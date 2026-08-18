<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Modules\Reports\Domain\ReportGroupByPolicy;
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
        $groupBy = $params['group_by'] ?? 'month';

        // The parameter boundary of ADR-0044. The route is granted to all three
        // offices — it is how per-product sales figures are read — but
        // `group_by=member` makes every row a named member with their personal
        // spend, which is not the Getränkewart's business. Checked against the
        // roles `AdminSessionAuth` resolved, before the report is built.
        // Only a dimension that *exists* can be refused by role. A value the
        // reports do not have at all is a malformed request, and it stays the
        // 400 it has always been rather than becoming a 403 that tells an admin
        // their office is wrong when they have simply mistyped one.
        $roles = $request->getAttribute('admin_roles') ?? [];
        $known = in_array($groupBy, ReportGroupByPolicy::classifiedDimensions(), true);
        if ($known && !ReportGroupByPolicy::permits($roles, (string) $groupBy)) {
            return $this->json($response, [
                'error' => 'insufficient_role',
                'message' => 'This grouping is not available for your role.',
            ], 403);
        }

        try {
            $report = $this->reportsService->getReport(
                reportType: $reportType,
                dateFrom: $params['date_from'] ?? null,
                dateTo: $params['date_to'] ?? null,
                groupBy: $groupBy,
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
