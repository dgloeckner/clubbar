<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class PaginatedResultDto
{
    public function __construct(
        public array $items,
        public int $total,
        public int $limit,
        public int $offset,
    ) {}

    public function hasMore(): bool
    {
        return ($this->offset + $this->limit) < $this->total;
    }

    public function toArray(): array
    {
        $items = array_map(function ($item) {
            return is_object($item) && method_exists($item, 'toArray')
                ? $item->toArray()
                : (is_array($item) ? $item : (array) $item);
        }, $this->items);

        return [
            'items' => $items,
            'total' => $this->total,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'has_more' => $this->hasMore(),
        ];
    }
}
