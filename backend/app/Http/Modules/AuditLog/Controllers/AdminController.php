<?php

namespace App\Http\Modules\AuditLog\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Modules\AuditLog\Requests\AuditLogListRequest;
use App\Http\Modules\AuditLog\Services\AuditLogService;
use Illuminate\Http\JsonResponse;

/**
 * Admin API Controller - Audit Log Module
 *
 * Handles audit log viewer endpoint for compliance and accountability.
 *
 * Implements Pattern 006: Thin Controllers
 * - Only HTTP routing and request/response handling
 * - All business logic delegated to AuditLogService
 * - Request validation via FormRequest (Pattern 001)
 * - Response serialization via DTOs (Pattern 003)
 *
 * Endpoints:
 * - GET /api/admin/audit-log - List audit log entries with filters
 */
class AdminController extends Controller
{
    /**
     * Initialize controller with audit log service
     *
     * Service injected by Laravel's service container.
     * Service is bound in AppServiceProvider.
     *
     * @param AuditLogService $auditLogService
     */
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * GET /api/admin/audit-log - List Audit Log Entries
     *
     * Returns paginated audit log entries with optional filters.
     *
     * Query Parameters:
     * - limit: int (1-100, default 50)
     * - offset: int (default 0)
     * - filters[date_from]: string (ISO 8601 date, optional)
     * - filters[date_to]: string (ISO 8601 date, optional)
     * - filters[action]: string (action type, optional)
     * - filters[entity_type]: string (entity type, optional)
     * - filters[admin_user_id]: string (UUID, optional)
     * - filters[search]: string (free text search, optional)
     *
     * @param AuditLogListRequest $request Validated request
     * @return JsonResponse Paginated audit log entries
     */
    public function index(AuditLogListRequest $request): JsonResponse
    {
        // Get pagination parameters
        $limit = $request->limit();
        $offset = $request->offset();
        $filters = $request->filters();

        // Delegate to service (Pattern 004: Service Layer)
        $result = $this->auditLogService->listAuditLogs($limit, $offset, $filters);

        // Serialize result to JSON (Pattern 003: DTOs)
        return response()->json($result->toArray());
    }
}
