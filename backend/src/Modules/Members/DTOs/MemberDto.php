<?php

declare(strict_types=1);

namespace App\Modules\Members\DTOs;

/**
 * A member as an offline terminal sees them.
 *
 * Deliberately narrower than `MemberAdminDto`: no contact details, no banking
 * data, no Deckel. The kiosk needs to recognize a card, greet a person, decide
 * whether they may transact at all, and — since ADR-0045 — whether they are old
 * enough for what is in the cart.
 */
final readonly class MemberDto
{
    public function __construct(
        public string $id,
        public ?string $cardUid,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $dateOfBirth,
        public string $preferredLanguage,
        public bool $isActive,
        public bool $isSepaValid,
        public ?string $deletedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            cardUid: $row['card_uid'] ?? null,
            firstName: $row['first_name'] ?? null,
            dateOfBirth: $row['date_of_birth'] ?? null,
            lastName: $row['last_name'] ?? null,
            preferredLanguage: $row['preferred_language'],
            isActive: (bool) $row['is_active'],
            isSepaValid: !empty($row['has_iban']) && !empty($row['mandate_reference']),
            deletedAt: $row['deleted_at'] ?? null,
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'card_uid' => $this->cardUid,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            // The raw date, not an age (ADR-0045 decision 1). Age changes on a
            // day the server cannot predict a sync for, so anything derived is
            // silently wrong from the member's next birthday until the next
            // sync — and wrong about exactly the member who will notice. The
            // terminal computes the age itself, at checkout.
            //
            // This is the one field that puts personal data on a kiosk. What
            // takes it back off is erasure riding this same delta: an
            // anonymized row arrives with the value nulled, on the ordinary
            // cursor, with no new mechanism.
            'date_of_birth' => $this->dateOfBirth,
            'preferred_language' => $this->preferredLanguage,
            'is_active' => $this->isActive,
            'is_sepa_valid' => $this->isSepaValid,
            'deleted_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->deletedAt),
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'updated_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->updatedAt),
        ];
    }
}
