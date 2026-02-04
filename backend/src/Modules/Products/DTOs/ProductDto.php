<?php

declare(strict_types=1);

namespace App\Modules\Products\DTOs;

final readonly class ProductDto
{
    public function __construct(
        public string $id,
        public array $names,
        public array $descriptions,
        public int $priceCents,
        public string $categoryId,
        public bool $isActive,
        public ?string $iconName,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            names: is_string($row['names']) ? json_decode($row['names'], true) : $row['names'],
            descriptions: is_string($row['descriptions'] ?? '{}') ? json_decode($row['descriptions'] ?? '{}', true) : ($row['descriptions'] ?? []),
            priceCents: (int) $row['price_cents'],
            categoryId: $row['category_id'],
            isActive: (bool) $row['is_active'],
            iconName: $row['icon_name'] ?? null,
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }

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
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
