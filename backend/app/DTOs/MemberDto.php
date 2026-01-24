<?php

namespace App\DTOs;

use DateTimeImmutable;

/**
 * MemberDto
 *
 * Data transfer object for member data.
 * Encapsulates member information for API responses.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
final readonly class MemberDto
{
    public function __construct(
        public string $id,
        public ?string $cardUid,
        public string $firstName,
        public string $lastName,
        public string $preferredLanguage,
        public bool $isActive,
        public bool $isSepaValid,
        public ?DateTimeImmutable $deletedAt,
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
            'card_uid' => $this->cardUid,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'preferred_language' => $this->preferredLanguage,
            'is_active' => $this->isActive,
            'is_sepa_valid' => $this->isSepaValid,
            'deleted_at' => $this->deletedAt?->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
