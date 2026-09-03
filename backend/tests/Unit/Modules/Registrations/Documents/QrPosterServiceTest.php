<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Documents;

use App\Modules\Registrations\Documents\QrPosterService;
use App\Shared\Mail\MailLayout;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * The poster that goes on the clubhouse wall (#783).
 *
 * ## Why the code is decoded rather than merely counted
 *
 * A QR code is the one graphic where "it rendered" and "it works" are entirely
 * different claims, and the first is worthless. The first version of this class
 * passed `$url` to `getQRMatrix()` — which takes no argument — and produced a
 * flawless, perfectly scannable code carrying **the empty string**. It looked
 * exactly right on paper. Nothing but pointing a decoder at it would have said
 * otherwise, and a club would have found out from a member standing at the wall.
 *
 * So the assertion is: render the PDF, rasterise it, decode it, and compare the
 * result to the URL that went in. The tooling for that is not always present,
 * and the test skips rather than passes when it is missing — a decode test that
 * quietly degrades to "a PDF was produced" is the thing this exists to prevent.
 */
final class QrPosterServiceTest extends TestCase
{
    use TempTree;

    private const URL = 'https://club.example/register#kY3n-2Qb7xLp0aZm9RtVwSdEfGhJkLmN';

    public function test_it_renders_a_pdf(): void
    {
        $poster = (new QrPosterService())->render(self::URL, 'Ruderclub Musterstadt e.V.');

        self::assertStringStartsWith('%PDF-', $poster);
    }

    /** The whole point: the code carries the URL, fragment and all. */
    public function test_the_code_decodes_to_the_onboarding_url(): void
    {
        self::assertSame(self::URL, $this->decode((new QrPosterService())->render(self::URL, 'Club')));
    }

    /**
     * The secret lives in the fragment, so a decoder that dropped it would
     * produce a poster leading to the "this link no longer works" screen — for
     * every member, permanently, with the code looking perfect.
     */
    public function test_the_fragment_survives_into_the_code(): void
    {
        $decoded = $this->decode((new QrPosterService())->render(self::URL, 'Club'));

        self::assertStringContainsString('#kY3n-2Qb7xLp0aZm9RtVwSdEfGhJkLmN', (string) $decoded);
    }

    /**
     * The club's name identifies a photographed poster as *this* club's. Drawn
     * with a core font, so it is literal in the content stream.
     */
    public function test_the_club_name_is_printed(): void
    {
        $poster = $this->readableText((new QrPosterService())->render(self::URL, 'Ruderclub Musterstadt'));

        self::assertStringContainsString('Ruderclub Musterstadt', $poster);
    }

    /** Umlauts through Latin-1 — a club called Grünwald is not an edge case. */
    public function test_a_club_name_with_umlauts_survives(): void
    {
        $poster = $this->readableText((new QrPosterService())->render(self::URL, 'RV Grünwald'));

        self::assertStringContainsString((string) iconv('UTF-8', 'ISO-8859-1//TRANSLIT', 'Grünwald'), $poster);
    }

    /**
     * The poster is the one place in this feature with a language of its own:
     * the club's *documents* are whatever the club published, but the sheet
     * saying "scan this" is clubbar's own words (ADR-0052 decision 6).
     */
    public function test_the_poster_speaks_the_language_it_was_asked_for(): void
    {
        $german = $this->readableText((new QrPosterService())->render(self::URL, 'Club', 'de'));
        $english = $this->readableText((new QrPosterService())->render(self::URL, 'Club', 'en'));

        self::assertStringContainsString('Zum Anmelden', $german);
        self::assertStringContainsString('Scan the code', $english);
    }

    /**
     * The URL is never printed as text.
     *
     * A poster spelling out the secret would leak it to every photograph of the
     * wall — and it is unusable by hand anyway: nobody types 43 random
     * characters into a phone. The QR being the only way in is also what makes
     * rotation mean anything.
     */
    public function test_the_secret_is_never_printed_in_readable_text(): void
    {
        $poster = (new QrPosterService())->render(self::URL, 'Club');

        // Both the raw file and its inflated streams: FPDF compresses what it
        // draws, so a raw-bytes check alone would pass for a poster that *did*
        // print the secret.
        self::assertStringNotContainsString('kY3n-2Qb7xLp0aZm9RtVwSdEfGhJkLmN', $poster);
        self::assertStringNotContainsString('kY3n-2Qb7xLp0aZm9RtVwSdEfGhJkLmN', $this->readableText($poster));
    }

    /**
     * The sheet is the club's, not a generic one.
     *
     * The mail, the onboarding page and this poster are three surfaces of one
     * club met minutes and days apart, and the whole reason the poster was
     * restyled is that they have to look like it. The assertion is on the
     * *drawn* colours rather than on constants either side of the comparison:
     * a design that is only equal in the source and never reaches the page is
     * exactly the failure a screenshot would catch and a unit test usually
     * does not.
     */
    public function test_the_sheet_is_drawn_in_the_clubs_colours(): void
    {
        $poster = $this->readableText((new QrPosterService())->render(self::URL, 'Club'));

        self::assertStringContainsString($this->fill(MailLayout::RED), $poster, 'no red rule under the masthead');
        self::assertStringContainsString($this->fill(MailLayout::PETROL), $poster, 'no petrol footer band');
        self::assertStringContainsString($this->fill(MailLayout::INK_050), $poster, 'no paper ground');
        self::assertStringContainsString($this->fill(MailLayout::RED_SOFT), $poster, 'no note box');
    }

    /**
     * The club's name is printed twice, as the mail prints it twice: the
     * masthead's letterspaced capitals identify a photographed poster, and the
     * footer band says who this is from.
     */
    public function test_the_name_is_in_both_the_masthead_and_the_footer(): void
    {
        $poster = $this->readableText((new QrPosterService())->render(self::URL, 'Ruderclub Musterstadt'));

        self::assertStringContainsString('RUDERCLUB MUSTERSTADT', $poster);
        self::assertStringContainsString('Ruderclub Musterstadt', $poster);
    }

    /**
     * An instance with no name printed gets no empty bands.
     *
     * A petrol strip carrying nothing is not neutral — it is a footer that
     * failed — and `instance_name` is genuinely absent on an installation that
     * has not been through the panel yet. The onboarding page hides its
     * colophon on the same fact; the sheet keeps its red rule, which is design
     * rather than content, and drops the rest.
     */
    public function test_a_nameless_instance_gets_no_empty_bands(): void
    {
        $poster = $this->readableText((new QrPosterService())->render(self::URL, '   '));

        self::assertStringNotContainsString($this->fill(MailLayout::PETROL), $poster);
        self::assertStringContainsString($this->fill(MailLayout::RED), $poster);
    }

    /**
     * A long name is set smaller, never cut.
     *
     * The masthead is two lines, and the first attempt at this simply sliced
     * whatever did not fit — which on a real club produced a poster reading
     * `TURN- UND SPORTVEREIN OBERAMMERGAU-UNTERAMMERGAU` and stopping, with
     * `VON 1897 E.V.` silently gone. A name that does not fit is printed in
     * smaller capitals.
     */
    public function test_a_long_club_name_is_printed_in_full(): void
    {
        $name = 'Turn-, Sport- und Schwimmverein Oberammergau-Unterammergau-Kleinsiedlung von 1897 e.V.';
        $poster = $this->readableText((new QrPosterService())->render(self::URL, $name));

        foreach (['TURN-,', 'SCHWIMMVEREIN', 'OBERAMMERGAU-UNTERAMMERGAU-KLEINSIEDLUNG', '1897', 'E.V.'] as $word) {
            self::assertStringContainsString($word, $poster, "the masthead dropped $word");
        }
    }

    /**
     * The code has to stay big enough to scan from across a room.
     *
     * The layout gives the code whatever the words leave, so a change to the
     * copy — a longer translation, a second line of headline — comes out of
     * the QR rather than off the page. That is the right trade until it is
     * not: measured on the rasterised sheet, the code is a hand's width.
     */
    public function test_the_code_is_printed_large_enough_to_scan(): void
    {
        $symbol = $this->symbol((new QrPosterService())->render(self::URL, 'Ruderclub Musterstadt e.V.'));

        // The decoder reports where it found the code, so this is the printed
        // size of the code itself — the matrix's own quiet zone and the panel
        // around it excluded. The rasteriser runs at 150dpi, so a point is
        // 150/72 pixels and 200pt is 7cm: scannable well beyond arm's length.
        self::assertGreaterThan((int) round(200 * 150 / 72), $symbol['width']);
    }

    /** `SetFillColor()`'s operator for a hex colour, as it lands in the page. */
    private function fill(string $hex): string
    {
        $hex = ltrim($hex, '#');

        return sprintf(
            '%.3F %.3F %.3F rg',
            hexdec(substr($hex, 0, 2)) / 255,
            hexdec(substr($hex, 2, 2)) / 255,
            hexdec(substr($hex, 4, 2)) / 255,
        );
    }

    /**
     * The text the poster's content streams carry.
     *
     * FPDF compresses what it draws, so the drawn words are not literal bytes
     * in the file — an assertion against the raw PDF passes or fails for
     * reasons unrelated to what is on the page.
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

    /**
     * Rasterise the poster, find the code, and report what it says and how
     * big it is.
     *
     * Skips — loudly — when `pdftoppm` or a decoder is missing, rather than
     * degrading into a test that only proves a PDF was written.
     *
     * @return array{data: string, width: int}
     */
    private function symbol(string $pdf): array
    {
        if (self::which('pdftoppm') === null) {
            self::markTestSkipped('pdftoppm (poppler-utils) is needed to rasterise the poster.');
        }

        $dir = self::makeTempTree('clubbar-poster');

        try {
            file_put_contents($dir . '/poster.pdf', $pdf);
            exec(sprintf('pdftoppm -f 1 -l 1 -r 150 -png %s %s 2>/dev/null', escapeshellarg($dir . '/poster.pdf'), escapeshellarg($dir . '/page')));

            $images = glob($dir . '/page*.png') ?: [];
            self::assertNotSame([], $images, 'The poster produced no page to decode.');

            // The symbol's own rectangle, not the drawn panel: the layout gives
            // the code the space the words leave, so the only honest measure of
            // "big enough to scan" is where a decoder says the code actually is.
            $script = <<<'PY'
                import sys
                try:
                    from PIL import Image
                    from pyzbar.pyzbar import decode
                except ImportError:
                    print("SKIP")
                    sys.exit(0)
                found = decode(Image.open(sys.argv[1]))
                if not found:
                    print("NONE")
                else:
                    print(f"{found[0].rect.width}\t{found[0].data.decode()}")
                PY;

            file_put_contents($dir . '/decode.py', $script);
            $output = trim((string) shell_exec(sprintf('python3 %s %s 2>/dev/null', escapeshellarg($dir . '/decode.py'), escapeshellarg($images[0]))));

            if ($output === 'SKIP' || $output === '') {
                self::markTestSkipped('pyzbar/Pillow are needed to decode the poster.');
            }

            self::assertNotSame('NONE', $output, 'No QR code was found on the poster at all.');

            [$width, $data] = explode("\t", $output, 2);

            return ['data' => $data, 'width' => (int) $width];
        } finally {
            self::removeTempTree($dir);
        }
    }

    /** What the code on the poster says. */
    private function decode(string $pdf): ?string
    {
        return $this->symbol($pdf)['data'];
    }

    private static function which(string $binary): ?string
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));

        return $path === '' ? null : $path;
    }
}
