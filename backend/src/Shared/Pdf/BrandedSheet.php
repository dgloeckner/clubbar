<?php

declare(strict_types=1);

namespace App\Shared\Pdf;

use App\Shared\Mail\MailLayout;
use setasign\Fpdi\Fpdi;

/**
 * A sheet of paper wearing the club's design.
 *
 * ## Why the palette is not defined here
 *
 * {@see MailLayout} is where this project's design lives, and every other
 * surface mirrors it rather than restating it: `backend/public/register/
 * register.css` says so in its header, and this class goes one better by
 * *reading* the constants instead of copying their values — a hex code that
 * changes in the mail changes on the printed sheet in the same commit, with no
 * third place to remember.
 *
 * The values are shared; the technique cannot be. MailLayout writes tables and
 * inline styles because Outlook renders mail with the Word engine, and a
 * browser renders the onboarding page with CSS. Neither exists here: a PDF is
 * drawn, so the same design has to be re-expressed as filled rectangles,
 * baselines and core fonts. What survives the translation is what makes the
 * design recognisable — the warm off-white grounds, the red rule under a paper
 * masthead, the petrol footer band, letterspaced capitals for the club's mark,
 * a serif for the headline and a grotesque for everything else.
 *
 * ## Core fonts only
 *
 * Fraunces and Inter are webfonts; embedding either would add a font file to
 * every generated document and a licence question to the project. The mail
 * already answers this — its fallback stacks are a serif for headings and a
 * grotesque for body text, which is exactly the pairing the PDF core fonts
 * give for free: Times and Helvetica. A club that prints this sheet gets the
 * same *shape* of design as the mail a member receives a week later, which is
 * the point; matching the exact face is not.
 */
class BrandedSheet extends Fpdi
{
    /* The design, borrowed from the mail. Kept as hex so the constants above
     * can be handed straight to the setters. */
    public const RED       = MailLayout::RED;
    public const RED_DARK  = MailLayout::RED_DARK;
    public const RED_SOFT  = MailLayout::RED_SOFT;
    public const PETROL    = MailLayout::PETROL;
    public const INK_900   = MailLayout::INK_900;
    public const INK_700   = MailLayout::INK_700;
    public const INK_500   = MailLayout::INK_500;
    public const INK_200   = MailLayout::INK_200;
    public const INK_050   = MailLayout::INK_050;
    public const WHITE     = MailLayout::WHITE;

    /** The mail's grotesque and its serif, as the core fonts they fall back to. */
    public const SANS    = 'Helvetica';
    public const DISPLAY = 'Times';

    /**
     * Text colour, from a `#rrggbb` string.
     *
     * Hex rather than three integers because that is how the design is written
     * down everywhere else in this project, and a triple is one transcription
     * step away from a colour nobody chose.
     */
    public function ink(string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $this->SetTextColor($r, $g, $b);
    }

    /** Fill colour, from a `#rrggbb` string. */
    public function paint(string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $this->SetFillColor($r, $g, $b);
    }

    /** Stroke colour, from a `#rrggbb` string. */
    public function stroke(string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $this->SetDrawColor($r, $g, $b);
    }

    /**
     * Letterspacing, in points.
     *
     * The club's mark and the red kicker above a heading are set in small
     * letterspaced capitals — 0.05em and 0.14em in the mail — and without the
     * tracking they are merely capitals, which is a different design. PDF's
     * `Tc` is part of the text state and therefore survives the `BT`/`ET`
     * pairs FPDF emits around each string, so it is set once and cleared once.
     *
     * {@see GetStringWidth()} knows nothing about it, so anything centred or
     * right-aligned while tracking is on must add it back — {@see trackedWidth()}.
     */
    public function tracking(float $points): void
    {
        $this->_out(sprintf('%.3F Tc', $points));
    }

    /** The size the current font is set at, in points. */
    public function fontSize(): float
    {
        return (float) $this->FontSizePt;
    }

    /** What a tracked string actually measures: the glyphs plus the gaps. */
    public function trackedWidth(string $text, float $tracking): float
    {
        return $this->GetStringWidth($text) + max(0, strlen($text) - 1) * $tracking;
    }

