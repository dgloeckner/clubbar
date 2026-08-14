<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Mail;

use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use PHPUnit\Framework\TestCase;

/**
 * The layout is the one piece of this feature nobody can test by reading it —
 * it is a wall of inline styles, and the constraints it satisfies (Outlook's
 * Word engine, blocked images, a client that strips `<style>`) are invisible
 * in the output. So the tests pin the constraints, not the markup.
 */
class MailLayoutTest extends TestCase
{
    private function branding(array $overrides = []): MailBranding
    {
        return new MailBranding(
            // array_key_exists, not ??: half these tests override a field
            // *to null* and need that null to survive.
            orgName: array_key_exists('orgName', $overrides) ? $overrides['orgName'] : 'Ruderverein Beispiel e. V.',
            addressLine: array_key_exists('addressLine', $overrides) ? $overrides['addressLine'] : 'Musterweg 35 · 60599 Frankfurt am Main',
            baseUrl: array_key_exists('baseUrl', $overrides) ? $overrides['baseUrl'] : 'https://www.example.org',
            logoSrc: array_key_exists('logoSrc', $overrides) ? $overrides['logoSrc'] : 'https://www.example.org/assets/img/adler-mail.png',
            headerStyle: $overrides['headerStyle'] ?? MailLayout::HEADER_PAPER,
            footerLinks: $overrides['footerLinks'] ?? ['Kontakt' => 'https://www.example.org/kontakt'],
            wordmark: array_key_exists('wordmark', $overrides) ? $overrides['wordmark'] : 'Ruderverein Beispiel',
            wordmarkSub: array_key_exists('wordmarkSub', $overrides) ? $overrides['wordmarkSub'] : 'Seit 1879',
        );
    }

    private function render(array $overrides = [], array $parts = []): string
    {
        return MailLayout::document($this->branding($overrides), $parts + [
            'title' => 'Vorabankündigung',
            'preview' => 'Einzug von 47,50 € am 21.08.2026',
            'content' => MailLayout::contentStart()
                . MailLayout::title('Vorabankündigung')
                . MailLayout::contentEnd(),
        ]);
    }

    public function test_renders_a_complete_document(): void
    {
        $html = $this->render();

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringEndsWith('</body></html>', $html);
        $this->assertStringContainsString('<title>Vorabankündigung</title>', $html);
    }

    public function test_uses_table_layout_and_no_modern_css(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('role="presentation"', $html);
        // Outlook renders with the Word engine. None of these survive it, so
        // none of them may be load-bearing here.
        $this->assertStringNotContainsString('display:flex', $html);
        $this->assertStringNotContainsString('display:grid', $html);
    }

    public function test_the_card_is_600px_wide(): void
    {
        $this->assertStringContainsString('width:600px;max-width:600px', $this->render());
    }

    public function test_the_preheader_pads_the_inbox_preview(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Einzug von 47,50', $html);
        $this->assertStringContainsString('mso-hide:all', $html);
        // The padding run is what keeps the body text out of the preview line.
        $this->assertStringContainsString('&#8199;&#65279;&nbsp;', $html);
    }

    public function test_omits_the_preheader_when_there_is_none(): void
    {
        $html = $this->render(parts: ['preview' => '']);

        $this->assertStringNotContainsString('mso-hide:all', $html);
    }

    public function test_paper_is_the_settlement_header_and_the_default(): void
    {
        $this->assertSame(MailLayout::HEADER_PAPER, MailLayout::DEFAULT_HEADER_STYLE);

        $html = $this->render(['headerStyle' => MailLayout::HEADER_PAPER]);

        // Light ground, dark mark, and the red rule that keeps it from looking
        // like an unbranded plain-text mail.
        $this->assertStringContainsString('background:' . MailLayout::INK_050, $html);
        $this->assertStringContainsString('border-bottom:3px solid ' . MailLayout::RED, $html);
    }

    public function test_the_other_header_variants_still_work(): void
    {
        $red = $this->render(['headerStyle' => MailLayout::HEADER_RED]);
        $this->assertStringContainsString('bgcolor="' . MailLayout::RED . '"', $red);
        $this->assertStringNotContainsString('border-bottom:3px solid', $red);

        $petrol = $this->render(['headerStyle' => MailLayout::HEADER_PETROL]);
        $this->assertStringContainsString('bgcolor="' . MailLayout::PETROL . '"', $petrol);
    }

    public function test_the_wordmark_is_text_so_a_blocked_image_still_names_the_sender(): void
    {
        $html = $this->render(['logoSrc' => null]);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Ruderverein Beispiel', $html);
    }

    public function test_the_logo_carries_explicit_dimensions(): void
    {
        // Without width/height Outlook renders the image at its full file size,
        // which for a 6× asset is a coat of arms the width of the mail.
        $this->assertStringContainsString('width="47" height="53"', $this->render());
    }

