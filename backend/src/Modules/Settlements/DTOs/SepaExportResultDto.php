<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

/**
 * The outcome of a SEPA export: the pain.008 document plus the members the
 * export deliberately left out of it.
 *
 * Under the exclude-and-flag ruling (#141) a member whose unsettled position
 * is a credit is owed money by the club and must never be debited. Dropping
 * them silently is how issue #114 describes the file diverging from the books
 * without anyone being told, so the exclusions travel with the XML.
 */
final readonly class SepaExportResultDto
{
    /**
     * @param array<int, array{member_id: string, first_name: string, last_name: string, balance_cents: int}> $creditExcludedMembers
     */
    public function __construct(
        public string $xml,
        public array $creditExcludedMembers = [],
    ) {}
}
