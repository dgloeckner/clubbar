<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Registrations\Documents;

use App\Modules\Registrations\Documents\QrPosterService;
use PHPUnit\Framework\TestCase;

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
     * Rasterise the poster and read the code back.
     *
     * Skips — loudly — when `pdftoppm` or a decoder is missing, rather than
     * degrading into a test that only proves a PDF was written.
     */
    private function decode(string $pdf): ?string
    {
        if (self::which('pdftoppm') === null) {
            self::markTestSkipped('pdftoppm (poppler-utils) is needed to rasterise the poster.');
        }

        $dir = sys_get_temp_dir() . '/clubbar-poster-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        try {
            file_put_contents($dir . '/poster.pdf', $pdf);
            exec(sprintf('pdftoppm -f 1 -l 1 -r 150 -png %s %s 2>/dev/null', escapeshellarg($dir . '/poster.pdf'), escapeshellarg($dir . '/page')));

            $images = glob($dir . '/page*.png') ?: [];
            self::assertNotSame([], $images, 'The poster produced no page to decode.');

            $script = <<<'PY'
                import sys
                try:
                    from PIL import Image
                    from pyzbar.pyzbar import decode
                except ImportError:
                    print("SKIP")
                    sys.exit(0)
                found = decode(Image.open(sys.argv[1]))
                print(found[0].data.decode() if found else "NONE")
                PY;

            file_put_contents($dir . '/decode.py', $script);
            $output = trim((string) shell_exec(sprintf('python3 %s %s 2>/dev/null', escapeshellarg($dir . '/decode.py'), escapeshellarg($images[0]))));

            if ($output === 'SKIP' || $output === '') {
                self::markTestSkipped('pyzbar/Pillow are needed to decode the poster.');
            }

            self::assertNotSame('NONE', $output, 'No QR code was found on the poster at all.');

            return $output;
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($dir);
        }
    }

    private static function which(string $binary): ?string
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));

        return $path === '' ? null : $path;
    }
}