    /**
     * A rectangle with rounded corners, any subset of them.
     *
     * FPDF has no such primitive, and the design needs one: the mail's card is
     * 12px round, and the masthead and footer bands that sit *inside* it have
     * to round only the two corners they touch — square ones would poke out
     * through the card's own edge, which is the sort of detail that reads as a
     * broken renderer rather than as a choice.
     *
     * @param float          $r       corner radius
     * @param string         $style   `F` fill, `D` stroke, `FD` both
     * @param array{bool,bool,bool,bool} $corners top-left, top-right, bottom-right, bottom-left
     */
    public function roundedRect(
        float $x,
        float $y,
        float $w,
        float $h,
        float $r,
        string $style = 'F',
        array $corners = [true, true, true, true],
    ): void {
        $r = min($r, $w / 2, $h / 2);
        if ($r <= 0) {
            $this->Rect($x, $y, $w, $h, $style);

            return;
        }

        // A circular arc cannot be written as a cubic Bézier exactly; this is
        // the standard control-point distance for a quarter turn, accurate to
        // about one part in 10^4 — far below what a printer resolves.
        $k = 4 / 3 * (M_SQRT2 - 1) * $r;

        [$tl, $tr, $br, $bl] = $corners;

        $this->pathMove($x + ($tl ? $r : 0), $y);
        $this->pathLine($x + $w - ($tr ? $r : 0), $y);
        if ($tr) {
            $this->pathCurve($x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r);
        }
        $this->pathLine($x + $w, $y + $h - ($br ? $r : 0));
        if ($br) {
            $this->pathCurve($x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h);
        }
        $this->pathLine($x + ($bl ? $r : 0), $y + $h);
        if ($bl) {
            $this->pathCurve($x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r);
        }
        $this->pathLine($x, $y + ($tl ? $r : 0));
        if ($tl) {
            $this->pathCurve($x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y);
        }

        $this->_out(match ($style) {
            'F'         => 'f',
            'FD', 'DF'  => 'B',
            default     => 'S',
        });
    }

    /**
     * Break text into lines that fit a width, on spaces.
     *
     * A word longer than the line is left to overhang rather than being cut:
     * every string on these sheets is either the club's own words or its name,
     * and a name chopped mid-syllable is worse than one that runs wide.
     *
     * `$tracking` must be passed whenever the text will be drawn with it on —
     * a 40-character line of letterspaced capitals is some 30pt wider than the
     * font metrics say, which is a whole word's worth of overhang.
     *
     * @return list<string>
     */
    public function wrap(string $text, float $width, float $tracking = 0.0): array
    {
        $lines = [];
        $current = '';

        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && $this->trackedWidth($candidate, $tracking) > $width) {
                $lines[] = $current;
                $current = $word;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * Core fonts are Latin-1, and clubs have umlauts in their names.
     *
     * `//TRANSLIT` rather than `//IGNORE`: a club called Grünwald would rather
     * be printed Grunwald than Grnwald, and a poster is read by people who
     * already know how the name is spelled.
     */
    public static function latin1(string $value): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value);

        return $converted === false ? $value : $converted;
    }

    /** @return array{int,int,int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /* ── raw path operators ───────────────────────────────────────────────
       FPDF measures y from the top of the page and PDF from the bottom, and
       every coordinate is scaled by the document's unit. These three do that
       conversion once so the geometry above can be written in page units. */

    private function pathMove(float $x, float $y): void
    {
        $this->_out(sprintf('%.2F %.2F m', $x * $this->k, ($this->h - $y) * $this->k));
    }

    private function pathLine(float $x, float $y): void
    {
        $this->_out(sprintf('%.2F %.2F l', $x * $this->k, ($this->h - $y) * $this->k));
    }

    private function pathCurve(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $this->k,
            ($this->h - $y1) * $this->k,
            $x2 * $this->k,
            ($this->h - $y2) * $this->k,
            $x3 * $this->k,
            ($this->h - $y3) * $this->k,
        ));
    }
}
