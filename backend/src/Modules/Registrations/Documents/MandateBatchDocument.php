<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

/**
 * Many members' Anmeldung in one PDF, and what each sheet turned out to carry
 * (UC-A18).
 *
 * The second half is the reason this is a class and not a string. Whether a
 * sheet shows the full IBAN is decided per member *while rendering* — a
 * mandate sealed under a retired key, an IBAN too long for the template's comb
 * — and the caller has to audit what actually left the building, not what it
 * asked for. An `iban_full_view` entry for a sheet that was printed blank
 * would be a record of something that did not happen.
 */
final class MandateBatchDocument
{
    /**
     * @param string $pdf the document; nothing is written anywhere
     * @param array<string, MandateDocumentVariant> $variants member id => what
     *        that member's sheet carries, in the order the sheets were drawn
     */
    public function __construct(
        public readonly string $pdf,
        public readonly array $variants,
    ) {}

    /** How many members are in the document. */
    public function sheetCount(): int
    {
        return count($this->variants);
    }

    /** How many sheets carry a machine-printed IBAN. */
    public function fullIbanCount(): int
    {
        return count(array_filter(
            $this->variants,
            static fn(MandateDocumentVariant $v): bool => $v === MandateDocumentVariant::ADMIN_PRINT_FULL,
        ));
    }
}
