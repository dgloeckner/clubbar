<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * The HTML mail layout: header, content, footer, and the building blocks the
 * templates in #404 assemble their content from.
 *
 * Ported from `Mailvorlage.php` in the FRGS website repository
 * (`site/plugins/frgs-ruderkurs/src/Mailvorlage.php`), which is where the
 * design was worked out and proven against real clients; its `docs/
 * mail-gestaltung.md` is the reasoning behind every choice repeated below.
 * Two deliberate deviations from the original:
 *
 * 1. **Identifiers and prose are English**, per this repository's contributor
 *    rules. The upstream file is German throughout, so this is a translation,
 *    not a copy — diffing the two will not be mechanical.
 * 2. **Nothing club-specific is a constant.** Upstream hardcodes the club name,
 *    address and asset paths; here they arrive as `MailBranding`, because Club
 *    Bar is deployed by more than one club (decision of 2026-08-11 on #361,
 *    which explicitly rejects an `ABSENDERNAME`-style constant).
 *
 * Framework-free on purpose, so PHPUnit can render a mail without booting
 * anything.
 *
 * Why table layout and inline styles rather than the stylesheet architecture a
 * web page would use: mail clients know neither custom properties nor layers,
 * and Outlook renders with the Word engine — no flexbox, no border-radius on
 * most elements, no background images. The `<style>` block is a bonus that
 * Gmail and Apple Mail honour (webfonts, the mobile breakpoint) and Outlook
 * discards; nothing in it is needed for the mail to be readable.
 */
final class MailLayout
{
    /* Farben — Spiegel von tokens.css, dort mitpflegen */
    public const RED         = '#C8332E';
    public const RED_DARK    = '#A8241F';
    public const RED_SOFT    = '#FBEAE9';
    public const RED_LIGHT   = '#E9837D'; // red on a dark ground
    public const PETROL      = '#24363E';
    public const INK_900     = '#141414';
    public const INK_700     = '#3A3A3A';
    public const INK_500     = '#6B6B6B';
    public const INK_200     = '#E2E2E0';
    public const INK_100     = '#F1F0EC';
    public const INK_050     = '#FAFAF7';
    public const WHITE       = '#FFFFFF';

    /* Fonts — Fraunces/Inter are fetched via @font-face where the client
     * allows it; where it does not (Gmail web, Outlook) the fallbacks carry
     * the look: a serif for headings, a grotesque for body text. */
    public const SANS    = "'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    public const DISPLAY = "'Fraunces',Georgia,'Times New Roman',Times,serif";

    /** Header variants — see the upstream docs/mail-gestaltung.md */
    public const HEADER_RED    = 'red';
    public const HEADER_PETROL = 'petrol';
    public const HEADER_PAPER  = 'paper';

    public const HEADER_STYLES = [self::HEADER_RED, self::HEADER_PETROL, self::HEADER_PAPER];

    /**
     * The variant for settlement mail. Decided on 2026-08-11: of the three it
     * reads most like a letterhead and least like a newsletter, which is what
     * a pre-notification about somebody's bank account should look like.
     */
    public const DEFAULT_HEADER_STYLE = self::HEADER_PAPER;

    /** The CID the bundled coat of arms is embedded under, when one is used. */
    public const LOGO_CID = 'club-logo';

