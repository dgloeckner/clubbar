<?php

declare(strict_types=1);

namespace App\DTO;

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
        public ?string $mandateReference,
        public ?string $mandateSignedAt,
        public ?string $deletedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromRow(array $row): self
    {
        $iban = $row['iban'] ?? null;
        return new self(
            id: $row['id'],
            cardUid: $row['card_uid'] ?? null,
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            email: $row['email'],
            phone: $row['phone'] ?? null,
            preferredLanguage: $row['preferred_language'],
            isActive: (bool) $row['is_active'],
            isSepaValid: !empty($row['iban']) && !empty($row['mandate_reference']),
            ibanMasked: $iban ? substr($iban, 0, 2) . '****' . substr($iban, -4) : null,
            iban: $iban,
            mandateReference: $row['mandate_reference'] ?? null,
            mandateSignedAt: $row['mandate_signed_at'] ?? null,
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
            'email' => $this->email,
            'phone' => $this->phone,
            'preferred_language' => $this->preferredLanguage,
            'is_active' => $this->isActive,
            'is_sepa_valid' => $this->isSepaValid,
            'iban' => $this->iban,
            'iban_masked' => $this->ibanMasked,
            'mandate_reference' => $this->mandateReference,
            'mandate_signed_at' => $this->mandateSignedAt,
            'deleted_at' => $this->deletedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
