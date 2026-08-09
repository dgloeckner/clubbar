<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Services;

use App\Modules\AuditLog\DTOs\AuditLogDto;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Shared\Http\ListQuery;

/**
 * Reading the audit trail (Pattern 004).
 *
 * The controller used to reach for the repository and map the rows itself,
 * which put "a page of the audit log is repository rows turned into DTOs" in
 * the HTTP layer where nothing else could reuse it. Writing to the trail is a
 * different concern and lives in {@see \App\Shared\Services\AuditService}.
 */
class AuditLogService
{
    public function __construct(
        private AuditLogRepository $auditLogRepository,
    ) {}

    /**
     * One page of audit entries, already presented.
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(ListQuery $query, array $filters = []): array
    {
        $result = $this->auditLogRepository->listWithFilters(
            $query->perPage,
            $query->offset,
            $filters,
            $query->sortKey,
            $query->sortOrder,
        );

        return [
            'items' => array_map(
                static fn(array $row): array => AuditLogDto::fromRow($row)->toArray(),
                $result['items'],
            ),
            'total' => (int) $result['total'],
        ];
    }
}