    public static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The frame: header, content, footer.
     *
     * @param array{title?: string, preview?: string, content?: string, trailer?: string, lang?: string} $parts
     */
    public static function document(MailBranding $branding, array $parts): string
    {
        $title   = $parts['title']   ?? $branding->orgName;
        $preview = $parts['preview'] ?? '';
        $content = $parts['content'] ?? '';
        $lang    = $parts['lang']    ?? 'de';
        $baseUrl = $branding->baseUrl === null ? null : rtrim($branding->baseUrl, '/');

        // Preheader: the line a mail app shows next to the subject. The run of
        // spaces pushes the body text that would otherwise follow out of that
        // preview — an old trick, and still a necessary one.
        $preheader = $preview === '' ? '' : sprintf(
            '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;'
            . 'opacity:0;overflow:hidden;mso-hide:all;color:%s;">%s%s</div>',
            self::INK_100,
            self::esc($preview),
            str_repeat('&#8199;&#65279;&nbsp;', 30)
        );

        return '<!DOCTYPE html>' . "\n"
            . '<html lang="' . self::esc($lang) . '" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width,initial-scale=1">' . "\n"
            . '<meta name="x-apple-disable-message-reformatting">' . "\n"
            . '<meta name="color-scheme" content="light">' . "\n"
            . '<meta name="supported-color-schemes" content="light">' . "\n"
            . '<title>' . self::esc($title) . '</title>' . "\n"
            . '<!--[if mso]><style>* { font-family: Arial, sans-serif !important; }</style><![endif]-->' . "\n"
            . self::styleBlock($baseUrl) . "\n"
            . '</head>' . "\n"
            . '<body style="margin:0;padding:0;background:' . self::INK_100 . ';">' . "\n"
            . $preheader . "\n"
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="background:' . self::INK_100 . ';width:100%;border-collapse:collapse;">' . "\n"
            . '<tr><td align="center" style="padding:28px 12px 36px;">' . "\n"
            . '<table role="presentation" class="cb-card" width="600" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:600px;max-width:600px;background:' . self::WHITE . ';border-collapse:collapse;'
            . 'border:1px solid ' . self::INK_200 . ';border-radius:12px;overflow:hidden;">' . "\n"
            . self::header($branding)
            . $content
            . self::footer($branding)
            . '</table>' . "\n"
            . self::trailer($parts['trailer'] ?? null)
            . '</td></tr></table>' . "\n"
            . '</body></html>';
    }

    /**
     * Webfonts are only declared when a site is configured to serve them.
     * Pointing @font-face at a URL that 404s costs a client a request and
     * gains nothing; the fallback stacks already keep the serif/grotesque
     * contrast that carries the design.
     */
    private static function styleBlock(?string $baseUrl): string
    {
        $fonts = '';
        if ($baseUrl !== null) {
            $fonts = '@font-face{font-family:"Fraunces";src:url("' . $baseUrl . '/assets/fonts/fraunces-500.woff2") format("woff2");font-weight:500;font-style:normal;}' . "\n"
                . '@font-face{font-family:"Inter";src:url("' . $baseUrl . '/assets/fonts/inter-400.woff2") format("woff2");font-weight:400;font-style:normal;}' . "\n"
                . '@font-face{font-family:"Inter";src:url("' . $baseUrl . '/assets/fonts/inter-500.woff2") format("woff2");font-weight:500;font-style:normal;}' . "\n"
                . '@font-face{font-family:"Inter";src:url("' . $baseUrl . '/assets/fonts/inter-600.woff2") format("woff2");font-weight:600;font-style:normal;}' . "\n"
                . '@font-face{font-family:"Inter";src:url("' . $baseUrl . '/assets/fonts/inter-700.woff2") format("woff2");font-weight:700;font-style:normal;}' . "\n";
        }

        return '<style>' . "\n"
            . $fonts
            . 'body{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}' . "\n"
            . 'table{border-collapse:collapse;}' . "\n"
            . 'img{-ms-interpolation-mode:bicubic;}' . "\n"
            . 'a{color:' . self::RED_DARK . ';}' . "\n"
            . '@media only screen and (max-width:620px){' . "\n"
            . '  .cb-card{width:100%!important;border-radius:0!important;border-left:0!important;border-right:0!important;}' . "\n"
            . '  .cb-pad{padding-left:22px!important;padding-right:22px!important;}' . "\n"
            . '  .cb-title{font-size:25px!important;}' . "\n"
            . '  .cb-button a{display:block!important;text-align:center!important;}' . "\n"
            . '}' . "\n"
            . '</style>';
    }

