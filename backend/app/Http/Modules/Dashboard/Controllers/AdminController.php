<?php

namespace App\Http\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\Dashboard\Services\DashboardService;
use Illuminate\Http\JsonResponse;

/**
 * Dashboard API Controller - Admin Dashboard Metrics
 *
 * Handles the dashboard endpoint for admin overview and metrics aggregation.
 * Read-only operation providing system-wide metrics and status.
 *
 * Implements Pattern 006: Thin Controllers
 * - Only HTTP routing and response handling
 * - All business logic delegated to service
 * - Response serialization via DTOs (Pattern 003)
 *
 * Endpoints:
 * - GET /api/admin/dashboard - Get aggregated dashboard metrics
 */
class AdminController extends Controller
{
    /**
     * Initialize controller with DashboardService.
     *
     * @param DashboardService $dashboardService
     */
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    /**
     * GET /api/admin/dashboard - Get Dashboard Metrics
     *
     * Returns aggregated metrics from all system modules:
     * - Business metrics (member counts, balances, revenue)
     * - Recent transactions (last 10)
     * - Terminal status with connectivity
     * - System status (settlements, totals, health)
     * - Admin alerts (SEPA issues, etc.)
     *
     * Read-only operation with no request parameters.
     * Response is point-in-time snapshot; clients refresh for latest data.
     *
     * @return JsonResponse Dashboard data (200 OK)
     */
    public function show(): JsonResponse
    {
        $dashboard = $this->dashboardService->getDashboardMetrics();

        return response()->json($dashboard->toArray());
    }
}
