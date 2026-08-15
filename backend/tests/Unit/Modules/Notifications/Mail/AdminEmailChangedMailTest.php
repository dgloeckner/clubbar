<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\AdminEmailChangedMail;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Your sign-in address was changed", sent to the address it was changed *from*.
 *
 * The recipient is the whole point of this message, and the omission is the
 * other half: it must not name the address the account moved *to*. Whoever
 * reads this can no longer sign in, so if the change was a takeover, printing
 * the attacker's address into the victim's mailbox tells the wrong person
 * something and the right person nothing they can act on.
 */
class AdminEmailChangedMailTest extends TestCase
{
    private const FORMER = 'vorstand@example.org';
    private const NEW_ADDRESS = 'attacker@evil.example';

    private function render(MailLanguage $language, ?string $name = 'Erika Mustermann'): MailMessage
    {
        return AdminEmailChangedMail::render(
            recipientAddress: self::FORMER,
            recipientName: $name,
            changedAt: '2026-08-21 09:30:00',
            language: $language,
            branding: new MailBranding(
                orgName: 'Beispiel-Ruderverein e.V.',
                addressLine: 'Musterweg 35, 60599 Frankfurt am Main',
            ),
        );
    }

    /** @return array<string, array{0: MailLanguage, 1: string}> */
    public static function languages(): array
    {
        return [
            'German' => [MailLanguage::German, '21.08.2026'],
            'English' => [MailLanguage::English, '21 August 2026'],
        ];
    }

    #[DataProvider('languages')]
    public function test_it_is_addressed_to_the_former_address(MailLanguage $language): void
    {
        $message = $this->render($language);

        $this->assertSame(self::FORMER, $message->to);
        $this->assertStringContainsString(self::FORMER, $message->html);
        $this->assertStringContainsString(self::FORMER, $message->text);
    }

    /**
     * The omission that matters. Asserted against the rendered article rather
     * than the call, because the address is never passed in — this is a guard
     * against a future edit deciding it would be helpful to include it.
     */
    #[DataProvider('languages')]
    public function test_it_never_names_the_new_address(MailLanguage $language): void
    {
        $message = $this->render($language);

        foreach ([$message->subject, $message->html, $message->text] as $part) {
            $this->assertStringNotContainsString(self::NEW_ADDRESS, $part);
            $this->assertStringNotContainsString('evil.example', $part);
        }
    }

    #[DataProvider('languages')]
    public function test_it_says_when_the_change_happened(MailLanguage $language, string $expectedDate): void
    {
        $message = $this->render($language);

        $this->assertStringContainsString($expectedDate, $message->html);
        $this->assertStringContainsString($expectedDate, $message->text);
    }

    /**
     * Recovery is another admin (ADR-0026). There is deliberately no undo link
     * and no token — a self-service reversal reachable from an email would be a
     * second way in, defeating the step-up that gates the change — so the one
     * actionable instruction has to be the one that works.
     */
    public function test_it_points_at_the_only_recovery_there_is(): void
    {
        $german = $this->render(MailLanguage::German);
        $this->assertStringContainsString('Administrator', $german->text);

        $english = $this->render(MailLanguage::English);
        $this->assertStringContainsString('another administrator', $english->text);

        foreach ([$german, $english] as $message) {
            $this->assertStringNotContainsString('http://', $message->text);
            $this->assertStringNotContainsString('undo', strtolower($message->text));
        }
    }

    #[DataProvider('languages')]
    public function test_it_renders_both_parts_with_a_subject(MailLanguage $language): void
    {
        $message = $this->render($language);

        $this->assertNotSame('', trim($message->subject));
        $this->assertNotSame('', trim($message->text));
        $this->assertStringContainsString('<html', strtolower($message->html));
        $this->assertStringContainsString('Beispiel-Ruderverein e.V.', $message->text);
    }

    /** An account with no display name still gets a greeting rather than "Hallo ,". */
    public function test_a_nameless_account_gets_the_generic_greeting(): void
    {
        $message = $this->render(MailLanguage::German, null);

        $this->assertStringContainsString('Hallo', $message->text);
        $this->assertStringNotContainsString('Hallo ,', $message->text);
    }
}
