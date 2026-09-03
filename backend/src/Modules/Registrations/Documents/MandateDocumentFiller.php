<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;

/**
 * Draw a member's data onto the club's own Anmeldung (#780, ADR-0052 decision 5).
 *
 * ## What this is not
 *
 * It is not a form filler. Nothing here writes an AcroForm value, and the
 * output has no form in it at all — the fields exist in the *template* purely
 * as an addressing contract, giving each value a name and a rectangle. That
 * choice is what makes the result flattened **by construction**: FPDI does not
 * import annotations, and a form field is an annotation, so there is no
 * flattening step that could be skipped, misconfigured or forgotten.
 *
 * ## The order of operations is load-bearing
 *
 * Import page 1, draw every value on it, *then* append pages 2..n. FPDF cannot
 * revisit a page it has moved past, so a fill written in the obvious order —
 * assemble the document, then write the values — produces a complete document
 * with nothing written on it. The failure is silent and looks exactly like the
 * data never arriving.
 *
 * ## Two details that cost real debugging in the spike
 *
 * `/Rect` corner order is normalized by {@see PdfAcroFormFields} — WeasyPrint
 * writes corners top-down, and read literally every value lands off the page.
 * And core fonts are Latin-1, so text is transliterated: a club whose members
 * are called Müller is not an edge case.
 */
class MandateDocumentFiller
{
    /** The size a value is drawn at when it fits. */
    private const FONT_SIZE = 10.0;

    /**
     * The smallest a value may be shrunk to before legibility loses to fitting.
     *
     * Below this, a value that still does not fit is drawn at this size and
     * allowed to be too wide — which is visibly wrong, and *meant* to be. A
     * value silently clipped or overlapping the next field is a document that
     * looks fine and is not; one that is obviously cramped gets looked at.
     */
    private const MIN_FONT_SIZE = 5.0;

    /** Left padding inside the field rectangle, so text does not touch the line. */
    private const TEXT_INSET = 3.0;

    /**
     * A comb cell: `iban_1`, `iban_2`, … one box per character.
     *
     * German forms draw the IBAN as an **IBAN-Kamm** — a row of boxes sized for
     * a handwritten character — and a value drawn as one continuous run across
     * it lands between the boxes rather than in them. A template that wants its
     * comb filled declares one field per box, which is also how it would be
     * authored in HTML: `<input name="iban_1">` and so on.
     *
     * Only recognised when the *base* name has a value and the numbered name
     * does not. That second condition is what keeps `iban_last4` — base
     * `iban_last`, index `4` — from being mistaken for the fourth box of a comb
     * that does not exist.
     */
    private const COMB_CELL = '/^(?<base>.+)_(?<index>[1-9]\d*)$/';

    /**
     * Refuse a template this filler could not use, before anything depends on it.
     *
     * Called at configuration time (#783) so a club learns their document is
     * unusable while they are looking at the setting, rather than when the
     * first applicant's registration cannot produce a document.
     *
     * **And one that this filler could use perfectly well** (#812): a template
     * whose fields already carry values. That is not a fill problem — FPDI
     * imports page 1 without annotations, so the values never reach the output
     * — it is a *publication* problem. ADR-0052 decision 6 makes this the URL
     * every applicant opens before typing anything, so a value left in a field
     * is shown to every stranger who scans the poster. It is checked first, and
     * before the vocabulary, because it is the only failure here that leaks
     * somebody's data rather than merely producing no document.
     *
     * @throws UnusableTemplateException
     */
    public function assertUsable(string $template): void
    {
        $fields = PdfAcroFormFields::scan($template);

        if ($fields === []) {
            throw new UnusableTemplateException(PdfAcroFormFields::diagnose($template));
        }

        $prefilled = PdfAcroFormFields::prefilledFields($template);
        if ($prefilled !== []) {
            throw new UnusableTemplateException(null, prefilledFields: $prefilled);
        }

        $missing = PdfAcroFormFields::missingRequired($fields);
        if ($missing !== []) {
            throw new UnusableTemplateException(null, $missing);
        }
    }

