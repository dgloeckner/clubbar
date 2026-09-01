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
    /** Points. Matches the field heights a 10pt template produces. */
    private const FONT_SIZE = 10.0;

    /** Left padding inside the field rectangle, so text does not touch the line. */
    private const TEXT_INSET = 3.0;

    /**
     * Refuse a template this filler could not use, before anything depends on it.
     *
     * Called at configuration time (#783) so a club learns their document is
     * unusable while they are looking at the setting, rather than when the
     * first applicant's registration cannot produce a document.
     *
     * @throws UnusableTemplateException
     */
    public function assertUsable(string $template): void
    {
        $fields = PdfAcroFormFields::scan($template);

        if ($fields === []) {
            throw new UnusableTemplateException(PdfAcroFormFields::diagnose($template));
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

            foreach ($fields as $name => [$x1, $y1, , $y2]) {
                $value = (string) ($values[$name] ?? '');
                if ($value === '') {
                    continue;
                }

                // FPDF's origin is top-left; the rectangle's is bottom-left.
                $height = $y2 - $y1;
                $baselineFromBottom = $y1 + ($height - self::FONT_SIZE) / 2 + 2.0;

                $pdf->Text(
                    $x1 + self::TEXT_INSET,
                    (float) $size['height'] - $baselineFromBottom,
                    $this->latin1($value),
                );
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
