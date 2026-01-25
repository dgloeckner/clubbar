<?php

namespace App\Http\Modules\AuditLog\Services;

use App\Http\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Shared\DTOs\PaginatedResultDto;

/**
 * AuditLogService - Business Logic for Audit Log Queries
 *
 * Implements Pattern 004: Service Layer
 * Orchestrates audit log retrieval and filtering.
 */
final readonly class AuditLogService
{
    /**
     * Initialize service with audit log repository
     *
     * @param AuditLogRepository $auditLogRepository
     */
    public function __construct(
        private AuditLogRepository $auditLogRepository,
    ) {}

    /**
     * List audit log entries with pagination and filters
     *
     * @param int $limit Items per page
     * @param int $offset Starting position
     * @param array $filters Filter criteria
     * @return PaginatedResultDto
     */
    public function listAuditLogs(int $limit, int $offset, array $filters = []): PaginatedResultDto
    {
        // Fetch from repository
        $result = $this->auditLogRepository->listWithFilters($limit, $offset, $filters);

        // Transform to paginated result
        return new PaginatedResultDto(
            items: array_map(fn ($dto) => $dto->toArray(), $result['items']),
            total: $result['total'],
            limit: $limit,
            offset: $offset,
        );
    }
}
