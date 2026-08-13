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
        public ?string $ibanLast4,
        public ?string $accountHolderName,
        public ?string $mandateReference,
        public ?string $mandateSignedAt,
        public ?string $bankName,
        public ?string $deletedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * The stored IBAN is sealed (ADR-0035); the API exposes only its last
     * four characters — enough to recognize the account, useless to debit it.
     * `iban_masked` keeps its established shape (`****3000`) for display.
     */
    public static function fromRow(array $row): self
    {
        $last4 = $row['iban_last4'] ?? null;
        return new self(
            id: $row['id'],
            cardUid: $row['card_uid'] ?? null,
            firstName: $row['first_name'] ?? null,
            lastName: $row['last_name'] ?? null,
            email: $row['email'] ?? null,
            phone: $row['phone'] ?? null,
            preferredLanguage: $row['preferred_language'],
            isActive: (bool) $row['is_active'],
            isSepaValid: !empty($row['has_iban']) && !empty($row['mandate_reference']),
            ibanMasked: $last4 ? '****' . $last4 : null,
            ibanLast4: $last4,
            accountHolderName: $row['account_holder_name'] ?? null,
            mandateReference: $row['mandate_reference'] ?? null,
            mandateSignedAt: $row['mandate_signed_at'] ?? null,
            bankName: $row['bank_name'] ?? null,
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
            'iban_last4' => $this->ibanLast4,
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
