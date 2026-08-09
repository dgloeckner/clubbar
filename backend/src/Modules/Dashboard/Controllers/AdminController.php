<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\DashboardService;
use App\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    public function __construct(
        private DashboardService $dashboardService,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        return $this->json($response, $this->dashboardService->getDashboard()->toArray());
    }

    /**
     * Monthly statistics for the statistics page.
     */
    public function monthlyStats(Request $request, Response $response): Response
    {
        $month = $request->getQueryParams()['month'] ?? date('Y-m');

        if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
            return $this->json($response, ['error' => 'Invalid month format. Use YYYY-MM.'], 400);
        }

        return $this->json($response, $this->dashboardService->getMonthlyStats($month));
    }
}