    /**
     * Header band with the club mark.
     *
     * The logo is a PNG, never an SVG: Gmail and Outlook do not display SVG
     * images at all. `width` and `height` are mandatory — without them Outlook
     * renders the image at its full file size.
     *
     * When the client blocks images, which is the normal case on first open,
     * the wordmark carries the sender on its own. That is why it is text.
     */
    private static function header(MailBranding $branding): string
    {
        [$ground, $mark, $subline] = match ($branding->headerStyle) {
            self::HEADER_PETROL => [self::PETROL, self::WHITE, '#9FB0B7'],
            self::HEADER_RED    => [self::RED, self::WHITE, '#F6CCCA'],
            default             => [self::INK_050, self::INK_900, self::INK_500],
        };

        $bottomBorder = $branding->headerStyle === self::HEADER_PAPER
            ? 'border-bottom:3px solid ' . self::RED . ';'
            : '';

        $logo = $branding->logoSrc === null ? '' : sprintf(
            '<td width="47" style="width:47px;padding-right:16px;vertical-align:middle;">'
            . '<img src="%s" width="47" height="53" alt="%s" '
            . 'style="display:block;width:47px;height:53px;border:0;outline:none;text-decoration:none;">'
            . '</td>',
            self::esc($branding->logoSrc),
            self::esc($branding->wordmarkText())
        );

        $subline = $branding->wordmarkSub === null ? '' : sprintf(
            '<div style="font-family:%s;font-size:11px;font-weight:600;letter-spacing:0.18em;'
            . 'text-transform:uppercase;line-height:1.4;color:%s;padding-top:3px;">%s</div>',
            self::SANS,
            $subline,
            self::esc($branding->wordmarkSub)
        );

        return sprintf(
            '<tr><td bgcolor="%1$s" class="cb-pad" style="background:%1$s;padding:24px 30px;%2$s">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '%3$s'
            . '<td style="vertical-align:middle;">'
            . '<div style="font-family:%4$s;font-size:15px;font-weight:700;letter-spacing:0.05em;'
            . 'text-transform:uppercase;line-height:1.35;color:%5$s;">%6$s</div>'
            . '%7$s'
            . '</td></tr></table>'
            . '</td></tr>' . "\n",
            $ground,
            $bottomBorder,
            $logo,
            self::SANS,
            $mark,
            self::esc($branding->wordmarkText()),
            $subline
        );
    }

    private static function footer(MailBranding $branding): string
    {
        $linkStyle = 'color:' . self::RED_LIGHT . ';text-decoration:none;';

        $address = $branding->addressLine === null ? '' : sprintf(
            '<div style="font-family:%s;font-size:14px;line-height:1.6;color:%s;padding-top:4px;">%s</div>',
            self::SANS,
            '#9FB0B7',
            self::esc($branding->addressLine)
        );

        $links = '';
        if ($branding->footerLinks !== []) {
            $rendered = [];
            foreach ($branding->footerLinks as $label => $url) {
                $rendered[] = sprintf(
                    '<a href="%s" style="%s">%s</a>',
                    self::esc($url),
                    $linkStyle,
                    self::esc((string) $label)
                );
            }
            $links = sprintf(
                '<div style="font-family:%s;font-size:14px;line-height:1.6;padding-top:12px;">%s</div>',
                self::SANS,
                implode(sprintf('<span style="color:%s;"> &nbsp;&middot;&nbsp; </span>', '#5A6E77'), $rendered)
            );
        }

        return sprintf(
            '<tr><td bgcolor="%1$s" class="cb-pad" style="background:%1$s;padding:26px 30px;">'
            . '<div style="font-family:%2$s;font-size:14px;font-weight:600;line-height:1.55;color:%3$s;">%4$s</div>'
            . '%5$s%6$s'
            . '</td></tr>' . "\n",
            self::PETROL,
            self::SANS,
            self::WHITE,
            self::esc($branding->orgName),
            $address,
            $links
        );
    }

    private static function trailer(?string $line): string
    {
        if ($line === null || $line === '') {
            return '';
        }

        return sprintf(
            '<div style="font-family:%s;font-size:12px;line-height:1.6;color:%s;'
            . 'max-width:600px;padding:16px 12px 0;text-align:center;">%s</div>' . "\n",
            self::SANS,
            self::INK_500,
            self::esc($line)
        );
    }

    /* ─────────────────────────── Building blocks ─────────────────────────── */

    /** Opens the white content area. */
    public static function contentStart(): string
    {
        return '<tr><td class="cb-pad" style="padding:34px 30px 32px;">' . "\n";
    }

    public static function contentEnd(): string
    {
        return '</td></tr>' . "\n";
    }

