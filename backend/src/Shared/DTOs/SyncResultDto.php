<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class SyncResultDto
{
    public function __construct(
        public array $items,
        public string $cursor,
        public bool $hasMore,
    ) {}

    public function toArray(string $itemsKey = 'items'): array
    {
        $mappedItems = array_map(fn($item) => is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : $item, $this->items);
        return [
            $itemsKey => $mappedItems,
            'cursor' => $this->cursor,
            'count' => count($mappedItems),
            'has_more' => $this->hasMore,
        ];
    }
}
