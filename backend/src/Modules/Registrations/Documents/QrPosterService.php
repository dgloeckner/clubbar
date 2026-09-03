<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

use App\Shared\Pdf\BrandedSheet;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * The sheet that goes on the clubhouse wall (#783).
 *
 * ## It wears the club's mail
 *
 * The palette, the paper masthead with its red rule, the serif headline over a
 * grotesque lede, the note box with the red bar and the petrol footer band all
 * come from {@see \App\Shared\Mail\MailLayout} by way of {@see BrandedSheet},
 * which reads its constants rather than restating them. That is the same move
 * `backend/public/register/register.css` makes, and for the same reason: this
 * poster, the onboarding page it leads to and the mail that arrives afterwards
 * are three surfaces of one club, met minutes and days apart by a person who is
 * being asked to type their IBAN into a phone. If they do not look like the
 * same club, the design is doing the opposite of its job.
 *
 * ## The QR is generated here, and that is a requirement rather than a habit
 *
 * The URL it encodes *is* the credential — the poster secret rides in its
 * fragment — so handing it to a third-party QR service would publish the club's
 * gate to a host nobody chose. `chillerlan/php-qrcode` is already a dependency
 * (it draws the TOTP enrolment codes), and the matrix it produces is drawn here
 * as PDF rectangles: vector, so it stays scannable at any print size, and no
 * image library is needed on a shared host.
 *
 * ## Why the URL is not printed as text
 *
 * A poster that spelled out the secret would leak it to every photograph of the
 * wall, and it is unusable by hand anyway — 43 random characters typed into a
 * phone is not a path anybody takes. The QR is the only way in, which is also
 * what makes rotation meaningful.
 *
 * ## Why the club's logo is not on it
 *
 * `mail_config.logo_url` is an arbitrary `http(s)` URL an admin typed. Drawing
 * it here would mean this endpoint fetching a URL of the admin's choosing from
 * the server, which is a request-forgery primitive handed to a feature that
 * only wants a decoration. The mail has the same problem and already answers
 * it: when a client blocks images "the wordmark carries the sender on its own.
 * That is why it is text." On paper it is text for the same reason.
 */
class QrPosterService
{
    /** A4 portrait, in points. */
    private const WIDTH = 595.28;
    private const HEIGHT = 841.89;

    /**
     * How far the sheet is inset.
     *
     * The design is a card — the mail's 600px card, the onboarding page's
     * sheet — and on paper a card needs somewhere to sit. It is also what keeps
     * every band inside the unprintable edge that office printers reserve: a
     * masthead bled to the paper edge comes back with a white frame around it
     * and a red rule cut in half.
     */
    private const MARGIN = 34.0;

    /** MailLayout's 30px content padding. */
    private const PAD = 30.0;

    /** The mail card's 12px corner. */
    private const RADIUS = 12.0;

    /**
     * How wide the QR is printed, at most.
     *
     * Generous on purpose: this is scanned from a metre away, in a clubhouse,
     * by whatever phone somebody has. A small code that needs a close approach
     * is a code people give up on. It is a *ceiling* rather than a size because
     * the words above and below it are translated and a club's name wraps —
     * {@see render()} lays the sheet out and gives the code every point the
     * text did not need, which on A4 is comfortably more than this.
     */
    private const QR_MAX = 300.0;

    /**
     * The panel's padding, and nothing more.
     *
     * A QR needs a quiet zone of four modules to be found at all, and this is
     * *not* it: `chillerlan/php-qrcode` puts the quiet zone inside the matrix
     * it returns — a 37-module code arrives as a 45-module grid whose outer
     * four rings are blank — so the margin is already drawn by the time this
     * padding is applied. Sizing the padding as if it were the quiet zone
     * would spend the same space twice and shrink the code for nothing.
     */
    private const QR_PAD = 16.0;

    /** The club's mark in the masthead, at MailLayout's header scale. */
    private const WORDMARK_SIZE = 16.0;
    private const WORDMARK_TRACKING = 0.8;

    /** The steps under the code: the panel's caption, and the sheet's smallest type. */
    private const CAPTION_SIZE = 13.0;
    private const CAPTION_LEADING = 18.0;

    /**
     * @param string $url the onboarding URL, secret in its fragment
     * @param string $clubName printed in the masthead and the footer, so a
     *        photographed poster is identifiable as this club's rather than any
     *        club's. Blank drops both bands rather than printing empty ones,
     *        which is what the onboarding page does with the same fact
     * @param string $language `de` or `en` — the poster's own words, and the
     *        only place this feature has a language of its own, because the
     *        club's documents are whatever the club published (decision 6)
     */
    public function render(string $url, string $clubName, string $language = 'de'): string
    {
        $words = $this->words($language);
        $matrix = $this->matrix($url);
        $clubName = trim($clubName);

        $pdf = new BrandedSheet('P', 'pt', [self::WIDTH, self::HEIGHT]);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $cardX = self::MARGIN;
        $cardY = self::MARGIN;
        $cardW = self::WIDTH - 2 * self::MARGIN;
        $cardH = self::HEIGHT - 2 * self::MARGIN;
        $textX = $cardX + self::PAD;
        $textW = $cardW - 2 * self::PAD;

        $pdf->paint(BrandedSheet::WHITE);
        $pdf->stroke(BrandedSheet::INK_200);
        $pdf->SetLineWidth(1);
        $pdf->roundedRect($cardX, $cardY, $cardW, $cardH, self::RADIUS, 'FD');

        // Measured before anything is drawn: the code is given whatever the
        // words leave, so every word has to be wrapped first.
        $masthead = $this->mastheadHeight($pdf, $clubName, $textW);
        $footer = $clubName === '' ? 0.0 : $this->footerHeight();
        $note = $this->noteLines($pdf, $words['note'], $textW);
        $noteHeight = $this->noteHeight(count($note));

        $top = $cardY + $masthead + self::PAD;
        $bottom = $cardY + $cardH - $footer - self::PAD;

        $this->drawMasthead($pdf, $clubName, $cardX, $cardY, $cardW, $masthead, $textX, $textW);

        $top = $this->drawEyebrow($pdf, $words['eyebrow'], $textX, $top);
        $top = $this->drawTitle($pdf, $words['headline'], $textX, $top, $textW);
        $top = $this->drawLede($pdf, $words['lede'], $textX, $top, $textW);

        $noteTop = $bottom - $noteHeight;
        $this->drawNote($pdf, $words['noteHeading'], $note, $textX, $noteTop, $textW);

        $this->drawCodePanel($pdf, $matrix, $words['steps'], $textX, $top, $textW, $noteTop - self::PAD - $top);

        if ($clubName !== '') {
            $this->drawFooter($pdf, $clubName, $cardX, $cardY + $cardH - $footer, $cardW, $footer, $textX);
        }

        return (string) $pdf->Output('S');
    }

    /* ── the bands ────────────────────────────────────────────────────────── */

    /**
     * MailLayout's `paper` header: the club's mark in small letterspaced
     * capitals on the palest ground, over a red rule. A long club name wraps
     * onto a second line and the band grows, rather than the name running off
     * the sheet — the same thing `overflow-wrap:anywhere` does on the
     * onboarding page.
     */
    private function mastheadHeight(BrandedSheet $pdf, string $clubName, float $textW): float
    {
        if ($clubName === '') {
            // No name to show, so the band is only its red rule — the one part
            // of the masthead that is design rather than content.
            return 3.0;
        }

        $lines = $this->wordmarkLines($pdf, $clubName, $textW);

        return 26.0 + count($lines) * $this->wordmarkLeading($pdf) + 26.0 + 3.0;
    }

    /**
     * The club's name as the masthead prints it, with the font left set to the
     * size it chose.
     *
     * Two lines at most, and the type shrinks rather than the name being cut:
     * a club whose name does not fit is a club whose name is on the poster in
     * smaller capitals, not one whose poster says `TURN- UND SPORTVEREIN
     * OBERAMMERGAU-UNTERAMMERGAU` and stops. Measured against 1.9 lines rather
     * than 2 because wrapping never uses a line's last few points, and a name
     * that lands a single word on a third line would lose that word.
     *
     * @return list<string>
     */
    private function wordmarkLines(BrandedSheet $pdf, string $clubName, float $textW): array
    {
        $name = BrandedSheet::latin1($this->upper($clubName));
        $size = self::WORDMARK_SIZE;

        // Tried rather than calculated, which {@see fit()} is not. Its
        // arithmetic is exact for one line and meaningless for two: wrapping
        // is lumpy — `OBERAMMERGAU-UNTERAMMERGAU-KLEINSIEDLUNG` is a single
        // 40-character token that either fits a line or takes one of its own —
        // so the only honest way to find the size that lands in two lines is
        // to set it and look. Fourteen half-point steps at worst, on a
        // document that is generated when an admin clicks Print.
        while (true) {
            $pdf->SetFont(BrandedSheet::SANS, 'B', $size);
            $lines = $pdf->wrap($name, $textW, self::WORDMARK_TRACKING);

            if (count($lines) <= 2 || $size <= 9.0) {
                return array_slice($lines, 0, 2);
            }

            $size -= 0.5;
        }
    }

    private function wordmarkLeading(BrandedSheet $pdf): float
    {
        return $pdf->fontSize() * 1.3;
    }

    private function drawMasthead(
        BrandedSheet $pdf,
        string $clubName,
        float $cardX,
        float $cardY,
        float $cardW,
        float $height,
        float $textX,
        float $textW,
    ): void {
        if ($height > 3.0) {
            $pdf->paint(BrandedSheet::INK_050);
            $pdf->roundedRect($cardX, $cardY, $cardW, $height - 3.0, self::RADIUS, 'F', [true, true, false, false]);
        }

        $pdf->paint(BrandedSheet::RED);
        $pdf->Rect($cardX, $cardY + $height - 3.0, $cardW, 3.0, 'F');

        if ($clubName === '') {
            return;
        }

        $lines = $this->wordmarkLines($pdf, $clubName, $textW);
        $leading = $this->wordmarkLeading($pdf);

        $pdf->ink(BrandedSheet::INK_900);
        $pdf->tracking(self::WORDMARK_TRACKING);

        $y = $cardY + 26.0;
        foreach ($lines as $line) {
            $pdf->Text($textX, $y + $pdf->fontSize() * 0.95, $line);
            $y += $leading;
        }

        $pdf->tracking(0);
    }

    private function footerHeight(): float
    {
        return 46.0;
    }

    /**
     * The mail's petrol footer, carrying the one thing it carries there: who
     * this is from.
     */
    private function drawFooter(
        BrandedSheet $pdf,
        string $clubName,
        float $cardX,
        float $y,
        float $cardW,
        float $height,
        float $textX,
    ): void {
        $pdf->paint(BrandedSheet::PETROL);
        $pdf->roundedRect($cardX, $y, $cardW, $height, self::RADIUS, 'F', [false, false, true, true]);

        $name = BrandedSheet::latin1($clubName);
        // One line, always: the band is a fixed strip and a club with a very
        // long name would otherwise have it printed across the sheet's edge.
        $this->fit($pdf, 'B', 14.0, $name, $cardW - 2 * self::PAD, 9.0);
        $pdf->ink(BrandedSheet::WHITE);
        $pdf->Text($textX, $y + 28.0, $name);
    }

    /* ── the content ──────────────────────────────────────────────────────── */

    /** The small red kicker above the heading. */
    private function drawEyebrow(BrandedSheet $pdf, string $text, float $x, float $top): float
    {
        $pdf->SetFont(BrandedSheet::SANS, 'B', 12);
        $pdf->ink(BrandedSheet::RED);
        $pdf->tracking(1.7);
        $pdf->Text($x, $top + 11.0, BrandedSheet::latin1($this->upper($text)));
        $pdf->tracking(0);

        return $top + 26.0;
    }

    /** The serif headline — the mail's `title`, at the size a wall needs. */
    private function drawTitle(BrandedSheet $pdf, string $text, float $x, float $top, float $width): float
    {
        $pdf->SetFont(BrandedSheet::DISPLAY, '', 32);
        $pdf->ink(BrandedSheet::INK_900);

        foreach ($pdf->wrap(BrandedSheet::latin1($text), $width) as $line) {
            $pdf->Text($x, $top + 30.0, $line);
            $top += 39.0;
        }

        return $top + 14.0;
    }

    private function drawLede(BrandedSheet $pdf, string $text, float $x, float $top, float $width): float
    {
        $pdf->SetFont(BrandedSheet::SANS, '', 17);
        $pdf->ink(BrandedSheet::INK_700);

        foreach ($pdf->wrap(BrandedSheet::latin1($text), $width) as $line) {
            $pdf->Text($x, $top + 16.0, $line);
            $top += 26.0;
        }

        return $top + 18.0;
    }

    /**
     * The code, on the mail's data-table ground: palest ink, hairline border.
     *
     * The steps ride inside the panel rather than under it, because they are a
     * caption to the code — read after somebody has looked at what they are
     * about to scan, not as a block of their own.
     *
     * @param list<list<bool>> $matrix
     */
    private function drawCodePanel(
        BrandedSheet $pdf,
        array $matrix,
        string $steps,
        float $x,
        float $top,
        float $width,
        float $height,
    ): void {
        $pdf->paint(BrandedSheet::INK_050);
        $pdf->stroke(BrandedSheet::INK_200);
        $pdf->SetLineWidth(1);
        $pdf->roundedRect($x, $top, $width, $height, 8.0, 'FD');

        $inner = $width - 2 * self::QR_PAD;
        $lines = $this->caption($pdf, BrandedSheet::latin1($steps), $inner);
        $captionHeight = count($lines) * self::CAPTION_LEADING + 12.0;

        $size = min(self::QR_MAX, $inner, $height - 2 * self::QR_PAD - $captionHeight);
        $this->drawMatrix($pdf, $matrix, $x + ($width - $size) / 2, $top + ($height - $captionHeight - $size) / 2, $size);

        $pdf->ink(BrandedSheet::INK_500);
        $y = $top + $height - self::QR_PAD - count($lines) * self::CAPTION_LEADING + 12.0;
        foreach ($lines as $line) {
            $pdf->Text($x + ($width - $pdf->GetStringWidth($line)) / 2, $y, $line);
            $y += self::CAPTION_LEADING;
        }
    }

    /**
     * The steps, set to fit the panel on one line.
     *
     * A caption that wraps has stopped being a caption: the second line of a
     * centred grey run-on is an orphan, and "beim Kassenwart / abgeben" reads
     * as two instructions rather than one.
     *
     * @return list<string>
     */
    private function caption(BrandedSheet $pdf, string $steps, float $width): array
    {
        $this->fit($pdf, '', self::CAPTION_SIZE, $steps, $width, 10.5);

        return $pdf->wrap($steps, $width);
    }

    /**
     * Select the largest size up to `$size` at which the text is one line, and
     * leave the font set to it.
     *
     * Text width in PDF is linear in the font size, so the size that fits is
     * arithmetic rather than a search — one step, no loop, exact. The floor
     * matters: a string long enough to need 8pt has a problem that shrinking
     * cannot solve, and illegible is worse than wrapped or clipped.
     */
    private function fit(BrandedSheet $pdf, string $style, float $size, string $text, float $width, float $floor): void
    {
        $pdf->SetFont(BrandedSheet::SANS, $style, $size);

        $needed = $pdf->GetStringWidth($text);
        if ($needed > $width) {
            $pdf->SetFont(BrandedSheet::SANS, $style, max($floor, $size * $width / $needed));
        }
    }

    /**
     * MailLayout's note box — red bar down the left, soft red ground — used for
     * what the mail and the onboarding page both use it for: the one line that
     * must not be skimmed past. Square-cornered, like both of them.
     *
     * @param list<string> $lines
     */
    private function drawNote(BrandedSheet $pdf, string $heading, array $lines, float $x, float $top, float $width): void
    {
        $height = $this->noteHeight(count($lines));

        $pdf->paint(BrandedSheet::RED_SOFT);
        $pdf->Rect($x, $top, $width, $height, 'F');
        $pdf->paint(BrandedSheet::RED);
        $pdf->Rect($x, $top, 4.0, $height, 'F');

        $textX = $x + 20.0;

        $pdf->SetFont(BrandedSheet::SANS, 'B', 11);
        $pdf->ink(BrandedSheet::RED_DARK);
        $pdf->tracking(1.3);
        $pdf->Text($textX, $top + 16.0 + 10.0, BrandedSheet::latin1($this->upper($heading)));
        $pdf->tracking(0);

        $pdf->SetFont(BrandedSheet::SANS, '', 14);
        $pdf->ink(BrandedSheet::INK_700);
        $y = $top + 16.0 + 16.0 + 6.0;
        foreach ($lines as $line) {
            $pdf->Text($textX, $y + 13.0, $line);
            $y += 19.0;
        }
    }

    /** @return list<string> */
    private function noteLines(BrandedSheet $pdf, string $text, float $width): array
    {
        $pdf->SetFont(BrandedSheet::SANS, '', 14);

        return $pdf->wrap(BrandedSheet::latin1($text), $width - 40.0);
    }

    private function noteHeight(int $lines): float
    {
        return 16.0 + 16.0 + 6.0 + $lines * 19.0 + 16.0;
    }

    /* ── the code itself ──────────────────────────────────────────────────── */

    /**
     * The QR as a boolean grid.
     *
     * `ECC_M` rather than the lowest level: a poster acquires a thumbtack hole,
     * a coffee ring and a bit of sun, and medium correction survives all three
     * without meaningfully enlarging the code.
     *
     * @return list<list<bool>>
     */
    private function matrix(string $url): array
    {
        // `addByteSegment()` and *then* `getQRMatrix()`: the matrix getter takes
        // no argument, so passing the URL to it encodes nothing at all — a
        // perfectly scannable code carrying an empty string, which looks right
        // on paper and fails only when somebody points a phone at it. Caught by
        // decoding the rendered poster in `QrPosterServiceTest`, which is why
        // that test decodes rather than merely checking a code is present.
        $matrix = (new QRCode(new QROptions(['eccLevel' => EccLevel::M])))
            ->addByteSegment($url)
            ->getQRMatrix();
        $size = $matrix->getSize();

        $grid = [];
        for ($y = 0; $y < $size; $y++) {
            $row = [];
            for ($x = 0; $x < $size; $x++) {
                $row[] = $matrix->check($x, $y);
            }
            $grid[] = $row;
        }

        return $grid;
    }

    /**
     * Draw the grid as filled rectangles.
     *
     * One rectangle per dark module, sized to a whole number of *device* units
     * only in the sense that the module size divides the requested width — PDF
     * is vector, so there is no pixel grid to align to and no scaling artefact
     * to avoid. A neighbouring-module overlap of a hair is deliberate: adjacent
     * fills that merely touch can leave a hairline seam in some renderers, and
     * a seam across a finder pattern is a code that will not scan.
     *
     * Drawn in the design's darkest ink rather than pure black. The contrast
     * against the panel it sits on is 15:1 either way — far past anything a
     * decoder cares about — and it is the ink every other mark on the sheet is
     * made of.
     *
     * @param list<list<bool>> $matrix
     */
    private function drawMatrix(BrandedSheet $pdf, array $matrix, float $left, float $top, float $size): void
    {
        $modules = count($matrix);
        if ($modules === 0) {
            return;
        }

        $module = $size / $modules;
        $pdf->paint(BrandedSheet::INK_900);

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if (!$dark) {
                    continue;
                }

                $pdf->Rect(
                    $left + $x * $module,
                    $top + $y * $module,
                    $module + 0.05,
                    $module + 0.05,
                    'F'
                );
            }
        }
    }

    /**
     * Small capitals are made here rather than by the font: the core fonts have
     * no small-caps variant, and `strtoupper()` is byte-wise — it would leave a
     * German umlaut alone while uppercasing everything around it.
     */
    private function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /** @return array{eyebrow: string, headline: string, lede: string, steps: string, noteHeading: string, note: string} */
    private function words(string $language): array
    {
        if ($language === 'en') {
            return [
                'eyebrow' => 'Registration',
                'headline' => 'Pay at the bar with your card',
                'lede' => 'Scan the code to register.',
                'steps' => 'Scan · fill in · print and sign · hand it to the treasurer',
                'noteHeading' => 'Please note',
                'note' => 'Only club members. Your account works once the treasurer has checked the signed form.',
            ];
        }

        return [
            'eyebrow' => 'Anmeldung',
            'headline' => 'Bargeldlos an der Theke zahlen',
            'lede' => 'Zum Anmelden den Code scannen.',
            'steps' => 'Scannen · ausfüllen · ausdrucken und unterschreiben · beim Kassenwart abgeben',
            'noteHeading' => 'Hinweis',
            'note' => 'Nur für Vereinsmitglieder. Das Konto funktioniert, sobald der Kassenwart das unterschriebene Formular geprüft hat.',
        ];
    }
}
