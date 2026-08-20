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
        public ?int $minAge,
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
            // Left null rather than cast: NULL means the product carries no
            // legal age at all (ADR-0045), which is not the same as zero.
            minAge: isset($row['min_age']) ? (int) $row['min_age'] : null,
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
            'requires_dispenser' => $this->requiresDispenser,
            'icon_name' => $this->iconName,
            // The minimum age a member must have reached to buy this product;
            // null means unrestricted (ADR-0045).
            'min_age' => $this->minAge,
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'updated_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->updatedAt),
            'deleted_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->deletedAt),
        ];
    }
}
