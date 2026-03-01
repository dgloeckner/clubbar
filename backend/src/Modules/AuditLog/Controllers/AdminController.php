<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Controllers;

use App\Modules\AuditLog\DTOs\AuditLogDto;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private AuditLogRepository $auditLogRepository,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);

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
        $sortDirection = $params['sort_direction'] ?? null;
        if ($sortDirection !== null) {
            $filters['sort_direction'] = $sortDirection;
        }

        $result = $this->auditLogRepository->listWithFilters($limit, $offset, $filters);

        // Convert raw database rows to DTOs (Backend Pattern 004)
        $items = array_map(
            fn($row) => AuditLogDto::fromRow($row)->toArray(),
            $result['items']
        );

        return $this->json($response, [
            'items' => $items,
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
