<?php

declare(strict_types=1);

namespace App\Modules\Registrations\DTOs;

/**
 * One pending submission, as the review screen is allowed to see it
 * (ADR-0052, UC-A17).
 *
 * This DTO is the boundary. `RegistrationsRepository` hands back the whole row
 * — it has to, because approval copies the sealed material across — and the
 * only thing standing between that row and an HTTP response is this class. So
 * the rule is stated once here and enforced by construction: **the ciphertext
 * and the fingerprint never leave the server.**
 *
 * The ciphertext because it is the sealed IBAN, and putting it in a JSON body
 * puts it in a browser cache, a proxy log and whatever the panel's own
 * devtools retained — for a value whose whole protection is that it exists in
 * exactly one place, under a key the server does not hold (ADR-0036).
 *
 * The fingerprint for a subtler reason: it is a *stable* identifier for a bank
 * account, keyed but deterministic. Given it, anyone holding a candidate IBAN
 * can confirm whether this applicant banks there. It is the right tool for the
 * duplicate check — which is why the check runs server-side and only its
 * `bool` answer travels.
 *
 * What an admin gets instead is `****3000`, which is what the paper in their
 * hand shows too, and is exactly enough to check that the two match.
 */
final readonly class PendingRegistrationDto
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public string $email,
        public ?string $phone,
        public string $dateOfBirth,
        public string $preferredLanguage,
        public ?string $accountHolderName,
        public string $mandateReference,
        public string $ibanMasked,
        public string $ibanLast4,
        public ?string $bankName,
        public string $privacyNoticeUrl,
        public string $privacyNoticeShownAt,
        public string $submittedAt,
        public string $expiresAt,
        /** Whether the club already has a member at this address. */
        public bool $duplicateEmail,
        /** Whether the club already has a mandate on this account. */
        public bool $duplicateIban,
    ) {}

    /**
     * @param array<string, mixed> $row
     * @param array{email?: bool, iban?: bool} $duplicates decided by the
     *        service against `members`/`mandates`; absent means "not checked",
     *        which reads as no flag rather than as a flag nobody set
     */
    public static function fromRow(array $row, array $duplicates = []): self
    {
        $last4 = (string) ($row['iban_last4'] ?? '');

        return new self(
            id: (string) $row['id'],
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            email: (string) $row['email'],
            phone: self::nullableString($row['phone'] ?? null),
            dateOfBirth: (string) $row['date_of_birth'],
            preferredLanguage: (string) $row['preferred_language'],
            accountHolderName: self::nullableString($row['account_holder_name'] ?? null),
            mandateReference: (string) $row['mandate_reference'],
            ibanMasked: '****' . $last4,
            ibanLast4: $last4,
            bankName: self::nullableString($row['bank_name'] ?? null),
            privacyNoticeUrl: (string) $row['privacy_notice_url'],
            privacyNoticeShownAt: (string) $row['privacy_notice_shown_at'],
            submittedAt: (string) $row['submitted_at'],
            expiresAt: (string) $row['expires_at'],
            duplicateEmail: (bool) ($duplicates['email'] ?? false),
            duplicateIban: (bool) ($duplicates['iban'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone,
            'date_of_birth' => $this->dateOfBirth,
            'preferred_language' => $this->preferredLanguage,
            'account_holder_name' => $this->accountHolderName,
            'mandate_reference' => $this->mandateReference,
            'iban_masked' => $this->ibanMasked,
            'iban_last4' => $this->ibanLast4,
            'bank_name' => $this->bankName,
            // Which document this person was pointed at, and when. Not
            // decoration: it is the club's evidence that the notice was shown
            // before any of the data above was collected, and the document at
            // that URL can change afterwards.
            'privacy_notice_url' => $this->privacyNoticeUrl,
            'privacy_notice_shown_at' => $this->privacyNoticeShownAt,
            'submitted_at' => $this->submittedAt,
            'expires_at' => $this->expiresAt,
            'duplicate_email' => $this->duplicateEmail,
            'duplicate_iban' => $this->duplicateIban,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
