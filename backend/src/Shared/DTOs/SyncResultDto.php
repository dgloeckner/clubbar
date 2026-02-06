<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

final readonly class SyncResultDto
{
    public function __construct(
        public array $items,
        public int $cursor,
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

    /**
     * Convert MySQL datetime string (YYYY-MM-DD HH:MM:SS) to Unix timestamp in milliseconds.
     */
    public static function dateToTimestamp(?string $dateStr): int
    {
        if ($dateStr === null) {
            return (int) (microtime(true) * 1000);
        }
        // Parse MySQL datetime format and convert to milliseconds
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $dateStr);
        return $dt ? $dt->getTimestamp() * 1000 : (int) (microtime(true) * 1000);
    }
}
