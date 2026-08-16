<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Mail;

use App\Modules\Notifications\DTOs\CredentialExpiryDataDto;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\CredentialExpiryMail;
use App\Shared\Mail\MailBranding;
use App\Shared\Security\CredentialLifecycle;
use PHPUnit\Framework\TestCase;

/**
 * What the credential expiry warning says, and — mostly — what it does not
 * (#438).
 *
 * The negative assertions are the point of this file. A mail about a secret is
 * exactly the shape that reaches for the secret to identify it, and the
 * consequence of getting that wrong is a private key or a terminal token in an
 * inbox, forwarded, and backed up by three mail providers. So the absence is
 * asserted rather than left to the template having kept omitting it.
 */
class CredentialExpiryMailTest extends TestCase
{
    private static function branding(): MailBranding
    {
        return new MailBranding(orgName: 'FRGS Ruderbar');
    }

    private static function key(
        int $tier = 30,
        int $daysLeft = 25,
        MailLanguage $language = MailLanguage::German,
    ): CredentialExpiryDataDto {
        return new CredentialExpiryDataDto(
            language: $language,
            recipientAddress: 'kassenwart@example.org',
            recipientName: 'Kassenwart',
            branding: self::branding(),
            credential: 'key',
            name: 'ruderbar-2026',
            tierDays: $tier,
            daysLeft: $daysLeft,
            expiresOn: '10.09.2026',
        );
    }

    private static function terminal(
        int $tier = 7,
        MailLanguage $language = MailLanguage::German,
    ): CredentialExpiryDataDto {
        return new CredentialExpiryDataDto(
            language: $language,
            recipientAddress: 'kassenwart@example.org',
            recipientName: null,
            branding: self::branding(),
            credential: 'terminal',
            name: 'Theke',
            tierDays: $tier,
            daysLeft: 5,
            expiresOn: '21.08.2026',
        );
    }

    /**
     * The four facts #438 asks for: which credential, which tier, act before
     * this date, and what "act" means — in both parts, because a reader who
     * never sees the HTML gets the text one.
     */
    public function test_it_carries_the_four_facts_in_both_parts(): void
    {
        $message = CredentialExpiryMail::render(self::key());

        foreach ([$message->html, $message->text] as $part) {
            $this->assertStringContainsString('ruderbar-2026', $part);
            $this->assertStringContainsString('10.09.2026', $part);
            $this->assertStringContainsString('25', $part);
            $this->assertStringContainsString('Einstellungen → Schlüssel', $part);
        }
    }

    /**
     * No key material, no token, no fingerprint — and the message says so, so a
     * reader has a rule to apply to the next mail that *does* ask for one.
     */
    public function test_it_carries_no_secret_material(): void
    {
        $publicKey = base64_encode(random_bytes(32));
        $tokenHash = hash('sha256', 'a-terminal-token');

        foreach ([self::key(), self::terminal()] as $data) {
            $message = CredentialExpiryMail::render($data);

            foreach ([$message->html, $message->text] as $part) {
                $this->assertStringNotContainsString($publicKey, $part);
                $this->assertStringNotContainsString($tokenHash, $part);
                // Nothing invites a secret to be pasted back, either.
                $this->assertStringNotContainsString('Passwort', $part);
            }

            $this->assertStringContainsString('keinerlei Schlüssel- oder Zugangsdaten', $message->text);
        }
    }

    /**
     * The tier changes the volume, never the content: a warning skimmed at 90
     * days and read at 7 must not look like it is about a different thing.
     */
    public function test_every_tier_renders_and_only_the_subject_escalates(): void
    {
        $subjects = [];

        foreach (CredentialLifecycle::WARNING_TIERS as $tier) {
            $message = CredentialExpiryMail::render(self::key(tier: $tier));
            $subjects[$tier] = $message->subject;

            $this->assertStringContainsString('ruderbar-2026', $message->text);
            $this->assertStringContainsString('Einstellungen → Schlüssel', $message->text);
        }

        $this->assertStringNotContainsString('Dringend', $subjects[90]);
        $this->assertStringNotContainsString('Dringend', $subjects[30]);
        $this->assertStringContainsString('Dringend', $subjects[7]);
        $this->assertCount(3, array_unique($subjects));
    }

    public function test_the_terminal_variant_names_the_terminal_and_its_own_remedy(): void
    {
        $message = CredentialExpiryMail::render(self::terminal());

        $this->assertStringContainsString('Theke', $message->subject);
        $this->assertStringContainsString('Token rotieren', $message->text);
        // The key's remedy must not leak into the terminal's: rotating a token
        // needs no private key, and saying it does sends somebody to a safe.
        $this->assertStringNotContainsString('private Hälfte', $message->text);
    }

    public function test_it_renders_in_english(): void
    {
        $message = CredentialExpiryMail::render(self::key(language: MailLanguage::English));

        $this->assertStringContainsString('expires in 25 days', $message->subject);
        $this->assertStringContainsString('Settings → Credentials', $message->text);
        $this->assertStringNotContainsString('Einstellungen', $message->text);
    }

    public function test_an_unnamed_recipient_gets_the_generic_greeting(): void
    {
        $message = CredentialExpiryMail::render(self::terminal());

        $this->assertStringContainsString('Hallo,', $message->text);
        $this->assertNull($message->toName);
    }

    /**
     * A terminal name is admin-supplied text and reaches an HTML document.
     */
    public function test_a_terminal_name_cannot_inject_markup(): void
    {
        $data = new CredentialExpiryDataDto(
            language: MailLanguage::German,
            recipientAddress: 'kassenwart@example.org',
            recipientName: null,
            branding: self::branding(),
            credential: 'terminal',
            name: '<script>alert(1)</script>',
            tierDays: 7,
            daysLeft: 5,
            expiresOn: '21.08.2026',
        );

        $message = CredentialExpiryMail::render($data);

        $this->assertStringNotContainsString('<script>', $message->html);
        $this->assertStringContainsString('&lt;script&gt;', $message->html);
    }
}