    /**
     * The club's document with the values drawn in, every page preserved.
     *
     * A value whose field the template does not carry is dropped rather than
     * refused: the vocabulary is a superset, and a club document without
     * `geburtsdatum` is a valid club document. An **empty** value is skipped
     * too, which is exactly how the admin-print variant leaves the IBAN line
     * blank for a hand-written number.
     *
     * @param array<string, string> $values field name => what to draw
     * @throws UnusableTemplateException the template cannot be read at all
     */
    public function fill(string $template, array $values): string
    {
        $fields = PdfAcroFormFields::scan($template);
        if ($fields === []) {
            throw new UnusableTemplateException(PdfAcroFormFields::diagnose($template));
        }

        $this->assertCombCanHoldTheIban($fields, $values);

        $source = $this->sourceFile($template);

        try {
            $pdf = new Fpdi('P', 'pt');
            $pageCount = $this->openSource($pdf, $source);

            $pageOne = $pdf->importPage(1);
            $size = $pdf->getTemplateSize($pageOne);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($pageOne);

            $pdf->SetFont('Helvetica', '', self::FONT_SIZE);
            $pdf->SetTextColor(26, 26, 46);

            foreach ($fields as $name => [$x1, $y1, $x2, $y2]) {
                $value = $this->valueFor($name, $fields, $values);
                if ($value === '') {
                    continue;
                }

                $text = $this->latin1($value);
                $fieldWidth = $x2 - $x1;

                // Shrink to fit rather than overflow. Not a nicety: on the
                // reference club's own published Anmeldung the 32-character
                // mandate reference is 166pt wide at 10pt in a 108pt field, so
                // a fixed size runs it 58pt into whatever sits beside it.
                $fontSize = $this->sizeThatFits($pdf, $text, $fieldWidth);
                $pdf->SetFontSize($fontSize);

                // FPDF's origin is top-left; the rectangle's is bottom-left.
                $height = $y2 - $y1;
                $baselineFromBottom = $y1 + ($height - $fontSize) / 2 + 2.0;

                // A comb cell holds one glyph and wants it in the middle of the
                // box; anything else reads left to right from the field's edge.
                $x = $this->isCombCell($name, $fields, $values)
                    ? $x1 + max(0.0, ($fieldWidth - $pdf->GetStringWidth($text)) / 2)
                    : $x1 + self::TEXT_INSET;

                $pdf->Text($x, (float) $size['height'] - $baselineFromBottom, $text);
            }

            // Only now. FPDF cannot go back to page 1 once page 2 exists, so
            // everything above has to have happened already.
            for ($page = 2; $page <= $pageCount; $page++) {
                $imported = $pdf->importPage($page);
                $pageSize = $pdf->getTemplateSize($imported);
                $pdf->AddPage($pageSize['orientation'], [$pageSize['width'], $pageSize['height']]);
                $pdf->useTemplate($imported);
            }

            return (string) $pdf->Output('S');
        } finally {
            @unlink($source);
        }
    }

    /**
     * Refuse a comb too short for the IBAN it is being handed.
     *
     * A German form draws 22 boxes because a German IBAN is 22 characters. Hand
     * it a 27-character French one and the last five have nowhere to go — and
     * the silent outcome is the worst one available: a **legal SEPA mandate
     * printed with a truncated IBAN**, which looks complete, which somebody
     * signs, and which fails at the bank weeks later with no way to tell from
     * the paper what went wrong.
     *
     * So it fails here instead, loudly. The member's path treats that as "no
     * document" and keeps the registration; the admin's says what happened. And
     * it is the honest answer either way: an IBAN that will not fit the boxes
     * cannot be written on that form by hand either, which is a property of the
     * club's paper rather than something software should paper over.
     *
     * Only when the comb is the *only* place the IBAN could go: a template
     * carrying a single wide `iban` field as well has somewhere to put it.
     *
     * @param array<string, mixed> $fields
     * @param array<string, string> $values
     * @throws UnusableTemplateException
     */
    private function assertCombCanHoldTheIban(array $fields, array $values): void
    {
        $cells = PdfAcroFormFields::combCells($fields);
        if ($cells === [] || array_key_exists('iban', $fields)) {
            return;
        }

        $iban = preg_replace('/\s+/', '', (string) ($values['iban'] ?? '')) ?? '';
        if ($iban === '') {
            return;
        }

        $highest = max(array_keys($cells));
        if (strlen($iban) > $highest) {
            throw new UnusableTemplateException(
                null,
                [],
                sprintf(
                    'The template\'s IBAN comb holds %d characters; this IBAN has %d.',
                    $highest,
                    strlen($iban),
                ),
            );
        }
    }

