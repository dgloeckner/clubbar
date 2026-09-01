<?php

declare(strict_types=1);

namespace App\Modules\Registrations\DTOs;

/**
 * What the public endpoint hands back.
 *
 * Deliberately thin, and deliberately not a view of the stored row. The
 * endpoint is write-only (ADR-0052 decision 3): it answers with what the
 * applicant needs to carry on — the reference printed on their paper, and the
 * document itself, which #780 adds — and never with anything it read back.
 *
 * The same shape is returned whether or not the club already knows this person,
 * which is what makes the endpoint useless for asking questions about
 * membership (decision 9).
 */
final readonly class RegistrationReceiptDto
{
    public function __construct(
        public string $id,
        public string $mandateReference,
        /**
         * The club's Anmeldung, filled, base64-encoded — or null.
         *
         * It arrives here and nowhere else (ADR-0052 decision 5). There is no
         * second endpoint and no download token: the plaintext IBAN existed
         * only for the length of the request that produced this, so reloading
         * the confirmation screen has nothing left to render from.
         *
         * **Null is a normal answer**, not an error. A club webhost that is
         * down must not cost a registration, so the submission stands and the
         * document is simply absent — the admin-print variant, which needs no
         * plaintext at all, is the path that always works.
         */
        public ?string $documentBase64 = null,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'mandate_reference' => $this->mandateReference,
            // Always present as a key, so a client branches on the value rather
            // than on whether the field exists.
            'document' => $this->documentBase64,
        ];
    }

    /** Whether there is a document to hand over at all. */
    public function hasDocument(): bool
    {
        return $this->documentBase64 !== null;
    }
}
