<?php

namespace App\DTOs;

/**
 * SyncResultDto
 *
 * Data transfer object for paginated sync results.
 * Encapsulates collection of items with cursor pagination.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
final readonly class SyncResultDto
{
    public function __construct(
        public array $items,
        public string $cursor,
        public bool $hasMore,
    ) {}

    /**
     * Convert to response array with specified items key
     *
     * @param string $itemsKey The key name for items in response (e.g., 'members', 'categories')
     * @return array
     */
    public function toResponse(string $itemsKey): array
    {
        return [
            $itemsKey => array_map(fn($item) => $item->toArray(), $this->items),
            'cursor' => $this->cursor,
            'count' => count($this->items),
            'has_more' => $this->hasMore,
        ];
    }
}
