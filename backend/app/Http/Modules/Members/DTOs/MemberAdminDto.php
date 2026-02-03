<?php

namespace App\Http\Modules\Members\DTOs;

use DateTimeImmutable;

/**
 * MemberAdminDto
 *
 * Extended DTO for member data in admin API responses.
 * Includes additional fields: email, phone, IBAN (masked), SEPA validity status.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
final readonly class MemberAdminDto
{
    public function __construct(
        public string $id,
        public ?string $cardUid,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public string $preferredLanguage,
        public bool $isActive,
        public bool $isSepaValid,
        public ?string $ibanMasked,
        public ?string $iban,
        public ?string $accountHolderName,
        public ?string $mandateReference,
        public ?string $mandateSignedAt,
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
            'email' => $this->email,
            'phone' => $this->phone,
            'preferred_language' => $this->preferredLanguage,
            'is_active' => $this->isActive,
            'is_sepa_valid' => $this->isSepaValid,
            'iban' => $this->iban,
            'iban_masked' => $this->ibanMasked,
            'account_holder_name' => $this->accountHolderName,
            'mandate_reference' => $this->mandateReference,
            'mandate_signed_at' => $this->mandateSignedAt,
            'deleted_at' => $this->deletedAt?->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