    /** The small red kicker above the heading. */
    public static function eyebrow(string $text): string
    {
        return sprintf(
            '<div style="font-family:%s;font-size:12px;font-weight:700;letter-spacing:0.14em;'
            . 'text-transform:uppercase;color:%s;padding-bottom:10px;">%s</div>' . "\n",
            self::SANS,
            self::RED,
            self::esc($text)
        );
    }

    public static function title(string $text): string
    {
        return sprintf(
            '<h1 class="cb-title" style="margin:0 0 16px;font-family:%s;font-size:29px;'
            . 'line-height:1.22;font-weight:500;color:%s;">%s</h1>' . "\n",
            self::DISPLAY,
            self::INK_900,
            self::esc($text)
        );
    }

    /** The standfirst directly under the heading. */
    public static function lede(string $html): string
    {
        return sprintf(
            '<p style="margin:0 0 22px;font-family:%s;font-size:17px;line-height:1.62;color:%s;">%s</p>' . "\n",
            self::SANS,
            self::INK_700,
            $html
        );
    }

    public static function paragraph(string $html): string
    {
        return sprintf(
            '<p style="margin:0 0 16px;font-family:%s;font-size:16px;line-height:1.65;color:%s;">%s</p>' . "\n",
            self::SANS,
            self::INK_700,
            $html
        );
    }

    public static function subheading(string $text): string
    {
        return sprintf(
            '<h2 style="margin:30px 0 14px;font-family:%s;font-size:15px;font-weight:700;'
            . 'letter-spacing:0.09em;text-transform:uppercase;color:%s;">%s</h2>' . "\n",
            self::SANS,
            self::INK_900,
            self::esc($text)
        );
    }

    public static function divider(): string
    {
        return sprintf(
            '<div style="height:1px;line-height:1px;font-size:0;background:%s;margin:28px 0;">&nbsp;</div>' . "\n",
            self::INK_200
        );
    }

