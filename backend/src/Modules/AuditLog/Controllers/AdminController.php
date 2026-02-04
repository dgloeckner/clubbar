<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Controllers;

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

        $filters = [];
        if (isset($params['date_from'])) {
            $filters['date_from'] = $params['date_from'];
        }
        if (isset($params['date_to'])) {
            $filters['date_to'] = $params['date_to'];
        }
        if (isset($params['action'])) {
            $filters['action'] = $params['action'];
        }
        if (isset($params['entity_type'])) {
            $filters['entity_type'] = $params['entity_type'];
        }
        if (isset($params['admin_user_id'])) {
            $filters['admin_user_id'] = $params['admin_user_id'];
        }
        if (isset($params['search'])) {
            $filters['search'] = $params['search'];
        }

        $result = $this->auditLogRepository->listWithFilters($limit, $offset, $filters);

        return $this->json($response, [
            'items' => $result['items'],
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
