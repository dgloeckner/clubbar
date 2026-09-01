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
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'mandate_reference' => $this->mandateReference,
        ];
    }
}
