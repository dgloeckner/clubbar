<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\DTOs;

use App\Modules\AdminUsers\Enums\AdminRole;

final readonly class AdminUserDto
{
    /**
     * @param list<AdminRole> $roles The account's roles (ADR-0044). Empty when
     *  the caller did not resolve them — a list endpoint that answers one row
     *  per account without them is honest about it, rather than implying the
     *  account holds none.
     */
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
        public array $roles = [],
    ) {}

    /** @param list<AdminRole> $roles */
    public static function fromRow(array $row, array $roles = []): self
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
            roles: $roles,
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
            'roles' => AdminRole::toValues($this->roles),
            'last_login_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->lastLoginAt),
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'updated_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->updatedAt),
        ];
    }
}