    /**
     * Note box with a red bar down the left — where the announcement puts the
     * six-week Beanstandung hint.
     */
    public static function noteBox(string $heading, string $html): string
    {
        return sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:100%%;border-collapse:collapse;margin:22px 0;background:%s;">'
            . '<tr><td width="4" bgcolor="%s" style="width:4px;background:%s;font-size:0;line-height:0;">&nbsp;</td>'
            . '<td style="padding:16px 20px;">'
            . '<div style="font-family:%s;font-size:12px;font-weight:700;letter-spacing:0.12em;'
            . 'text-transform:uppercase;color:%s;padding-bottom:6px;">%s</div>'
            . '<div style="font-family:%s;font-size:15px;line-height:1.6;color:%s;">%s</div>'
            . '</td></tr></table>' . "\n",
            self::RED_SOFT,
            self::RED,
            self::RED,
            self::SANS,
            self::RED_DARK,
            self::esc($heading),
            self::SANS,
            self::INK_700,
            $html
        );
    }

    /**
     * Two-column data summary — the mandate reference, creditor ID, amount,
     * due date and masked IBAN of a pre-notification. Empty values drop out.
     *
     * @param array<string,string> $rows Label => value (plain text)
     */
    public static function dataTable(array $rows): string
    {
        $rows = array_filter($rows, fn ($value) => trim((string) $value) !== '');
        if ($rows === []) {
            return '';
        }

        $html = sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:100%%;border-collapse:collapse;background:%s;border:1px solid %s;border-radius:8px;">'
            . '<tr><td style="padding:6px 20px 8px;">'
            . '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:100%%;border-collapse:collapse;">',
            self::INK_050,
            self::INK_200
        );

        $last = array_key_last($rows);
        foreach ($rows as $label => $value) {
            $border = $label === $last ? 'none' : '1px solid ' . self::INK_200;
            $html .= sprintf(
                '<tr>'
                . '<td width="40%%" style="width:40%%;padding:10px 14px 10px 0;border-bottom:%s;'
                . 'font-family:%s;font-size:14px;line-height:1.45;color:%s;vertical-align:top;">%s</td>'
                . '<td style="padding:10px 0;border-bottom:%s;font-family:%s;font-size:15px;'
                . 'line-height:1.45;font-weight:500;color:%s;vertical-align:top;">%s</td>'
                . '</tr>',
                $border,
                self::SANS,
                self::INK_500,
                self::esc((string) $label),
                $border,
                self::SANS,
                self::INK_900,
                nl2br(self::esc(trim((string) $value)))
            );
        }

        return $html . '</table></td></tr></table>' . "\n";
    }

    /**
     * The itemised statement: description, quantity, amount. A table rather
     * than the two-column block above, because this one has a money column
     * that has to line up and a total underneath it.
     *
     * @param list<array{label: string, quantity?: string, amount: string}> $lines
     */
    public static function itemTable(array $lines, ?string $totalLabel = null, ?string $total = null): string
    {
        if ($lines === [] && $total === null) {
            return '';
        }

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:100%;border-collapse:collapse;margin:6px 0 4px;">';

        foreach ($lines as $line) {
            $html .= sprintf(
                '<tr>'
                . '<td style="padding:8px 10px 8px 0;border-bottom:1px solid %s;font-family:%s;'
                . 'font-size:15px;line-height:1.45;color:%s;vertical-align:top;">%s</td>'
                . '<td align="right" style="padding:8px 10px;border-bottom:1px solid %s;font-family:%s;'
                . 'font-size:15px;line-height:1.45;color:%s;vertical-align:top;white-space:nowrap;">%s</td>'
                . '<td align="right" style="padding:8px 0;border-bottom:1px solid %s;font-family:%s;'
                . 'font-size:15px;line-height:1.45;color:%s;vertical-align:top;white-space:nowrap;">%s</td>'
                . '</tr>',
                self::INK_200,
                self::SANS,
                self::INK_700,
                self::esc($line['label']),
                self::INK_200,
                self::SANS,
                self::INK_500,
                self::esc($line['quantity'] ?? ''),
                self::INK_200,
                self::SANS,
                self::INK_900,
                self::esc($line['amount'])
            );
        }

        if ($total !== null) {
            $html .= sprintf(
                '<tr>'
                . '<td colspan="2" style="padding:12px 10px 0 0;font-family:%s;font-size:15px;'
                . 'font-weight:700;line-height:1.45;color:%s;">%s</td>'
                . '<td align="right" style="padding:12px 0 0;font-family:%s;font-size:16px;'
                . 'font-weight:700;line-height:1.45;color:%s;white-space:nowrap;">%s</td>'
                . '</tr>',
                self::SANS,
                self::INK_900,
                self::esc($totalLabel ?? ''),
                self::SANS,
                self::INK_900,
                self::esc($total)
            );
        }

        return $html . '</table>' . "\n";
    }

    /** Red primary button. */
    public static function button(string $text, string $url): string
    {
        return sprintf(
            '<table role="presentation" class="cb-button" cellpadding="0" cellspacing="0" border="0" '
            . 'style="border-collapse:separate;margin:22px 0 8px;"><tr>'
            . '<td bgcolor="%s" style="background:%s;border-radius:8px;">'
            . '<a href="%s" style="display:inline-block;padding:13px 26px;font-family:%s;font-size:15px;'
            . 'font-weight:600;line-height:1.2;color:%s;text-decoration:none;border-radius:8px;">%s</a>'
            . '</td></tr></table>' . "\n",
            self::RED,
            self::RED,
            self::esc($url),
            self::SANS,
            self::WHITE,
            self::esc($text)
        );
    }

    /** Sign-off with the club name. */
    public static function signOff(string $formula, string $name): string
    {
        return sprintf(
            '<p style="margin:26px 0 0;font-family:%s;font-size:16px;line-height:1.65;color:%s;">%s<br>'
            . '<strong style="color:%s;">%s</strong></p>' . "\n",
            self::SANS,
            self::INK_700,
            self::esc($formula),
            self::INK_900,
            self::esc($name)
        );
    }

    /** Linked text in club red. */
    public static function link(string $text, string $url): string
    {
        return sprintf(
            '<a href="%s" style="color:%s;text-decoration:underline;">%s</a>',
            self::esc($url),
            self::RED_DARK,
            self::esc($text)
        );
    }
}
