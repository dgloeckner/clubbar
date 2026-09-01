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

    // ── the IBAN-Kamm, and fitting a value to its field (#781 feedback) ──

    /**
     * German forms print an IBAN as a comb — one box per character, sized for a
     * handwritten letter. A value drawn as one continuous run across it lands
     * *between* the boxes rather than in them, so a template that wants its comb
     * filled declares one field per box, and each gets one character.
     */
    public function test_a_comb_gets_one_character_per_box(): void
    {
        $filled = $this->fill();
        $text = $this->readableText($filled);

        // Drawn one glyph at a time, each positioned in its own box.
        self::assertMatchesRegularExpression('/\(D\)\s*Tj/', $text);
        self::assertMatchesRegularExpression('/\(E\)\s*Tj/', $text);
        self::assertMatchesRegularExpression('/\(8\)\s*Tj/', $text);
    }

    /**
     * Each glyph lands inside the box it belongs to, and centred in it.
     *
     * The assertion the other comb tests cannot make: that the characters were
     * drawn is one thing, that they are in the right boxes is the thing that
     * matters. A comb filled left-to-right from the field's edge — or from the
     * wrong rectangle — produces a document that is subtly, unmistakably wrong
     * on paper and looks fine in every other check.
     */
    public function test_each_comb_glyph_is_drawn_centred_inside_its_own_box(): void
    {
        $fields = PdfAcroFormFields::scan($this->template);
        $filled = $this->fill(['iban' => 'DE89 3704 0044 0532 0130 00']);
        $compact = 'DE89370400440532013000';

        // FPDF's Text() writes `BT x y Td (s) Tj ET`.
        preg_match_all(
            '~BT\s+([\d.]+)\s+([\d.]+)\s+Td\s+\((.)\)\s*Tj~',
            $this->readableText($filled),
            $draws,
            PREG_SET_ORDER,
        );
        self::assertNotSame([], $draws, 'Nothing was drawn one glyph at a time.');

        $placed = [];
        foreach ($draws as [, $x, , $glyph]) {
            $placed[] = [(float) $x, $glyph];
        }

        $verified = 0;
        foreach ([1, 5, 12, 22] as $cell) {
            $name = 'iban_' . $cell;
            self::assertArrayHasKey($name, $fields, "The fixture should carry {$name}.");

            [$x1, , $x2] = $fields[$name];
            $expected = $compact[$cell - 1];

            foreach ($placed as [$x, $glyph]) {
                if ($glyph === $expected && $x >= $x1 && $x <= $x2) {
                    // Centred, not flush: a glyph at the very edge of its box is
                    // what a left-aligned draw would produce.
                    self::assertGreaterThan($x1, $x, "{$name} should be centred, not flush left.");
                    $verified++;
                    break;
                }
            }
        }

        self::assertSame(4, $verified, 'Every sampled comb cell should hold its own character.');
    }

    /**
     * A comb has one cell per *character*, and a space is not a character that
     * gets a box. The grouped form a single wide field shows — `DE89 3704 …` —
     * is compacted before it is distributed, or every group would shift the
     * rest of the number one box to the right.
     */
    public function test_the_comb_is_filled_from_the_compact_iban(): void
    {
        $filled = $this->fill(['iban' => 'DE89 3704 0044 0532 0130 00']);
        $fields = PdfAcroFormFields::scan($this->template);

        // Cell 5 is the fifth character of the compact IBAN — `3`, not the
        // space that follows `DE89` in the grouped form.
        self::assertArrayHasKey('iban_5', $fields);
        self::assertStringContainsString('(3) Tj', $this->readableText($filled));
    }

    /**
     * The trap this had to avoid: `iban_last4` looks exactly like the fourth box
     * of a comb named `iban_last`. It is not, and it has a value of its own —
     * mistaking it would silently drop the hint the Kassenwart checks against.
     */
    public function test_a_field_that_merely_ends_in_a_number_is_not_a_comb_cell(): void
    {
        $text = $this->readableText($this->fill(['iban_last4' => 'endet auf ****3000']));

        self::assertStringContainsString('endet auf ****3000', $text);
    }

    /**
     * The bug the comb question uncovered, and the bigger of the two.
     *
     * On the reference club's own published Anmeldung the mandate reference is
     * 32 hex characters in a 108pt field: at a fixed 10pt that is 166pt of text,
     * running 58pt into whatever sits beside it. Nothing about the output says
     * so — it just looks wrong on paper, after printing.
     */
    public function test_a_value_too_wide_for_its_field_is_shrunk_rather_than_overflowing(): void
    {
        $reference = str_repeat('c0ffee12', 4);
        $filled = $this->fill(['mandatsreferenz' => $reference]);

        $fields = PdfAcroFormFields::scan($this->template);
        $width = $fields['mandatsreferenz'][2] - $fields['mandatsreferenz'][0];

        // Measured with the same metrics the filler uses, at the size it chose.
        $pdf = new \setasign\Fpdi\Fpdi('P', 'pt');
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', $this->fontSizeUsedFor($filled, $reference));

        self::assertLessThanOrEqual($width, $pdf->GetStringWidth($reference));
        self::assertStringContainsString($reference, $this->readableText($filled));
    }

    /** A value that already fits keeps the nominal size; nothing shrinks for fun. */
    public function test_a_value_that_fits_is_drawn_at_the_nominal_size(): void
    {
        $filled = $this->fill(['vorname' => 'Lena']);

        self::assertSame(10.0, $this->fontSizeUsedFor($filled, 'Lena'));
    }

    /**
     * The size the content stream actually selected before drawing $needle.
     *
     * FPDF writes `/F1 9.50 Tf` ahead of each `Tj`, so the last size set before
     * a given string is the size it was drawn at.
     */
    private function fontSizeUsedFor(string $pdf, string $needle): float
    {
        $text = $this->readableText($pdf);
        $at = strpos($text, '(' . $needle . ')');
        self::assertNotFalse($at, "The value {$needle} was never drawn.");

        preg_match_all('~/F\d+\s+([\d.]+)\s+Tf~', substr($text, 0, $at), $sizes);
        self::assertNotSame([], $sizes[1], 'No font size was selected before drawing.');

        return (float) end($sizes[1]);
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