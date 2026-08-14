<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Mail;

use App\Modules\Notifications\DTOs\CancellationNoticeDataDto;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\CancellationNoticeMail;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * „Einzug entfällt" (#404).
 *
 * Two things are being asserted, and the second is the one that matters: that
 * the notice names the collection it retracts clearly enough to be recognised,
 * and that it does **not** repeat the mandate reference or the creditor
 * identifier. Those authorise a collection; under "this will not be collected"
 * they would read as a fresh announcement.
 */
class CancellationNoticeMailTest extends TestCase
{
    private function render(MailLanguage $language, array $overrides = []): MailMessage
    {
        return CancellationNoticeMail::render(new CancellationNoticeDataDto(...array_merge([
            'language' => $language,
            'recipientAddress' => 'mitglied@example.org',
            'recipientName' => 'Erika Mustermann',
            'salutationName' => 'Erika',
            'branding' => new MailBranding(
                orgName: 'Beispiel-Ruderverein e.V.',
                addressLine: 'Musterweg 35, 60599 Frankfurt am Main',
            ),
            'amountCents' => 1234,
            'dueDate' => '2026-08-21',
            'treasurerEmail' => 'kasse@example.org',
        ], $overrides)));
    }

    /** @return array<string, array{0: MailLanguage, 1: string, 2: string}> */
    public static function languages(): array
    {
        return [
            'German' => [MailLanguage::German, '12,34 €', '21.08.2026'],
            'English' => [MailLanguage::English, 'EUR 12.34', '21 August 2026'],
        ];
    }

    #[DataProvider('languages')]
    public function test_it_names_the_collection_it_retracts(MailLanguage $language, string $amount, string $date): void
    {
        $message = $this->render($language);

        foreach ([$amount, $date] as $required) {
            $this->assertStringContainsString($required, $message->html);
            $this->assertStringContainsString($required, $message->text);
        }
    }

    #[DataProvider('languages')]
    public function test_it_carries_no_mandate_reference_and_no_creditor_id(MailLanguage $language): void
    {
        $message = $this->render($language);

        foreach ([$message->html, $message->text, $message->subject] as $part) {
            $this->assertStringNotContainsString('DE43ZZZ', $part);
            $this->assertStringNotContainsString('Mandatsreferenz', $part);
            $this->assertStringNotContainsString('Mandate reference', $part);
        }
    }

    #[DataProvider('languages')]
    public function test_it_tells_the_member_there_is_nothing_to_do(MailLanguage $language): void
    {
        $message = $this->render($language);

        $this->assertStringContainsString(
            $language === MailLanguage::German ? 'Du musst nichts weiter tun' : 'nothing for you to do',
            $message->text,
        );
    }

    public function test_the_german_notice_is_in_the_du_form(): void
    {
        $message = $this->render(MailLanguage::German);

        $this->assertStringContainsString('Hallo Erika,', $message->text);
        $this->assertStringContainsString('Dein Konto', $message->text);
        $this->assertStringNotContainsString('Ihr Konto', $message->text);
    }

    /**
     * The debt does not vanish with the collection. Saying so here prevents the
     * predictable follow-up question, and it is true: cancelling a settlement
     * releases its items back to unsettled (ADR-0032).
     */
    #[DataProvider('languages')]
    public function test_it_says_an_open_balance_stays_open(MailLanguage $language): void
    {
        $message = $this->render($language);

        $this->assertStringContainsString(
            $language === MailLanguage::German ? 'Offene Beträge bleiben offen' : 'stays open',
            $message->text,
        );
    }

    public function test_the_subject_does_not_read_like_a_second_announcement(): void
    {
        $subject = $this->render(MailLanguage::German)->subject;

        $this->assertStringContainsString('entfällt', $subject);
        $this->assertStringNotContainsString('Vorabankündigung', $subject);
    }
}
