<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class CategoryDto
{
    public function __construct(
        public string $id,
        public array $names,
        public int $displayOrder,
        public bool $isActive,
        public ?string $iconName,
        public string $createdAt,
        public string $updatedAt,
        public ?int $productCount = null,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            names: is_string($row['names']) ? json_decode($row['names'], true) : $row['names'],
            displayOrder: (int) $row['display_order'],
            isActive: (bool) $row['is_active'],
            iconName: $row['icon_name'] ?? null,
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
            productCount: isset($row['product_count']) ? (int) $row['product_count'] : null,
        );
    }

    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'names' => $this->names,
            'display_order' => $this->displayOrder,
            'is_active' => $this->isActive,
            'icon_name' => $this->iconName,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
        if ($this->productCount !== null) {
            $data['product_count'] = $this->productCount;
        }
        return $data;
    }
}
