<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Documents;

use App\Modules\Registrations\Documents\PdfAcroFormFields;
use App\Modules\Registrations\Documents\TemplateProblem;
use PHPUnit\Framework\TestCase;

/**
 * Reading a club's template well enough to refuse it (#780, ADR-0052 decision 5).
 *
 * The AcroForm fields are an **addressing contract**, not a form to fill: what
 * this class extracts is a name and a rectangle per field, which is everything
 * the filler needs and nothing more. It never opens a form, never writes one,
 * and deliberately does not depend on FPDI — a template that this scanner can
 * read but FPDI cannot is a case the service has to handle, and coupling the
 * two would hide it.
 *
 * The fixtures below are hand-built PDF fragments rather than rendered files.
 * That is not laziness: the two failure modes worth testing — a compressed
 * object stream and a cross-reference stream — are exactly what a real
 * WeasyPrint build *avoids*, so they cannot be produced by the pipeline this
 * feature documents. `MandateTemplateFixtureTest` covers the real rendered
 * article.
 */
final class PdfAcroFormFieldsTest extends TestCase
{
    /** A widget object as an uncompressed PDF writes one. */
    private function widget(string $name, string $rect): string
    {
        return "4 0 obj\n<< /Type /Annot /Subtype /Widget /FT /Tx /T ({$name}) /Rect [ {$rect} ] >>\nendobj\n";
    }

    private function pdf(string $body): string
    {
        return "%PDF-1.4\n" . $body . "\nxref\n0 1\ntrailer\n<< >>\n%%EOF\n";
    }

    public function test_it_reads_a_field_name_and_its_rectangle(): void
    {
        $fields = PdfAcroFormFields::scan($this->pdf($this->widget('vorname', '72 700 300 716')));

        self::assertSame(['vorname'], array_keys($fields));
        self::assertSame([72.0, 700.0, 300.0, 716.0], $fields['vorname']);
    }

    /**
     * The bug that cost real debugging in the spike, kept as a test.
     *
     * PDF allows `/Rect` to name **any two opposite corners**, and WeasyPrint
     * writes them top-down. Read literally, the height comes out negative and
     * every value is drawn off the page — which looks, on the resulting PDF,
     * exactly like the fill silently doing nothing.
     */
    public function test_it_normalizes_corners_written_top_down(): void
    {
        $fields = PdfAcroFormFields::scan($this->pdf($this->widget('iban', '300 716 72 700')));

        self::assertSame([72.0, 700.0, 300.0, 716.0], $fields['iban']);
    }

    public function test_it_reads_negative_and_fractional_coordinates(): void
    {
        $fields = PdfAcroFormFields::scan($this->pdf($this->widget('nachname', '-1.5 700.25 300 716.75')));

        self::assertSame([-1.5, 700.25, 300.0, 716.75], $fields['nachname']);
    }

    public function test_it_reads_every_widget_in_the_file(): void
    {
        $fields = PdfAcroFormFields::scan($this->pdf(
            $this->widget('vorname', '72 700 300 716')
            . $this->widget('nachname', '320 700 540 716')
            . $this->widget('iban', '72 660 540 676'),
        ));

        self::assertSame(['vorname', 'nachname', 'iban'], array_keys($fields));
    }

    /**
     * A non-widget object that happens to carry a `/T` — an outline entry, a
     * bookmark — is not a field. Reading one as a field would place a value at
     * a rectangle nobody put there.
     */
    public function test_it_ignores_objects_that_are_not_widgets(): void
    {
        $fields = PdfAcroFormFields::scan($this->pdf(
            "5 0 obj\n<< /Type /Outlines /T (nichtsfeld) /Rect [ 1 2 3 4 ] >>\nendobj\n"
            . $this->widget('vorname', '72 700 300 716'),
        ));

        self::assertSame(['vorname'], array_keys($fields));
    }

    public function test_a_widget_without_a_rectangle_is_skipped(): void
    {
        $fields = PdfAcroFormFields::scan($this->pdf(
            "4 0 obj\n<< /Subtype /Widget /FT /Tx /T (ohnerect) >>\nendobj\n"
            . $this->widget('vorname', '72 700 300 716'),
        ));

        self::assertSame(['vorname'], array_keys($fields));
    }

    // ── why an empty result is empty ──────────────────────────────────────

    /**
     * Three ways to find no fields, and the club needs to be told which.
     *
     * "No fields found" alone sends somebody to check their field names, which
     * is the one thing that is *not* wrong when the file was built with object
     * streams: the names are there, in a compressed blob this scanner cannot
     * see. The answer is a rebuild flag, and the refusal has to say so.
     */
    public function test_a_compressed_object_stream_is_named_as_the_problem(): void
    {
        $raw = "%PDF-1.5\n7 0 obj\n<< /Type /ObjStm /N 4 >>\nstream\n...\nendstream\nendobj\nxref\n";

        self::assertSame([], PdfAcroFormFields::scan($raw));
        self::assertSame(TemplateProblem::COMPRESSED_OBJECT_STREAMS, PdfAcroFormFields::diagnose($raw));
    }

    public function test_a_cross_reference_stream_is_named_as_the_problem(): void
    {
        $raw = "%PDF-1.5\n1 0 obj\n<< /Type /XRef /W [1 2 1] >>\nstream\n...\nendstream\nendobj\nstartxref\n";

        self::assertSame([], PdfAcroFormFields::scan($raw));
        self::assertSame(TemplateProblem::NO_CLASSIC_XREF, PdfAcroFormFields::diagnose($raw));
    }

    public function test_a_readable_pdf_with_no_form_fields_says_exactly_that(): void
    {
        $raw = $this->pdf("4 0 obj\n<< /Type /Page >>\nendobj\n");

        self::assertSame([], PdfAcroFormFields::scan($raw));
        self::assertSame(TemplateProblem::NO_FORM_FIELDS, PdfAcroFormFields::diagnose($raw));
    }

    public function test_something_that_is_not_a_pdf_at_all_is_named_as_such(): void
    {
        self::assertSame(TemplateProblem::NOT_A_PDF, PdfAcroFormFields::diagnose('<html>404 Not Found</html>'));
    }

    /**
     * The likeliest real failure: a club webhost that answers a missing file
     * with an HTML error page and a 200. Nothing about that is a PDF, and
     * saying "no form fields found" about it would send the club looking in
     * entirely the wrong place.
     */
    public function test_a_file_with_fields_diagnoses_as_no_problem(): void
    {
        self::assertNull(PdfAcroFormFields::diagnose($this->pdf($this->widget('vorname', '72 700 300 716'))));
    }
}
