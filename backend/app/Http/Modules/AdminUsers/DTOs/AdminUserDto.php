<?php

namespace App\Http\Modules\AdminUsers\DTOs;

/**
 * AdminUserDto - Data Transfer Object for Admin User Responses
 *
 * Encapsulates admin user information for API responses.
 * Never exposes password_hash field.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
final readonly class AdminUserDto
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public string $locale,
        public bool $isActive,
        public ?\DateTime $lastLoginAt = null,
        public ?\DateTime $createdAt = null,
        public ?\DateTime $updatedAt = null,
    ) {}

    /**
     * Convert to array for JSON serialization
     *
     * Response format per OpenAPI spec.
     * Note: password_hash is never included in responses.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'display_name' => $this->displayName,
            'locale' => $this->locale,
            'is_active' => $this->isActive,
            'last_login_at' => $this->lastLoginAt?->toIso8601String(),
            'created_at' => $this->createdAt?->toIso8601String(),
            'updated_at' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