    public function test_club_identity_comes_from_branding_not_constants(): void
    {
        $html = $this->render(['orgName' => 'Kanuverein Musterstadt e. V.', 'wordmark' => 'Kanuverein Musterstadt']);

        $this->assertStringContainsString('Kanuverein Musterstadt e. V.', $html);
        // The template this is ported from hardcodes one club's name. If any of
        // it survived the port, the deployment story is broken for every other
        // club (ADR-0034, and the 2026-08-11 decision on #361).
        $this->assertStringNotContainsString('Frankfurter Rudergesellschaft', $html);
        $this->assertStringNotContainsString('Mainwasenweg', $html);
    }

    public function test_webfonts_are_only_declared_when_a_site_serves_them(): void
    {
        $this->assertStringContainsString('@font-face', $this->render());

        $without = $this->render(['baseUrl' => null]);
        $this->assertStringNotContainsString('@font-face', $without);
        // The serif/grotesque contrast has to survive on the fallbacks alone.
        $this->assertStringContainsString('Georgia', $without);
        $this->assertStringContainsString('Arial', $without);
    }

    public function test_escapes_content_that_comes_from_the_database(): void
    {
        $html = MailLayout::document(
            $this->branding(['orgName' => 'Verein <script>alert(1)</script>']),
            ['content' => MailLayout::title('Müller & Söhne "GmbH"')]
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // Only the markup-significant characters are escaped; umlauts stay as
        // UTF-8 and are encoded by the transport, not here.
        $this->assertStringContainsString('Müller &amp; Söhne &quot;GmbH&quot;', $html);
    }

    public function test_data_table_drops_empty_rows(): void
    {
        $html = MailLayout::dataTable([
            'Mandatsreferenz' => 'MREF-0001',
            'Gläubiger-ID' => 'DE43ZZZ00000548506',
            'IBAN' => '',
        ]);

        $this->assertStringContainsString('MREF-0001', $html);
        $this->assertStringContainsString('DE43ZZZ00000548506', $html);
        $this->assertStringNotContainsString('IBAN', $html);
    }

    public function test_data_table_is_empty_when_everything_is(): void
    {
        $this->assertSame('', MailLayout::dataTable(['Mandatsreferenz' => '  ']));
    }

    public function test_item_table_carries_lines_and_a_total(): void
    {
        $html = MailLayout::itemTable(
            [
                ['label' => 'Bier 0,5 l', 'quantity' => '3', 'amount' => '7,50 €'],
                ['label' => 'Storno 12.07.2026', 'amount' => '-2,50 €'],
            ],
            'Einzugsbetrag',
            '5,00 €'
        );

        $this->assertStringContainsString('Bier 0,5 l', $html);
        $this->assertStringContainsString('-2,50 €', $html);
        $this->assertStringContainsString('Einzugsbetrag', $html);
        $this->assertStringContainsString('5,00 €', $html);
    }

    public function test_the_blocks_a_pre_notification_is_built_from(): void
    {
        // #404 assembles the announcement out of exactly these; a block that
        // renders nothing shows up here rather than in a member's inbox.
        $content = MailLayout::contentStart()
            . MailLayout::eyebrow('Vorabankündigung')
            . MailLayout::title('Einzug am 21. August 2026')
            . MailLayout::lede('Wir buchen <strong>47,50 €</strong> von deinem Konto ab.')
            . MailLayout::paragraph('Die Abrechnung im Einzelnen:')
            . MailLayout::divider()
            . MailLayout::signOff('Viele Grüße', 'Der Kassenwart')
            . MailLayout::contentEnd();

        foreach (['Vorabankündigung', 'Einzug am 21. August 2026', '47,50 €', 'Die Abrechnung im Einzelnen', 'Der Kassenwart'] as $expected) {
            $this->assertStringContainsString($expected, $content);
        }
        // The lede takes HTML, unlike the escaping blocks below — it is where a
        // template emphasises the amount.
        $this->assertStringContainsString('<strong>47,50 €</strong>', $content);
        $this->assertStringContainsString('</td></tr>', $content);
    }

    public function test_the_trailer_sits_outside_the_card(): void
    {
        $withTrailer = $this->render(parts: ['trailer' => 'Diese Nachricht wurde automatisch erzeugt.']);
        $this->assertStringContainsString('Diese Nachricht wurde automatisch erzeugt.', $withTrailer);
        // Below the card: it follows the footer, which is the card's last row.
        $this->assertGreaterThan(
            strpos($withTrailer, 'Ruderverein Beispiel e. V.'),
            strpos($withTrailer, 'automatisch')
        );

        $this->assertStringNotContainsString('automatisch', $this->render());
    }

    public function test_building_blocks_escape_their_input(): void
    {
        $this->assertStringContainsString('&lt;b&gt;', MailLayout::eyebrow('<b>'));
        $this->assertStringContainsString('&lt;b&gt;', MailLayout::subheading('<b>'));
        $this->assertStringContainsString('&lt;b&gt;', MailLayout::button('<b>', 'https://example.org'));
        $this->assertStringContainsString('&lt;b&gt;', MailLayout::signOff('<b>', 'Verein'));
        $this->assertStringContainsString('&lt;b&gt;', MailLayout::link('<b>', 'https://example.org'));
        $this->assertStringContainsString('&lt;b&gt;', MailLayout::noteBox('<b>', 'text'));
    }
}
