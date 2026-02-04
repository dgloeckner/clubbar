<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class SyncResultDto
{
    public function __construct(
        public array $items,
        public string $cursor,
        public bool $hasMore,
    ) {}

    public function toResponse(string $itemsKey = 'items'): array
    {
        return [
            $itemsKey => array_map(fn($item) => is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : $item, $this->items),
            'cursor' => $this->cursor,
            'has_more' => $this->hasMore,
        ];
    }
}
