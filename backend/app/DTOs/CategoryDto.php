<?php

namespace App\DTOs;

use DateTimeImmutable;

/**
 * CategoryDto
 *
 * Data transfer object for product category data.
 * Encapsulates category information including multilingual names.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
final readonly class CategoryDto
{
    public function __construct(
        public string $id,
        public array $names,
        public int $displayOrder,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Convert to array for JSON serialization
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'names' => $this->names,
            'display_order' => $this->displayOrder,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
