<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Controllers;

use App\Modules\AuditLog\DTOs\AuditLogDto;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    public function __construct(
        private AuditLogRepository $auditLogRepository,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = ListQuery::fromParams($params);

        $nested = $params['filters'] ?? [];

        $filters = [];
        $dateFrom = $nested['date_from'] ?? $params['date_from'] ?? null;
        if ($dateFrom !== null) {
            $filters['date_from'] = $dateFrom;
        }
        $dateTo = $nested['date_to'] ?? $params['date_to'] ?? null;
        if ($dateTo !== null) {
            $filters['date_to'] = $dateTo;
        }
        $action = $nested['action'] ?? $params['action'] ?? null;
        if ($action !== null) {
            $filters['action'] = $action;
        }
        $entityType = $nested['entity_type'] ?? $params['entity_type'] ?? null;
        if ($entityType !== null) {
            $filters['entity_type'] = $entityType;
        }
        $adminUserId = $nested['admin_user_id'] ?? $params['admin_user_id'] ?? null;
        if ($adminUserId !== null) {
            $filters['admin_user_id'] = $adminUserId;
        }
        $search = $nested['search'] ?? $params['search'] ?? null;
        if ($search !== null) {
            $filters['search'] = $search;
        }
        $entityId = $nested['entity_id'] ?? $params['entity_id'] ?? null;
        if ($entityId !== null) {
            $filters['entity_id'] = $entityId;
        }

        $result = $this->auditLogRepository->listWithFilters($query->perPage, $query->offset, $filters);

        // Convert raw database rows to DTOs (Backend Pattern 004)
        $items = array_map(
            fn($row) => AuditLogDto::fromRow($row)->toArray(),
            $result['items']
        );

        return $this->json($response, PaginatedResponse::fromQuery($items, (int) $result['total'], $query));
    }
}
