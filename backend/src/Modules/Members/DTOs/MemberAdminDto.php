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
        public ?string $dateOfBirth,
        public string $preferredLanguage,
        public ?int $creditLimitCents,
        public bool $isActive,
        public bool $isSepaValid,
        public ?string $ibanMasked,
        public ?string $ibanLast4,
        public ?string $accountHolderName,
        public ?string $mandateReference,
        public ?string $mandateSignedAt,
        public ?string $bankName,
        public int $balanceCents,
        public ?string $deletedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * The stored IBAN is sealed (ADR-0036); the API exposes only its last
     * four characters — enough to recognize the account, useless to debit it.
     * `iban_masked` keeps its established shape (`****3000`) for display.
     *
     * `balance_cents` is the member's Deckel, summed by the repository over
     * their unsettled transactions (#371). It defaults to zero only so that a
     * row assembled by hand — a test fixture, say — still builds; every query
     * that feeds this DTO selects the column.
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
            dateOfBirth: $row['date_of_birth'] ?? null,
            phone: $row['phone'] ?? null,
            preferredLanguage: $row['preferred_language'],
            // Left null rather than defaulted: NULL is this member following
            // the club default, and 0 is a deliberate absence of a ceiling —
            // two different answers (ADR-0046 decision 3).
            creditLimitCents: isset($row['credit_limit_cents']) ? (int) $row['credit_limit_cents'] : null,
            isActive: (bool) $row['is_active'],
            isSepaValid: !empty($row['has_iban']) && !empty($row['mandate_reference']),
            ibanMasked: $last4 ? '****' . $last4 : null,
            ibanLast4: $last4,
            accountHolderName: $row['account_holder_name'] ?? null,
            mandateReference: $row['mandate_reference'] ?? null,
            mandateSignedAt: $row['mandate_signed_at'] ?? null,
            bankName: $row['bank_name'] ?? null,
            balanceCents: (int) ($row['balance_cents'] ?? 0),
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
            // DATE-only, like mandate_signed_at: no timezone to shift it a day.
            // NULL means the member has been anonymized (ADR-0045 rule 3).
            'date_of_birth' => $this->dateOfBirth,
            'preferred_language' => $this->preferredLanguage,
            // This member's own ceiling. `null` = follow the club default,
            // `0` = no ceiling for them (ADR-0046).
            'credit_limit_cents' => $this->creditLimitCents,
            'is_active' => $this->isActive,
            'is_sepa_valid' => $this->isSepaValid,
            'iban_last4' => $this->ibanLast4,
            'iban_masked' => $this->ibanMasked,
            'account_holder_name' => $this->accountHolderName,
            'mandate_reference' => $this->mandateReference,
            'mandate_signed_at' => $this->mandateSignedAt, // DATE-only field, no timezone needed
            'bank_name' => $this->bankName,
            'balance_cents' => $this->balanceCents,
            'deleted_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->deletedAt),
            'created_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->createdAt),
            'updated_at' => \App\Shared\Utils\DateFormatter::toUtcIso($this->updatedAt),
        ];
    }
}
