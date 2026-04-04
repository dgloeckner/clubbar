<?php

declare(strict_types=1);

namespace App\Modules\Members\DTOs;

final readonly class MemberAdminDto
{
    public function __construct(
        public string $id,
        public ?string $cardUid,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $email,
        public ?string $phone,
        public string $preferredLanguage,
        public bool $isActive,
        public bool $isSepaValid,
        public ?string $ibanMasked,
        public ?string $iban,
        public ?string $accountHolderName,
        public ?string $mandateReference,
        public ?string $mandateSignedAt,
        public ?string $bankName,
        public ?string $deletedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromRow(array $row, ?string $bankName = null): self
    {
        $iban = $row['iban'] ?? null;
        return new self(
            id: $row['id'],
            cardUid: $row['card_uid'] ?? null,
            firstName: $row['first_name'] ?? null,
            lastName: $row['last_name'] ?? null,
            email: $row['email'] ?? null,
            phone: $row['phone'] ?? null,
            preferredLanguage: $row['preferred_language'],
            isActive: (bool) $row['is_active'],
            isSepaValid: !empty($row['iban']) && !empty($row['mandate_reference']),
            ibanMasked: $iban ? substr($iban, 0, 2) . '****' . substr($iban, -4) : null,
            iban: $iban,
            accountHolderName: $row['account_holder_name'] ?? null,
            mandateReference: $row['mandate_reference'] ?? null,
            mandateSignedAt: $row['mandate_signed_at'] ?? null,
            bankName: $bankName,
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
            'account_holder_name' => $this->accountHolderName,
            'mandate_reference' => $this->mandateReference,
            'mandate_signed_at' => $this->mandateSignedAt, // DATE-only field, no timezone needed
            'bank_name' => $this->bankName,
            'deleted_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->deletedAt),
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'updated_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->updatedAt),
        ];
    }
}
