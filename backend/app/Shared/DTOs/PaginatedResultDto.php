<?php

namespace App\Shared\DTOs;

/**
 * PaginatedResultDto - Standardized Pagination Response
 *
 * Wraps paginated results with metadata for consistent API responses
 * across all modules.
 *
 * Pattern 003: Data Transfer Objects
 * All module list endpoints return data wrapped in this DTO.
 *
 * Usage:
 * ```php
 * $members = $this->repository->query()->paginate(10, ['*'], 'page', 1);
 * $dto = new PaginatedResultDto(
 *     items: $members->items(),
 *     total: $members->total(),
 *     limit: $members->perPage(),
 *     offset: ($members->currentPage() - 1) * $members->perPage(),
 * );
 *
 * return response()->json($dto->toArray());
 * ```
 */
readonly final class PaginatedResultDto
{
    /**
     * @param array $items The page items (already transformed to DTOs)
     * @param int $total Total count of all items (not just this page)
     * @param int $limit Items per page
     * @param int $offset Starting position (0-based)
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $limit,
        public int $offset,
    ) {}

    /**
     * Check if more items exist beyond this page.
     *
     * @return bool
     */
    public function has_more(): bool
    {
        return ($this->offset + $this->limit) < $this->total;
    }

    /**
     * Serialize to API response array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'total' => $this->total,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'has_more' => $this->has_more(),
        ];
    }
}
