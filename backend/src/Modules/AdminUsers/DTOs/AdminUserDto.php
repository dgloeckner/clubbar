<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\DTOs;

final readonly class AdminUserDto
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public string $locale,
        public bool $isActive,
        public ?string $lastLoginAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            email: $row['email'],
            displayName: $row['display_name'],
            locale: $row['locale'] ?? 'de',
            isActive: (bool) $row['is_active'],
            lastLoginAt: $row['last_login_at'] ?? null,
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'display_name' => $this->displayName,
            'locale' => $this->locale,
            'is_active' => $this->isActive,
            'last_login_at' => $this->lastLoginAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
