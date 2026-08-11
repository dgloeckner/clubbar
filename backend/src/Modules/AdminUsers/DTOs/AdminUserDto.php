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
        public bool $totpEnabled,
        public ?string $lastLoginAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            email: $row['email'],
            displayName: $row['display_name'] ?: $row['email'],
            locale: $row['locale'] ?? 'de',
            isActive: (bool) $row['is_active'],
            totpEnabled: (bool) ($row['totp_enabled'] ?? false),
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
            'totp_enabled' => $this->totpEnabled,
            'last_login_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->lastLoginAt),
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'updated_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->updatedAt),
        ];
    }
}
