<?php

declare(strict_types=1);

namespace App\Modules\Members\DTOs;

/**
 * The PDF of open mandates, and the two numbers a caller needs beside it
 * (UC-A18).
 *
 * The body is a document and has nowhere to carry a remark, so what the admin
 * should know about the print travels separately — the way the SEPA export
 * reports its omissions on headers (#114).
 */
final class OpenMandatePrintDto
{
    /**
     * @param int $sheetCount how many members are in the document
     * @param int $withoutIbanCount how many sheets of a **keyed** print were
     *        left blank after all — a mandate sealed under a key that is not
     *        the active one, or an IBAN the template's comb cannot hold. Always
     *        0 for a blank print, where a blank line is what was asked for
     */
    public function __construct(
        public readonly string $pdf,
        public readonly int $sheetCount,
        public readonly int $withoutIbanCount,
    ) {}
}
