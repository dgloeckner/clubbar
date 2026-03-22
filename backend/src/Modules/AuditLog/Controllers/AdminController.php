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
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? $params['limit'] ?? 50);
        $offset = isset($params['offset']) ? (int) $params['offset'] : ($page - 1) * $perPage;

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

        $result = $this->auditLogRepository->listWithFilters($perPage, $offset, $filters);

        // Convert raw database rows to DTOs (Backend Pattern 004)
        $items = array_map(
            fn($row) => AuditLogDto::fromRow($row)->toArray(),
            $result['items']
        );

        $totalPages = $perPage > 0 ? (int) ceil($result['total'] / $perPage) : 1;
        return $this->json($response, [
            'data' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'total_pages' => $totalPages,
            ],
        ]);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
