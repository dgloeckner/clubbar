<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Mail;

use App\Modules\CreditLimits\Domain\CreditLimitPolicy;
use App\Modules\CreditLimits\Domain\CreditLimitStatus;
use App\Modules\Notifications\DTOs\DeckelStatementDataDto;
use App\Modules\Notifications\DTOs\StatementLineDto;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\DeckelStatementMail;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a Deckelauszug reads like, in both languages and both parts
 * (ADR-0039 decision 4, #463 step 12.2).
 *
 * Modelled on `PreNotificationMailTest`, and its mirror image in one respect:
 * half the assertions here are **negative**. A statement that carries a mandate
 * reference or a due date has not rendered a wrong field, it has become a
 * different kind of message — a collection notice — and the member reading it
 * would reasonably start looking at their bank account. That failure is
 * invisible in review, because every one of those values is available a
 * constructor argument away in the neighbouring builder.
 *
 * The text part is asserted as hard as the HTML part, for the reason the
 * pre-notification's test gives: a text alternative that quietly omits the
 * total is the failure nobody notices until a member's client renders it.
 */
class DeckelStatementMailTest extends TestCase
{
    /** Values that belong to the Vorabankündigung and to nothing else. */
    private const FORBIDDEN = [
        'DE43ZZZ00000548506',
        'F3332CA866B249E7A202BFBF4836B605',
        'DE89370400440532013000',
    ];

    private function data(MailLanguage $language, array $overrides = []): DeckelStatementDataDto
    {
        $defaults = [
            'language' => $language,
            'recipientAddress' => 'mitglied@example.org',
            'recipientName' => 'Erika Mustermann',
            'salutationName' => 'Erika',
            'branding' => new MailBranding(
                orgName: 'Beispiel-Ruderverein e.V.',
                addressLine: 'Musterweg 35, 60599 Frankfurt am Main',
                headerStyle: MailLayout::HEADER_PAPER,
            ),
            'boundaryDate' => '2026-08-01',
            'totalCents' => 1234,
            'lines' => [
                new StatementLineDto('Bier 0,5 l', '2026-07-02 20:14:00', 984),
                new StatementLineDto('Kaffee', '2026-07-09 18:02:00', 250),
            ],
            'omittedLines' => 0,
            'creditLimitCents' => CreditLimitPolicy::DEFAULT_LIMIT_CENTS,
            'creditStatus' => CreditLimitStatus::OK,
            'treasurerEmail' => 'kasse@example.org',
        ];

        return new DeckelStatementDataDto(...array_merge($defaults, $overrides));
    }

    private function render(MailLanguage $language, array $overrides = []): MailMessage
    {
        return DeckelStatementMail::render($this->data($language, $overrides));
    }

    /** @return array<string, array{0: MailLanguage, 1: string, 2: string}> language, amount, boundary date */
    public static function languages(): array
    {
        return [
            'German' => [MailLanguage::German, '12,34 €', '01.08.2026'],
            'English' => [MailLanguage::English, 'EUR 12.34', '1 August 2026'],
        ];
    }

    #[DataProvider('languages')]
    public function test_it_states_the_tab_and_its_lines_in_both_parts(
        MailLanguage $language,
        string $amount,
        string $asOf,
    ): void {
        $message = $this->render($language);

        foreach ([$message->html, $message->text] as $part) {
            $this->assertStringContainsString($amount, $part, 'the tab is the whole point of the message');
            $this->assertStringContainsString($asOf, $part);
            $this->assertStringContainsString('Bier 0,5 l', $part);
            $this->assertStringContainsString('Kaffee', $part);
            $this->assertStringContainsString('Erika', $part, 'members are addressed by first name');
            $this->assertStringContainsString('kasse@example.org', $part);
        }
    }

    /**
     * The subject names the period, not the send day — the one thing that stops
     * „your tab is 12,34 €" from being read as a live figure.
     */
    #[DataProvider('languages')]
    public function test_the_subject_and_the_heading_name_the_boundary(
        MailLanguage $language,
        string $amount,
        string $asOf,
    ): void {
        $message = $this->render($language);

        $this->assertStringContainsString($asOf, $message->subject);
        $this->assertStringNotContainsString($amount, $message->subject, 'a tab is not a subject line');

        // And prominently in the body, as its own labelled row rather than as
        // a date buried in a sentence.
        $label = $language === MailLanguage::German ? 'Stand' : 'As at';
        $this->assertStringContainsString($label, $message->html);
        $this->assertStringContainsString($label, $message->text);
    }

    /**
     * The rule that keeps a Deckelauszug from turning into a collection notice.
     *
     * These values are not merely absent from the fixture — there is no field
     * on the DTO that could carry them. The assertion guards the day somebody
     * adds one.
     */
    #[DataProvider('languages')]
    public function test_it_carries_nothing_that_belongs_to_the_vorabankuendigung(
        MailLanguage $language,
    ): void {
        $message = $this->render($language);

        foreach ([$message->html, $message->text, $message->subject] as $part) {
            foreach (self::FORBIDDEN as $value) {
                $this->assertStringNotContainsString($value, $part);
            }

            foreach (['Mandatsreferenz', 'Mandate reference', 'Gläubiger', 'Creditor', 'Mahnung', 'IBAN'] as $word) {
                $this->assertStringNotContainsString(
                    $word,
                    $part,
                    "a Deckelauszug that says \"{$word}\" reads as a demand for money"
                );
            }
        }
    }

    /** A member at zero gets a sentence, not an empty table (ADR-0039 decision 4). */
    #[DataProvider('languages')]
    public function test_an_empty_tab_is_a_sentence_rather_than_an_empty_table(MailLanguage $language): void
    {
        $message = $this->render($language, ['totalCents' => 0, 'lines' => []]);

        $expected = $language === MailLanguage::German
            ? 'keine offenen Buchungen'
            : 'no outstanding entries';

        $this->assertStringContainsString($expected, $message->text);
        $this->assertStringContainsString($expected, $message->html);
        $this->assertStringNotContainsString('<td align="right"', $message->html, 'no item table without items');
    }

    /**
     * A credit is stated as a credit — and without implying a Payout is on its
     * way, because that is a separate act somebody has to ask for.
     */
    #[DataProvider('languages')]
    public function test_a_credit_is_stated_as_one_and_promises_no_payout(MailLanguage $language): void
    {
        $message = $this->render($language, [
            'totalCents' => -750,
            'lines' => [new StatementLineDto('Storno Bier 0,5 l', '2026-07-20 19:00:00', -750)],
            'creditStatus' => CreditLimitStatus::OK,
        ]);

        $creditLabel = $language === MailLanguage::German ? 'Guthaben' : 'In credit';
        $magnitude = $language === MailLanguage::German ? '7,50 €' : 'EUR 7.50';

        foreach ([$message->html, $message->text] as $part) {
            $this->assertStringContainsString($creditLabel, $part);
            $this->assertStringContainsString($magnitude, $part, 'a credit is shown without a minus sign');

            $promise = $language === MailLanguage::German ? 'auf Anfrage' : 'on request';
            $this->assertStringContainsString($promise, $part, 'a payout is asked for, never announced');
        }

        // The item table keeps the sign: it is a column of signed amounts and
        // its foot has to agree with them.
        $signed = $language === MailLanguage::German ? '-7,50 €' : 'EUR -7.50';
        $this->assertStringContainsString($signed, $message->text);
    }

    /**
     * Past the limit, the mail says what the terminal will do. This is the
     * concrete job the whole feature exists for: today a member's first signal
     * is being turned away at the bar, in front of the queue.
     */
    #[DataProvider('languages')]
    public function test_a_member_past_the_limit_is_told_the_terminal_will_refuse(MailLanguage $language): void
    {
        $message = $this->render($language, [
            'totalCents' => 12_500,
            'creditStatus' => CreditLimitStatus::EXCEEDED,
        ]);

        $limit = $language === MailLanguage::German ? '100,00 €' : 'EUR 100.00';
        $sentence = $language === MailLanguage::German ? 'keine weitere Bestellung' : 'not accept another order';

        foreach ([$message->html, $message->text] as $part) {
            $this->assertStringContainsString($limit, $part);
            $this->assertStringContainsString($sentence, $part);
        }
    }

    /** The warning band is where the message can still change the outcome. */
    #[DataProvider('languages')]
    public function test_the_warning_band_says_what_is_coming(MailLanguage $language): void
    {
        $message = $this->render($language, [
            'totalCents' => 8_500,
            'creditStatus' => CreditLimitStatus::APPROACHING,
        ]);

        $expected = $language === MailLanguage::German ? 'näherst Dich' : 'approaching it';

        $this->assertStringContainsString($expected, $message->text);
        $this->assertStringContainsString($expected, $message->html);
    }

    /**
     * The cap, and the sentence that makes it safe.
     *
     * The total is stated over the full set, so a member who counts the printed
     * lines and does not reach it must be told why — otherwise the one thing
     * they can check does not check out.
     */
    #[DataProvider('languages')]
    public function test_a_capped_statement_says_the_total_covers_more_than_it_prints(MailLanguage $language): void
    {
        $lines = [];
        for ($i = 0; $i < DeckelStatementDataDto::MAX_LINES; $i++) {
            $lines[] = new StatementLineDto('Bier', '2026-07-02 20:14:00', 250);
        }

        $message = $this->render($language, [
            'lines' => $lines,
            'omittedLines' => 50,
            'totalCents' => 37_500,
        ]);

        $expected = $language === MailLanguage::German ? '…und 50 weitere' : '…and 50 further';

        foreach ([$message->html, $message->text] as $part) {
            $this->assertStringContainsString($expected, $part);
        }

        $total = $language === MailLanguage::German ? '375,00 €' : 'EUR 375.00';
        $this->assertStringContainsString($total, $message->text, 'the total is over all 150, not the printed 100');
    }

    /** Nothing is said about a cap that removed nothing. */
    public function test_an_uncapped_statement_says_nothing_about_a_cap(): void
    {
        $message = $this->render(MailLanguage::German);

        $this->assertStringNotContainsString('weitere Buchungen', $message->html);
        $this->assertStringNotContainsString('weitere Buchungen', $message->text);
    }

    /** An anonymised member has no first name left; the greeting degrades rather than breaking. */
    public function test_a_member_without_a_name_is_greeted_generically(): void
    {
        $message = $this->render(MailLanguage::German, ['salutationName' => null, 'recipientName' => null]);

        $this->assertStringContainsString('Hallo,', $message->text);
        $this->assertNull($message->toName);
    }

    /** A club that has not filled in a reply-to still sends a usable statement. */
    public function test_a_missing_treasurer_address_leaves_a_readable_sentence(): void
    {
        $message = $this->render(MailLanguage::German, ['treasurerEmail' => null]);

        $this->assertStringContainsString('Kassenwart', $message->text);
        $this->assertStringNotContainsString('unter ,', $message->text);
        $this->assertStringNotContainsString('{email}', $message->text);
    }

    /** No placeholder may survive into a message, in either language or either part. */
    #[DataProvider('languages')]
    public function test_no_placeholder_survives_rendering(MailLanguage $language): void
    {
        $message = $this->render($language, ['omittedLines' => 3, 'creditStatus' => CreditLimitStatus::APPROACHING]);

        foreach ([$message->subject, $message->html, $message->text] as $part) {
            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', $part);
        }
    }
}
