<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Documents;

use App\Modules\Registrations\Documents\MandateDocumentFiller;
use App\Modules\Registrations\Documents\PdfAcroFormFields;
use App\Modules\Registrations\Documents\TemplateProblem;
use App\Modules\Registrations\Documents\UnusableTemplateException;
use App\Shared\Exceptions\BusinessRuleReason;
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
     * A value has nowhere to go when the template has no field for it, and is
     * dropped rather than drawn somewhere plausible. Asserted rather than
     * assumed, because "we simply never pass it" is a property of a caller and
     * this is a property of the document.
     *
     * The names here are deliberately ones this fixture does **not** carry.
     * `unterschrift` used to be among them and no longer is: since #784 the
     * fixture makes the signature line fillable on purpose, so that leaving it
     * empty is provable instead of merely inevitable — see
     * {@see self::test_nothing_is_ever_drawn_on_the_signature_line()}.
     */
    public function test_nothing_can_be_written_where_the_template_has_no_field(): void
    {
        $text = $this->readableText($this->fill([
            'datum_ort' => 'Frankfurt, 30.08.2026',
            'mitgliedsnummer' => 'M-4711',
        ]));

        self::assertStringNotContainsString('Frankfurt', $text);
        self::assertStringNotContainsString('30.08.2026', $text);
        self::assertStringNotContainsString('M-4711', $text);
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
     * A template that arrives with somebody's data already in it is refused
     * (#812) — and it is refused for a reason that has nothing to do with
     * filling, which is why it is easy to miss.
     *
     * The fill itself is unaffected: FPDI imports page 1 without annotations,
     * so the values are dropped along with the widgets that held them and the
     * output is correct. The damage is that ADR-0052 decision 6 makes this same
     * URL the club's Datenschutzhinweis — linked to every applicant before they
     * type anything — so a value in a field is published to every stranger who
     * scans the poster. A real club's IBAN was readable from the onboarding
     * page this way, and nothing in the document looked wrong to the club.
     */
    public function test_a_template_arriving_with_values_in_it_is_refused(): void
    {
        // The genuine fixture with its IBAN field filled — the shape WeasyPrint
        // produces from an `<input value="…">` somebody left in the HTML master.
        $published = str_replace(
            '/T (iban)/FT /Tx/DA (/a1.0 gs 0.101961 0.101961 0.180392 rg /SDLPBP 10 Tf)/V ()',
            '/T (iban)/FT /Tx/DA (/a1.0 gs 0.101961 0.101961 0.180392 rg /SDLPBP 10 Tf)/V (DE89370400440532013000)',
            $this->template,
        );
        self::assertNotSame($this->template, $published, 'the fixture no longer has the field this edits');

        try {
            (new MandateDocumentFiller())->assertUsable($published);
            self::fail('A template carrying somebody else\'s data must be refused.');
        } catch (UnusableTemplateException $e) {
            self::assertSame(['iban'], $e->prefilledFields);
            self::assertSame(
                BusinessRuleReason::DOCUMENT_TEMPLATE_PREFILLED,
                $e->getReason(),
                'the panel needs its own code for this: the fix is not a rebuild flag'
            );
        }
    }

    /**
     * The leak is reported ahead of the vocabulary. A document can be both
     * incomplete and published with data in it, and only one of those two is
     * somebody's bank details on a public URL.
     */
    public function test_the_leak_is_named_before_a_missing_field(): void
    {
        $both = str_replace('(iban_last4)', '(iban_lastvier)', $this->template);
        $both = str_replace(
            '/T (vorname)/FT /Tx/DA (/a1.0 gs 0.101961 0.101961 0.180392 rg /SDLPBP 10 Tf)/V ()',
            '/T (vorname)/FT /Tx/DA (/a1.0 gs 0.101961 0.101961 0.180392 rg /SDLPBP 10 Tf)/V (Anna)',
            $both,
        );

        try {
            (new MandateDocumentFiller())->assertUsable($both);
            self::fail('A template carrying values must be refused.');
        } catch (UnusableTemplateException $e) {
            self::assertSame(['vorname'], $e->prefilledFields);
            self::assertSame([], $e->missingFields);
        }
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
     * A comb whose first boxes are pre-printed, which is what a real form does.
     *
     * The reference club's Anmeldung prints `D` and `E` into the first two boxes
     * — every member's IBAN starts that way, and it helps whoever is writing by
     * hand. Those two boxes are therefore not fields, and the remaining twenty
     * are `iban_3` … `iban_22`.
     *
     * That works without a special case because **a cell's number is its
     * character position**, not its offset among the fields present. A template
     * pre-printing a prefix simply omits those boxes and the rest still land
     * where they belong.
     */
    public function test_a_comb_may_start_partway_through_when_a_prefix_is_pre_printed(): void
    {
        // The fixture's own comb with its first two cells renamed away — the
        // shape a form that pre-prints `DE` produces. Renamed to the *same
        // length*, deliberately: a PDF's xref table holds byte offsets, so a
        // longer name would leave the file unparseable and this test would fail
        // for a reason that has nothing to do with combs.
        $prefixed = str_replace(['(iban_1)', '(iban_2)'], ['(zzzz_1)', '(zzzz_2)'], $this->template);

        $filled = (new MandateDocumentFiller())->fill($prefixed, [
            'mandatsreferenz' => 'ref',
            'vorname' => 'Lena',
            'nachname' => 'Brandt',
            'iban' => 'DE89 3704 0044 0532 0130 00',
            'iban_last4' => '****3000',
        ]);

        $fields = PdfAcroFormFields::scan($prefixed);
        preg_match_all(
            '~BT\s+([\d.]+)\s+([\d.]+)\s+Td\s+\((.)\)\s*Tj~',
            $this->readableText($filled),
            $draws,
            PREG_SET_ORDER,
        );

        // Cell 3 still holds the *third* character of the IBAN — `8` — not the
        // first character of what remains after the prefix.
        [$x1, , $x2] = $fields['iban_3'];
        $found = false;
        foreach ($draws as [, $x, , $glyph]) {
            if ($glyph === '8' && (float) $x >= $x1 && (float) $x <= $x2) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'iban_3 should hold the third character of the IBAN.');
    }

    /**
     * A comb too short for the IBAN it is handed is refused, not truncated.
     *
     * The silent outcome is the worst one available: a **legal SEPA mandate
     * printed with a truncated IBAN** — complete-looking, signed by somebody,
     * and failing at the bank weeks later with nothing on the paper to say why.
     *
     * It is also the honest answer. A 27-character French IBAN cannot be
     * written into 22 German boxes by hand either; that is a property of the
     * club's paper, not something software should paper over.
     */
    public function test_a_comb_too_short_for_the_iban_is_refused_rather_than_truncated(): void
    {
        // The fixture's own comb is 22 cells, and its single wide `iban` field
        // has to go — otherwise the value has somewhere else to land.
        $combOnly = str_replace('/T (iban)', '/T (zzzz)', $this->template);

        try {
            (new MandateDocumentFiller())->fill($combOnly, [
                'mandatsreferenz' => 'ref',
                'vorname' => 'Amélie',
                'nachname' => 'Rousseau',
                'iban' => 'FR1420041010050500013M02606',
                'iban_last4' => '2606',
            ]);
            self::fail('An IBAN that does not fit the comb must be refused.');
        } catch (UnusableTemplateException $e) {
            self::assertStringContainsString('22', $e->getMessage());
            self::assertStringContainsString('27', $e->getMessage());
        }
    }

    /** A template with a wide field as well has somewhere to put it. */
    public function test_a_long_iban_is_accepted_when_a_single_wide_field_exists(): void
    {
        $filled = $this->fill(['iban' => 'FR1420041010050500013M02606']);

        self::assertStringContainsString('FR14', $this->readableText($filled));
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
    /**
     * The signature line comes back untouched (#784, epic decision 3).
     *
     * The fixture makes `ort_datum` and `unterschrift` real AcroForm fields
     * exactly so this is provable rather than incidental. A club whose designer
     * made the signature line fillable is a template this pipeline has to
     * handle **without** filling it: a machine-printed place and date on a SEPA
     * mandate is a claim nobody made, and the handwriting on those two lines is
     * what the Kassenwart's later attestation refers to.
     *
     * Asserted geometrically rather than by searching for a string, because the
     * failure to catch is "somebody added today's date as a convenience" and
     * the string it would print is not knowable in advance. What is knowable is
     * *where* it would land — so this checks that nothing was drawn inside
     * either rectangle at all.
     */
    public function test_nothing_is_ever_drawn_on_the_signature_line(): void
    {
        $fields = PdfAcroFormFields::scan($this->template);

        // A control run first: without it, a rebuild that dropped the two
        // fields would leave this test asserting that nothing was drawn into
        // nothing, and passing forever.
        $control = $this->drawnRuns($this->fill(['ort_datum' => 'Musterstadt', 'unterschrift' => 'Lena B.']));
        foreach (['ort_datum', 'unterschrift'] as $name) {
            self::assertArrayHasKey($name, $fields, 'the fixture must still carry ' . $name);
            self::assertNotEmpty(
                $this->runsInside($control, $fields[$name]),
                $name . ' is not actually fillable, so leaving it empty proves nothing',
            );
        }

        // And the real thing: the filler is given no value for either, so
        // neither rectangle has anything in it.
        $drawn = $this->drawnRuns($this->fill());
        foreach (['ort_datum', 'unterschrift'] as $name) {
            self::assertSame(
                [],
                $this->runsInside($drawn, $fields[$name]),
                $name . ' was written into',
            );
        }
    }

    /**
     * Every `(text) Tj` the filler added, with the baseline it was drawn at.
     *
     * FPDF writes each value as `BT x y Td (…) Tj ET` in its own uncompressed
     * stream, in PDF user space measured from the page's bottom-left — the same
     * space `/Rect` uses, which is what makes the comparison above meaningful
     * rather than approximate.
     *
     * @return list<array{x: float, y: float, text: string}>
     */
    private function drawnRuns(string $pdf): array
    {
        $runs = [];

        foreach ($this->contentStreams($pdf) as $stream) {
            if (preg_match_all('~BT\s+([0-9.-]+)\s+([0-9.-]+)\s+Td\s*\((.*?)\)\s*Tj~s', $stream, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $runs[] = ['x' => (float) $match[1], 'y' => (float) $match[2], 'text' => $match[3]];
                }
            }
        }

        return $runs;
    }

    /**
     * @param list<array{x: float, y: float, text: string}> $runs
     * @param array{0: float, 1: float, 2: float, 3: float} $rect
     * @return list<string>
     */
    private function runsInside(array $runs, array $rect): array
    {
        [$x1, $y1, $x2, $y2] = $rect;

        return array_values(array_map(
            static fn(array $run): string => $run['text'],
            array_filter(
                $runs,
                static fn(array $run): bool => $run['x'] >= $x1 - 1 && $run['x'] <= $x2 + 1
                    && $run['y'] >= $y1 - 1 && $run['y'] <= $y2 + 1,
            ),
        ));
    }

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