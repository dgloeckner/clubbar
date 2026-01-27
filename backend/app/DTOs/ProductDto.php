<?php

namespace App\DTOs;

use DateTimeImmutable;

/**
 * ProductDto
 *
 * Data transfer object for product data.
 * Encapsulates product information including multilingual names and descriptions.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
final readonly class ProductDto
{
    public function __construct(
        public string $id,
        public array $names,
        public array $descriptions,
        public int $priceCents,
        public string $categoryId,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $iconName = null,
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
            'descriptions' => $this->descriptions,
            'price_cents' => $this->priceCents,
            'category_id' => $this->categoryId,
            'is_active' => $this->isActive,
            'icon_name' => $this->iconName,
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
