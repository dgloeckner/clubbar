<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Mail;

use App\Modules\Notifications\DTOs\PreNotificationDataDto;
use App\Modules\Notifications\DTOs\StatementLineDto;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\PreNotificationMail;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The announcement says everything it is obliged to say — in both languages,
 * and in both parts of the message (#404).
 *
 * Every assertion here stands for a commitment made outside this repository:
 * the EPC rulebook for the creditor identifier and the mandate reference, the
 * Nutzungsordnung for the seven-day notice, the itemised statement and the
 * six-week objection window. A field silently going missing is not a rendering
 * bug, it is the club failing a promise, so each is asserted by name.
 *
 * The text part is checked as hard as the HTML part on purpose. A text
 * alternative that quietly omits the amount is the failure mode nobody notices,
 * because nobody reads it — until a member's client does.
 */
class PreNotificationMailTest extends TestCase
{
    private const CREDITOR_ID = 'DE43ZZZ00000548506';
    private const MANDATE = 'F3332CA866B249E7A202BFBF4836B605';

    private function data(MailLanguage $language, array $overrides = []): PreNotificationDataDto
    {
        $defaults = [
            'language' => $language,
            'recipientAddress' => 'mitglied@example.org',
            'recipientName' => 'Erika Mustermann',
            'salutationName' => 'Erika Mustermann',
            'branding' => new MailBranding(
                orgName: 'Beispiel-Ruderverein e.V.',
                addressLine: 'Musterweg 35, 60599 Frankfurt am Main',
                headerStyle: MailLayout::HEADER_PAPER,
            ),
            'amountCents' => 1234,
            'dueDate' => '2026-08-21',
            'creditorName' => 'Beispiel-Ruderverein e.V.',
            'creditorAddress' => 'Musterweg 35, 60599 Frankfurt am Main',
            'creditorId' => self::CREDITOR_ID,
            'mandateReference' => self::MANDATE,
            'accountLast4' => '3000',
            'lines' => [
                new StatementLineDto('Bier 0,5 l', '2026-07-02 20:14:00', 984),
                new StatementLineDto('Kaffee', '2026-07-09 18:02:00', 250),
            ],
            'periodStart' => '2026-07-01',
            'periodEnd' => '2026-07-31',
            'treasurerEmail' => 'kasse@example.org',
        ];

        return new PreNotificationDataDto(...array_merge($defaults, $overrides));
    }

    private function render(MailLanguage $language, array $overrides = []): MailMessage
    {
        return PreNotificationMail::render($this->data($language, $overrides));
    }

    /**
     * @return list<array{0: MailLanguage, 1: string}> language and the amount as
     *         that language writes it
     */
    public static function languages(): array
    {
        return [
            'German' => [MailLanguage::German, '12,34 €'],
            'English' => [MailLanguage::English, 'EUR 12.34'],
        ];
    }

    #[DataProvider('languages')]
    public function test_every_required_field_is_in_both_parts(MailLanguage $language, string $amount): void
    {
        $message = $this->render($language);

        $dueDate = $language === MailLanguage::German ? '21.08.2026' : '21 August 2026';

        foreach ([self::CREDITOR_ID, self::MANDATE, $amount, $dueDate, '3000'] as $required) {
            $this->assertStringContainsString(
                $required,
                $message->html,
                "HTML part is missing {$required}",
            );
            $this->assertStringContainsString(
                $required,
                $message->text,
                "text part is missing {$required}",
            );
        }
    }

    #[DataProvider('languages')]
    public function test_the_subject_carries_the_amount_and_the_due_date(MailLanguage $language, string $amount): void
    {
        $message = $this->render($language);

        $this->assertStringContainsString($amount, $message->subject);
        $this->assertStringContainsString(
            $language === MailLanguage::German ? '21.08.2026' : '21 August 2026',
            $message->subject,
        );
    }

    #[DataProvider('languages')]
    public function test_the_itemised_statement_lists_every_booking(MailLanguage $language, string $amount): void
    {
        $message = $this->render($language);

        foreach (['Bier 0,5 l', 'Kaffee'] as $label) {
            $this->assertStringContainsString($label, $message->html);
            $this->assertStringContainsString($label, $message->text);
        }

        // The lines add up under a total, and that total is the amount being
        // collected — this is the Abrechnungsübersicht of Nutzungsordnung
        // § 7 Abs. 1, not a decorative list.
        $this->assertStringContainsString(
            $language === MailLanguage::German ? 'Gesamtbetrag' : 'Total',
            $message->text,
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($message->text, $amount),
            'the collected amount should appear both as the announced sum and as the statement total',
        );
    }

    #[DataProvider('languages')]
    public function test_it_names_the_treasurer_and_the_six_week_window(MailLanguage $language): void
    {
        $message = $this->render($language);

        $this->assertStringContainsString('kasse@example.org', $message->html);
        $this->assertStringContainsString('kasse@example.org', $message->text);
        $this->assertStringContainsString(
            $language === MailLanguage::German ? 'sechs Wochen' : 'six weeks',
            $message->text,
        );
    }

    #[DataProvider('languages')]
    public function test_it_reminds_the_member_to_fund_the_account(MailLanguage $language): void
    {
        $message = $this->render($language);

        $this->assertStringContainsString(
            $language === MailLanguage::German ? 'gedeckt' : 'funded',
            $message->text,
        );
    }

    /**
     * ADR-0036: there is no full IBAN in the read model to leak, and the mail
     * must not reconstruct the appearance of one either. Four digits behind a
     * mask, and nothing more.
     */
    public function test_the_account_never_shows_more_than_the_last_four(): void
    {
        $message = $this->render(MailLanguage::German, ['accountLast4' => '3000']);

        $this->assertStringContainsString('**** 3000', $message->html);
        $this->assertStringNotContainsString('DE89370400440532013000', $message->html);
        $this->assertStringNotContainsString('DE89370400440532013000', $message->text);
        // No IBAN-length run of digits anywhere in the message. The creditor
        // identifier is deliberately not caught by this — it is IBAN-shaped,
        // it belongs in the mail, and its digit run is far shorter than an
        // account number's.
        $this->assertDoesNotMatchRegularExpression('/\d{15,}/', $message->text);
        $this->assertDoesNotMatchRegularExpression('/\d{15,}/', $message->html);
    }

    /**
     * An unconfigured club still has to be able to announce a collection. The
     * layout drops empty rows, so what is missing is absent rather than a label
     * with nothing after it.
     */
    public function test_an_unconfigured_creditor_leaves_the_row_out_rather_than_rendering_it_empty(): void
    {
        $message = $this->render(MailLanguage::German, [
            'creditorName' => null,
            'creditorAddress' => null,
            'creditorId' => null,
        ]);

        $this->assertStringNotContainsString('Gläubiger-Identifikationsnummer', $message->html);
        $this->assertStringNotContainsString('Gläubiger-Identifikationsnummer', $message->text);
        // The amount and the due date have no such escape.
        $this->assertStringContainsString('12,34 €', $message->text);
        $this->assertStringContainsString('21.08.2026', $message->text);
    }

    public function test_a_settlement_with_no_line_items_says_so_instead_of_showing_an_empty_table(): void
    {
        $message = $this->render(MailLanguage::German, ['lines' => []]);

        $this->assertStringContainsString('keine Einzelbuchungen', $message->text);
        $this->assertStringContainsString('12,34 €', $message->text);
    }

    public function test_a_member_with_no_name_is_greeted_without_one(): void
    {
        $message = $this->render(MailLanguage::German, ['salutationName' => null, 'recipientName' => null]);

        $this->assertStringContainsString('Guten Tag,', $message->text);
        $this->assertStringNotContainsString('Guten Tag ,', $message->text);
    }

    /** The club identity has to reach a reader who never renders the HTML. */
    public function test_the_text_part_is_signed_by_the_club(): void
    {
        $message = $this->render(MailLanguage::German);

        $this->assertStringContainsString('Beispiel-Ruderverein e.V.', $message->text);
        $this->assertStringContainsString('Musterweg 35', $message->text);
    }

    public function test_the_html_declares_the_members_language(): void
    {
        $this->assertStringContainsString('<html lang="en"', $this->render(MailLanguage::English)->html);
        $this->assertStringContainsString('<html lang="de"', $this->render(MailLanguage::German)->html);
    }

    /**
     * A member's own booking note is member-supplied text and reaches the HTML
     * part; the layout escapes it, and this is the test that would fail if a
     * building block were ever swapped for raw concatenation.
     */
    public function test_a_booking_label_cannot_inject_markup(): void
    {
        $message = $this->render(MailLanguage::German, [
            'lines' => [new StatementLineDto('<script>alert(1)</script>', '2026-07-02 20:14:00', 100)],
        ]);

        $this->assertStringNotContainsString('<script>', $message->html);
        $this->assertStringContainsString('&lt;script&gt;', $message->html);
    }
}
