<?php

namespace App\Http\Modules\Terminals\DTOs;

/**
 * TerminalListDto - Paginated Terminal List Response
 *
 * Wraps array of TerminalDto objects with pagination metadata.
 * Used for GET /api/admin/terminals endpoint.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
final readonly class TerminalListDto
{
    /**
     * @param array $data Array of TerminalDto objects
     * @param array $pagination Pagination metadata (total, per_page, current_page, last_page)
     */
    public function __construct(
        public array $data,
        public array $pagination,
    ) {}

    /**
     * Convert to array for JSON serialization.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(fn($dto) => $dto->toArray(), $this->data),
            'pagination' => $this->pagination,
        ];
    }
}
