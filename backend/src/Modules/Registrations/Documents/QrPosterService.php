<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use setasign\Fpdi\Fpdi;

/**
 * The sheet that goes on the clubhouse wall (#783).
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
 */
class QrPosterService
{
    /** A4 portrait, in points. */
    private const WIDTH = 595.28;
    private const HEIGHT = 841.89;

    /**
     * How wide the QR is printed.
     *
     * Generous on purpose: this is scanned from a metre away, in a clubhouse,
     * by whatever phone somebody has. A small code that needs a close approach
     * is a code people give up on.
     */
    private const QR_SIZE = 300.0;

    /**
     * @param string $url the onboarding URL, secret in its fragment
     * @param string $clubName printed above the code, so a photographed poster
     *        is identifiable as this club's rather than any club's
     * @param string $language `de` or `en` — the poster's own words, and the
     *        only place this feature has a language of its own, because the
     *        club's documents are whatever the club published (decision 6)
     */
    public function render(string $url, string $clubName, string $language = 'de'): string
    {
        $words = $this->words($language);
        $matrix = $this->matrix($url);

        $pdf = new Fpdi('P', 'pt', [self::WIDTH, self::HEIGHT]);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $pdf->SetTextColor(26, 26, 46);

        // Optically centred rather than top-aligned: this is pinned to a wall
        // at eye height, and a block hugging the top of the sheet reads as a
        // page that was cut off.
        $pdf->SetFont('Helvetica', 'B', 24);
        $this->centred($pdf, 150, $this->latin1($clubName), 24);

        $pdf->SetFont('Helvetica', 'B', 32);
        $this->centred($pdf, 205, $this->latin1($words['headline']), 32);

        $pdf->SetFont('Helvetica', '', 15);
        $this->centred($pdf, 243, $this->latin1($words['lede']), 15);

        $this->drawMatrix($pdf, $matrix, (self::WIDTH - self::QR_SIZE) / 2, 290.0);

        $pdf->SetFont('Helvetica', '', 14);
        $this->centred($pdf, 645, $this->latin1($words['steps']), 14);

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetTextColor(92, 92, 114);
        $this->centred($pdf, 685, $this->latin1($words['note']), 11);

        return (string) $pdf->Output('S');
    }

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
     * @param list<list<bool>> $matrix
     */
    private function drawMatrix(Fpdi $pdf, array $matrix, float $left, float $top): void
    {
        $modules = count($matrix);
        if ($modules === 0) {
            return;
        }

        $module = self::QR_SIZE / $modules;
        $pdf->SetFillColor(26, 26, 46);

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

    private function centred(Fpdi $pdf, float $y, string $text, float $fontSize): void
    {
        $pdf->Text((self::WIDTH - $pdf->GetStringWidth($text)) / 2, $y + $fontSize * 0.35, $text);
    }

    /** @return array{headline: string, lede: string, steps: string, note: string} */
    private function words(string $language): array
    {
        if ($language === 'en') {
            return [
                'headline' => 'Pay at the bar with your card',
                'lede' => 'Scan the code to register.',
                'steps' => 'Scan · fill in · print and sign · hand it to the treasurer',
                'note' => 'Only club members. Your account works once the treasurer has checked the signed form.',
            ];
        }

        return [
            'headline' => 'Bargeldlos an der Theke zahlen',
            'lede' => 'Zum Anmelden den Code scannen.',
            'steps' => 'Scannen · ausfüllen · ausdrucken und unterschreiben · beim Kassenwart abgeben',
            'note' => 'Nur für Vereinsmitglieder. Das Konto funktioniert, sobald der Kassenwart das unterschriebene Formular geprüft hat.',
        ];
    }

    /** Core fonts are Latin-1, and clubs have umlauts in their names. */
    private function latin1(string $value): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value);

        return $converted === false ? $value : $converted;
    }
}
