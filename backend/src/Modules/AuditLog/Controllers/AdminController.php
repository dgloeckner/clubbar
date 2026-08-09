<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Controllers;

use App\Modules\AuditLog\Services\AuditLogService;
use App\Shared\Http\JsonResponder;
use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    use JsonResponder;

    /** Filters the endpoint accepts, nested under `filters[…]` or at the top level. */
    private const FILTER_KEYS = [
        'date_from',
        'date_to',
        'action',
        'entity_type',
        'admin_user_id',
        'search',
        'entity_id',
    ];

    public function __construct(
        private AuditLogService $auditLogService,
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = ListQuery::fromParams($params);

        $result = $this->auditLogService->list($query, $this->filtersFrom($params));

        return $this->json($response, PaginatedResponse::fromQuery($result['items'], $result['total'], $query));
    }

    /**
     * Both spellings have to keep working: `filters[action]=…` from the admin
     * panel and the flat `action=…` older clients send.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function filtersFrom(array $params): array
    {
        $nested = $params['filters'] ?? [];
        $nested = is_array($nested) ? $nested : [];

        $filters = [];
        foreach (self::FILTER_KEYS as $key) {
            $value = $nested[$key] ?? $params[$key] ?? null;
            if ($value !== null) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }
}