    /**
     * What belongs in this field: a plain value, or one character of a comb.
     *
     * @param array<string, mixed> $fields
     * @param array<string, string> $values
     */
    private function valueFor(string $name, array $fields, array $values): string
    {
        if (array_key_exists($name, $values)) {
            return (string) $values[$name];
        }

        if (!$this->isCombCell($name, $fields, $values)) {
            return '';
        }

        preg_match(self::COMB_CELL, $name, $parts);

        // Whitespace never gets a box. A comb has one cell per character of the
        // value itself, so the grouped form a single wide field would show —
        // `DE89 3704 …` — is compacted before it is distributed.
        $source = preg_replace('/\s+/', '', (string) $values[$parts['base']]) ?? '';
        $index = (int) $parts['index'] - 1;

        return $index < strlen($source) ? $source[$index] : '';
    }

    /**
     * Whether this field is the *n*th box of a comb whose base value exists.
     *
     * @param array<string, mixed> $fields
     * @param array<string, string> $values
     */
    private function isCombCell(string $name, array $fields, array $values): bool
    {
        if (array_key_exists($name, $values)) {
            return false;
        }
        if (preg_match(self::COMB_CELL, $name, $parts) !== 1) {
            return false;
        }

        return array_key_exists($parts['base'], $values);
    }

    /**
     * The largest size at or below the nominal one at which this text fits.
     *
     * Stepped rather than solved: a half-point ladder is imperceptible on the
     * page and avoids a division that would need guarding against a zero-width
     * string. A value that will not fit even at the floor is drawn at the floor
     * and allowed to look cramped — see {@see MIN_FONT_SIZE}.
     */
    private function sizeThatFits(Fpdi $pdf, string $text, float $fieldWidth): float
    {
        $usable = max(1.0, $fieldWidth - 2 * self::TEXT_INSET);

        for ($size = self::FONT_SIZE; $size > self::MIN_FONT_SIZE; $size -= 0.5) {
            $pdf->SetFontSize($size);
            if ($pdf->GetStringWidth($text) <= $usable) {
                return $size;
            }
        }

        return self::MIN_FONT_SIZE;
    }

    /** How many pages a PDF has — for asserting that none were lost. */
    public function pageCount(string $pdf): int
    {
        $source = $this->sourceFile($pdf);

        try {
            return $this->openSource(new Fpdi('P', 'pt'), $source);
        } finally {
            @unlink($source);
        }
    }

    /**
     * FPDI reads from a path, and the template arrives as bytes — fetched over
     * HTTP, never stored (ADR-0052 decision 5a). So it gets a temporary file
     * for the length of the call and no longer; the `finally` above is what
     * makes "no storage side effects" true even when the fill throws.
     */
    private function sourceFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'clubbar-template-');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file for the mandate template.');
        }

        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * @throws UnusableTemplateException FPDI's parser refused the file — which
     *         for the free parser means a cross-reference stream, the one case
     *         a scan can pass and an import still fail
     */
    private function openSource(Fpdi $pdf, string $path): int
    {
        try {
            return $pdf->setSourceFile($path);
        } catch (CrossReferenceException $e) {
            throw new UnusableTemplateException(TemplateProblem::NO_CLASSIC_XREF, [], $e->getMessage());
        }
    }

    /**
     * Core fonts encode Latin-1 and nothing else, so anything outside it is
     * transliterated rather than dropped. `iconv` returning false — which it
     * can, for text it cannot map at all — falls back to the original: a
     * mangled name on the page beats an exception between a member and their
     * mandate.
     */
    private function latin1(string $value): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value);

        return $converted === false ? $value : $converted;
    }
}
