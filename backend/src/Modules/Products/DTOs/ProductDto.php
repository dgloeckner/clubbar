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
        public bool $requiresDispenser,
        public ?string $iconName,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt = null,
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
            requiresDispenser: (bool) $row['requires_dispenser'],
            iconName: $row['icon_name'] ?? null,
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
            deletedAt: $row['deleted_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'names' => $this->names ?: new \stdClass(),
            'descriptions' => $this->descriptions ?: new \stdClass(),
            'price_cents' => $this->priceCents,
            'category_id' => $this->categoryId,
            'is_active' => $this->isActive,
            'requires_dispenser' => $this->requiresDispenser ? 1 : 0,
            'icon_name' => $this->iconName,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }
}
