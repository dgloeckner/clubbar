<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Pdf;

use App\Shared\Mail\MailLayout;
use App\Shared\Pdf\BrandedSheet;
use PHPUnit\Framework\TestCase;

/**
 * The primitives every printed sheet is drawn with (#783).
 *
 * The drawing itself is asserted where it is composed — {@see \Tests\Unit\
 * Modules\Registrations\Documents\QrPosterServiceTest} decodes an actual
 * poster. What is worth pinning here is the two places this class can be
 * silently wrong: the palette it claims to mirror, and the measuring that
 * every layout on top of it divides its space by.
 */
final class BrandedSheetTest extends TestCase
{
    /**
     * The whole point of the class: one palette, defined in the mail.
     *
     * A copied hex code is a hex code that drifts, and the drift is invisible —
     * a poster two shades off the club's mail looks fine on its own and wrong
     * beside it. So these are identities, not values: a test naming `#C8332E`
     * would be the fourth copy of the thing being prevented.
     */
    public function test_the_palette_is_the_mails(): void
    {
        self::assertSame(MailLayout::RED, BrandedSheet::RED);
        self::assertSame(MailLayout::RED_DARK, BrandedSheet::RED_DARK);
        self::assertSame(MailLayout::RED_SOFT, BrandedSheet::RED_SOFT);
        self::assertSame(MailLayout::PETROL, BrandedSheet::PETROL);
        self::assertSame(MailLayout::INK_900, BrandedSheet::INK_900);
        self::assertSame(MailLayout::INK_700, BrandedSheet::INK_700);
        self::assertSame(MailLayout::INK_500, BrandedSheet::INK_500);
        self::assertSame(MailLayout::INK_200, BrandedSheet::INK_200);
        self::assertSame(MailLayout::INK_050, BrandedSheet::INK_050);
        self::assertSame(MailLayout::WHITE, BrandedSheet::WHITE);
    }

    /** A hex string reaches the content stream as the colour it names. */
    public function test_a_hex_colour_is_drawn_as_that_colour(): void
    {
        $pdf = $this->sheet();
        $pdf->paint(BrandedSheet::PETROL);
        $pdf->Rect(10, 10, 100, 20, 'F');

        // #24363E — 36, 54, 62 — as PDF writes device RGB.
        self::assertStringContainsString('0.141 0.212 0.243 rg', $this->content($pdf));
    }

    public function test_it_wraps_on_spaces_and_keeps_every_word(): void
    {
        $pdf = $this->sheet();
        $pdf->SetFont(BrandedSheet::SANS, '', 12);

        $lines = $pdf->wrap('one two three four five six seven eight nine ten', 60.0);

        self::assertGreaterThan(1, count($lines));
        self::assertSame(
            'one two three four five six seven eight nine ten',
            implode(' ', $lines),
        );
    }

    /**
     * Tracking is not in the font metrics, and forgetting it is how a
     * letterspaced club name runs off the edge of its band: forty characters
     * of capitals at 0.8pt is thirty points the wrap did not know about.
     */
    public function test_tracking_makes_a_line_break_earlier(): void
    {
        $pdf = $this->sheet();
        $pdf->SetFont(BrandedSheet::SANS, 'B', 12);

        $text = 'RUDERCLUB MUSTERSTADT E.V.';
        $plain = $pdf->wrap($text, 120.0);
        $tracked = $pdf->wrap($text, 120.0, 0.8);

        self::assertGreaterThan(count($plain), count($tracked));
        self::assertSame(
            $pdf->GetStringWidth('ABC') + 2 * 0.8,
            $pdf->trackedWidth('ABC', 0.8),
        );
    }

    /** An empty string still occupies a line, so callers can measure it. */
    public function test_empty_text_is_one_empty_line(): void
    {
        $pdf = $this->sheet();
        $pdf->SetFont(BrandedSheet::SANS, '', 12);

        self::assertSame([''], $pdf->wrap('   ', 100.0));
    }

    /**
     * A word wider than the line overhangs rather than being cut. On these
     * sheets every long token is a club's name, and half a name is worse than
     * a wide one.
     */
    public function test_a_word_longer_than_the_line_survives_whole(): void
    {
        $pdf = $this->sheet();
        $pdf->SetFont(BrandedSheet::SANS, '', 12);

        self::assertSame(
            ['Oberammergau-Unterammergau-Kleinsiedlung'],
            $pdf->wrap('Oberammergau-Unterammergau-Kleinsiedlung', 20.0),
        );
    }

    /**
     * Rounded corners are drawn as curves, and only the ones asked for. A band
     * sitting inside the card rounds the two corners it touches; square ones
     * would poke out through the card's own edge.
     */
    public function test_only_the_requested_corners_are_curved(): void
    {
        $all = $this->sheet();
        $all->roundedRect(10, 10, 100, 50, 8.0, 'F');

        $top = $this->sheet();
        $top->roundedRect(10, 10, 100, 50, 8.0, 'F', [true, true, false, false]);

        $none = $this->sheet();
        $none->roundedRect(10, 10, 100, 50, 0.0, 'F');

        self::assertSame(4, substr_count($this->content($all), ' c'));
        self::assertSame(2, substr_count($this->content($top), ' c'));
        self::assertSame(0, substr_count($this->content($none), ' c'));
    }

    /** A radius larger than the box would turn it inside out. */
    public function test_the_radius_is_capped_at_half_the_box(): void
    {
        $pdf = $this->sheet();
        $pdf->roundedRect(10, 10, 40, 20, 500.0, 'F');

        self::assertStringStartsWith('%PDF-', $pdf->Output('S'));
    }

    /** Core fonts are Latin-1, and clubs have umlauts in their names. */
    public function test_umlauts_are_transliterated_rather_than_dropped(): void
    {
        self::assertSame(
            (string) iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'RV Grünwald'),
            BrandedSheet::latin1('RV Grünwald'),
        );
    }

    private function sheet(): BrandedSheet
    {
        $pdf = new BrandedSheet('P', 'pt', [595.28, 841.89]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCompression(false);
        $pdf->AddPage();

        return $pdf;
    }

    /** The page's drawing operators, uncompressed. */
    private function content(BrandedSheet $pdf): string
    {
        return (string) $pdf->Output('S');
    }
}
