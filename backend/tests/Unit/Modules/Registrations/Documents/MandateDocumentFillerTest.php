<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Documents;

use App\Modules\Registrations\Documents\MandateDocumentFiller;
use App\Modules\Registrations\Documents\PdfAcroFormFields;
use App\Modules\Registrations\Documents\TemplateProblem;
use App\Modules\Registrations\Documents\UnusableTemplateException;
use PHPUnit\Framework\TestCase;

/**
 * Filling the club's document, against a real WeasyPrint build (#780).
 *
 * The fixture is `tests/Fixtures/documents/club-anmeldung.pdf`, rendered from
 * the `.html` beside it by `build.sh` — three pages, form fields on page 1, the
 * Datenschutzhinweise and the Nutzungsordnung behind it, exactly the shape
 * ADR-0052 decision 5 describes. Hand-built PDF fragments would not do here:
 * what is being asserted is that a document from the pipeline the ADR mandates
 * survives the round trip.
 */
final class MandateDocumentFillerTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../../../../Fixtures/documents/club-anmeldung.pdf';

    private string $template;

    protected function setUp(): void
    {
        $raw = @file_get_contents(self::FIXTURE);
        if ($raw === false) {
            self::fail('The template fixture is missing; rebuild it with tests/Fixtures/documents/build.sh');
        }

        $this->template = $raw;
    }

    /** @param array<string, string> $values */
    private function fill(array $values = []): string
    {
        return (new MandateDocumentFiller())->fill($this->template, $values + [
            'mandatsreferenz' => 'c0ffee1234spike9d41d8cd98f00b204',
            'vorname' => 'Lena',
            'nachname' => 'Brandt',
            'iban' => 'DE89 3704 0044 0532 0130 00',
            'iban_last4' => '****3000',
        ]);
    }

    private function pageCount(string $pdf): int
    {
        return (new MandateDocumentFiller())->pageCount($pdf);
    }

    public function test_the_filled_document_is_a_pdf(): void
    {
        self::assertStringStartsWith('%PDF-', $this->fill());
    }

    /**
     * The clause the whole decision turns on: the member signs the club's own
     * paper, whole. Pages 2 and 3 are the Datenschutzhinweise and the
     * Nutzungsordnung, and an output that dropped them would be a different
     * document wearing the club's first page.
     */
    public function test_every_template_page_survives_the_fill(): void
    {
        self::assertSame(3, $this->pageCount($this->template));
        self::assertSame(3, $this->pageCount($this->fill()));
    }

    /**
     * Not merely the right *number* of pages — the same pages, byte for byte.
     *
     * A page count alone would pass for an output that appended two blank
     * sheets. This compares content: every content stream the template carries
     * has to appear unchanged in the result, which is the strongest available
     * statement of "the member signs the club's own paper".
     *
     * It is asserted this way rather than by searching for the text on those
     * pages because there is no text to search for: WeasyPrint embeds subset
     * fonts, so a marker string is not literal bytes in the source PDF either.
     * The values *this* class draws are literal, because FPDF writes them with
     * a Latin-1 core font — which is why every other test here can read them.
     */
    public function test_every_template_page_is_carried_across_unchanged(): void
    {
        $before = $this->contentStreams($this->template);
        $after = $this->contentStreams($this->fill());

        self::assertNotSame([], $before, 'The fixture should carry content streams to compare.');

        foreach ($before as $index => $stream) {
            self::assertContains(
                $stream,
                $after,
                "Template content stream #{$index} did not survive the fill unchanged.",
            );
        }
    }

    /**
     * Page 1 is not rewritten either — it is imported and drawn *over*.
     *
     * The distinction matters: a fill that regenerated page 1 from its text
     * would silently lose whatever it could not re-render, and the club's
     * layout is not this software's to reproduce.
     */
    public function test_the_drawn_values_are_added_rather_than_replacing_the_page(): void
    {
        $filled = $this->fill();

        self::assertGreaterThan(
            count($this->contentStreams($this->template)),
            count($this->contentStreams($filled)),
            'The drawn text should be a stream of its own, beside the imported page.',
        );
    }

    public function test_the_member_variant_carries_the_full_iban(): void
    {
        self::assertStringContainsString('DE89 3704 0044 0532 0130 00', $this->readableText($this->fill()));
    }

    /**
     * The admin-print variant, and the reason decision 3 stays exception-free:
     * the IBAN is mandatory *mandate content*, not mandatory *machine-printed*
     * content. An empty value leaves the template's writing line blank for a
     * hand-written number, and the `****3000` hint is what the Kassenwart
     * checks it against.
     */
    public function test_an_empty_value_leaves_its_line_blank(): void
    {
        $text = $this->readableText($this->fill(['iban' => '']));

        self::assertStringNotContainsString('DE89', $text);
        self::assertStringContainsString('****3000', $text);
    }

    public function test_optional_fields_are_filled_when_the_template_has_them(): void
    {
        $text = $this->readableText($this->fill([
            'geburtsdatum' => '23.11.1979',
            'email' => 'lena@example.org',
            'kontoinhaber' => 'Petra Brandt',
        ]));

        self::assertStringContainsString('23.11.1979', $text);
        self::assertStringContainsString('lena@example.org', $text);
        self::assertStringContainsString('Petra Brandt', $text);
    }

    /**
     * A value for a field this template does not have is dropped, not an error.
     * Clubs publish different documents, and the vocabulary is a superset — a
     * template without `geburtsdatum` is a valid template.
     */
    public function test_a_value_with_no_field_to_go_in_is_ignored(): void
    {
        $filled = $this->fill(['telefonnummer' => '+49 69 1234']);

        self::assertStringNotContainsString('+49 69 1234', $this->readableText($filled));
        self::assertSame(3, $this->pageCount($filled));
    }

    /**
     * Ort/Datum, the signatures and the Kenntnisnahme boxes are done by hand at
     * signature and are not fields in a valid template — so there is nowhere
     * for a value to be drawn even if one were supplied. Asserted rather than
     * assumed, because "we simply never pass it" is a property of a caller and
     * this is a property of the document.
     */
    public function test_nothing_can_be_written_where_the_template_has_no_field(): void
    {
        $text = $this->readableText($this->fill([
            'datum_ort' => 'Frankfurt, 30.08.2026',
            'unterschrift' => 'Lena Brandt',
        ]));

        self::assertStringNotContainsString('Frankfurt', $text);
        self::assertStringNotContainsString('30.08.2026', $text);
    }

    /**
     * Flattened by construction, not by a flattening step: FPDI does not import
     * annotations, and the form fields *are* annotations. A step could be
     * forgotten; this cannot.
     */
    public function test_the_output_carries_no_live_form_fields(): void
    {
        $filled = $this->fill();

        self::assertNotSame([], PdfAcroFormFields::scan($this->template));
        self::assertSame([], PdfAcroFormFields::scan($filled));
        self::assertStringNotContainsString('/AcroForm', $filled);
    }

    /**
     * Core fonts are Latin-1, and a member called Müller-Lüdenscheidt is not an
     * edge case in a German club.
     */
    public function test_umlauts_survive_into_the_page(): void
    {
        $text = $this->readableText($this->fill(['nachname' => 'Müller-Lüdenscheidt']));

        self::assertStringContainsString(
            (string) iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Müller-Lüdenscheidt'),
            $text,
        );
    }

    public function test_a_template_that_is_not_a_pdf_is_refused_by_name(): void
    {
        try {
            (new MandateDocumentFiller())->fill('<html>404 Not Found</html>', []);
            self::fail('A webhost error page is not a template.');
        } catch (UnusableTemplateException $e) {
            self::assertSame(TemplateProblem::NOT_A_PDF, $e->problem);
        }
    }

    public function test_a_template_missing_a_required_field_is_refused_naming_it(): void
    {
        // The fixture with `iban_last4` renamed: still a valid PDF, no longer a
        // valid template, and the refusal has to say which field is gone.
        $crippled = str_replace('(iban_last4)', '(iban_lastvier)', $this->template);

        try {
            (new MandateDocumentFiller())->assertUsable($crippled);
            self::fail('A template missing a required field must be refused.');
        } catch (UnusableTemplateException $e) {
            self::assertSame(['iban_last4'], $e->missingFields);
        }
    }

    public function test_the_fixture_passes_its_own_validation(): void
    {
        $this->expectNotToPerformAssertions();

        (new MandateDocumentFiller())->assertUsable($this->template);
    }

    /**
     * Every stream in a PDF, decompressed where it is compressed.
     *
     * @return list<string>
     */
    private function contentStreams(string $pdf): array
    {
        $streams = [];

        if (preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $pdf, $matches)) {
            foreach ($matches[1] as $stream) {
                $inflated = @gzuncompress($stream);
                $streams[] = $inflated === false ? $stream : $inflated;
            }
        }

        return $streams;
    }

    /**
     * The text a PDF's content streams carry.
     *
     * Crude on purpose — this is a test helper, not a PDF reader. FPDF writes
     * drawn text as `(...) Tj` in an uncompressed stream, while the imported
     * pages arrive Flate-compressed, so both are handled. Enough to answer
     * "does this value appear on a page", which is the only question asked of
     * it.
     */
    private function readableText(string $pdf): string
    {
        $text = $pdf;

        if (preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $pdf, $streams)) {
            foreach ($streams[1] as $stream) {
                $inflated = @gzuncompress($stream);
                if ($inflated !== false) {
                    $text .= $inflated;
                }
            }
        }

        return $text;
    }
}
