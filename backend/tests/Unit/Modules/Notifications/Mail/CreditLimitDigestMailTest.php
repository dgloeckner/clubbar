<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Mail;

use App\Modules\CreditLimits\Domain\CreditLimitStatus;
use App\Modules\Notifications\DTOs\CreditLimitDigestDataDto;
use App\Modules\Notifications\DTOs\CreditLimitDigestLineDto;
use App\Modules\Notifications\DTOs\CreditLimitDigestReportDto;
use App\Modules\Notifications\Enums\DigestCadence;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\CreditLimitDigestMail;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the near-limit digest reads like, in both languages and both parts.
 *
 * The three figures the treasurer asked for — **who, what they owe, and the
 * limit that applies to them** — are asserted together and in both the HTML and
 * the text alternative. The text part matters as much as the HTML here for the
 * reason `PreNotificationMailTest` gives about its total: an alternative that
 * quietly omits the limits is the failure nobody notices until somebody's
 * client renders it, and a column of bare amounts with no ceilings beside them
 * is not merely less useful — with per-member ceilings (ADR-0047) it is
 * unreadable.
 */
class CreditLimitDigestMailTest extends TestCase
{
    public static function languages(): array
    {
        return [[MailLanguage::German], [MailLanguage::English]];
    }

    #[DataProvider('languages')]
    public function test_every_member_is_named_with_their_tab_and_their_ceiling(MailLanguage $language): void
    {
        $message = CreditLimitDigestMail::render($this->data($language));

        foreach ([$message->html, $message->text] as $part) {
            $this->assertStringContainsString('Anna Schmidt', $part);
            $this->assertStringContainsString('Bert Klein', $part);

            // Anna: 90,00 € of 100,00 € — the club default.
            $this->assertStringContainsString($language === MailLanguage::German ? '90,00 €' : 'EUR 90.00', $part);
            $this->assertStringContainsString($language === MailLanguage::German ? '100,00 €' : 'EUR 100.00', $part);

            // Bert carries an override of 500,00 €, and it must be *his*
            // ceiling that appears beside his tab, not the club's. This is the
            // assertion that would fail if the mail ever fell back to the
            // club default for every row.
            $this->assertStringContainsString($language === MailLanguage::German ? '500,00 €' : 'EUR 500.00', $part);
        }
    }

    #[DataProvider('languages')]
    public function test_the_share_of_the_ceiling_appears_beside_each_member(MailLanguage $language): void
    {
        $message = CreditLimitDigestMail::render($this->data($language));

        $this->assertStringContainsString('90', $message->html);
        $this->assertStringContainsString('104', $message->text, 'a tab past its ceiling reports over 100 %');
    }

    #[DataProvider('languages')]
    public function test_the_subject_names_how_many_members_are_listed(MailLanguage $language): void
    {
        $message = CreditLimitDigestMail::render($this->data($language));

        // A subject that reads the same every week is one that stops being
        // read; the count is what makes it worth opening.
        $this->assertStringContainsString('2', $message->subject);
    }

    #[DataProvider('languages')]
    public function test_the_total_owed_is_stated(MailLanguage $language): void
    {
        $message = CreditLimitDigestMail::render($this->data($language));

        // 90,00 + 52,00 = 142,00
        $expected = $language === MailLanguage::German ? '142,00 €' : 'EUR 142.00';
        $this->assertStringContainsString($expected, $message->html);
        $this->assertStringContainsString($expected, $message->text);
    }

    /**
     * A member past their ceiling is not "approaching" anything — they are
     * being refused at the till right now, which is the one line in this mail
     * with a deadline attached. It is called out in words rather than by a
     * colour, because a tinted table cell is invisible in a plain-text client.
     */
    #[DataProvider('languages')]
    public function test_a_member_over_their_limit_is_called_out_in_words(MailLanguage $language): void
    {
        $message = CreditLimitDigestMail::render($this->data($language));

        $marker = $language === MailLanguage::German ? 'Limit überschritten' : 'over the limit';
        $this->assertStringContainsString($marker, $message->html);
        $this->assertStringContainsString($marker, $message->text);
    }

    /**
     * The club's own ceiling and the warning band, stated once.
     *
     * Without them a reader cannot tell an override from a bug: Bert's row
     * showing €52 of €500 next to a club default of €100 looks like a mistake
     * until the club default is named.
     */
    #[DataProvider('languages')]
    public function test_the_clubs_policy_is_stated_so_an_override_is_legible(MailLanguage $language): void
    {
        $message = CreditLimitDigestMail::render($this->data($language));

        $this->assertStringContainsString('80', $message->html, 'the warning threshold');
        $this->assertStringContainsString('80', $message->text);
    }

    /** No silent truncation: a capped list says how many names it is not showing. */
    #[DataProvider('languages')]
    public function test_a_capped_list_says_how_many_it_left_out(MailLanguage $language): void
    {
        $message = CreditLimitDigestMail::render($this->data($language, omitted: 12));

        $this->assertStringContainsString('12', $message->html);
        $this->assertStringContainsString('12', $message->text);
    }

    #[DataProvider('languages')]
    public function test_a_full_list_says_nothing_about_omissions(MailLanguage $language): void
    {
        $message = CreditLimitDigestMail::render($this->data($language));

        $needle = $language === MailLanguage::German ? 'Weitere' : 'Another';
        $this->assertStringNotContainsString($needle, $message->html);
    }

    /**
     * The empty case renders rather than throws.
     *
     * The scan will not queue a digest for an empty list, so this is reached
     * only when the last member cleared their tab between the enqueue and the
     * drain. The recipient is expecting a digest, and one sentence of good news
     * beats a red row in the Notifications page.
     */
    #[DataProvider('languages')]
    public function test_an_emptied_list_still_renders_a_truthful_message(MailLanguage $language): void
    {
        $message = CreditLimitDigestMail::render($this->data($language, lines: []));

        $this->assertNotSame('', trim($message->html));
        $this->assertNotSame('', trim($message->text));
        $this->assertStringNotContainsString('Anna Schmidt', $message->html);
        $this->assertStringNotContainsString('Anna Schmidt', $message->text);
    }

    /** Addressed to whoever runs the club, at the address the row snapshotted. */
    public function test_the_message_goes_to_the_recipient_on_the_row(): void
    {
        $message = CreditLimitDigestMail::render($this->data(MailLanguage::German));

        $this->assertSame('kassenwart@example.org', $message->to);
        $this->assertSame('Karla Kassenwart', $message->toName);
    }

    /**
     * The digest carries member names, and no bank details of any kind.
     *
     * It reaches the treasury offices, who can already see these names on the
     * dashboard — but a mandate reference or an IBAN in an operational digest
     * would be a leak of a different order, and every one of those values is a
     * constructor argument away in a neighbouring builder.
     */
    public function test_the_digest_carries_no_banking_data(): void
    {
        $message = CreditLimitDigestMail::render($this->data(MailLanguage::German));

        foreach (['DE89370400440532013000', 'DE43ZZZ00000548506', '****'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $message->html);
            $this->assertStringNotContainsString($forbidden, $message->text);
        }
    }

    /**
     * @param list<CreditLimitDigestLineDto>|null $lines
     */
    private function data(MailLanguage $language, ?array $lines = null, int $omitted = 0): CreditLimitDigestDataDto
    {
        $lines ??= [
            new CreditLimitDigestLineDto(
                memberId: 'm-1',
                name: 'Anna Schmidt',
                balanceCents: 9_000,
                limitCents: 10_000,
                percentOfLimit: 90,
                status: CreditLimitStatus::APPROACHING,
            ),
            // An override, and over it: 52,00 € against a 500,00 € ceiling
            // would be comfortable, so this row is deliberately marked
            // exceeded to prove the status travels from the DTO rather than
            // being recomputed from the club default.
            new CreditLimitDigestLineDto(
                memberId: 'm-2',
                name: 'Bert Klein',
                balanceCents: 5_200,
                limitCents: 50_000,
                percentOfLimit: 104,
                status: CreditLimitStatus::EXCEEDED,
            ),
        ];

        $total = 0;
        $exceeded = 0;
        foreach ($lines as $line) {
            $total += $line->balanceCents;
            if ($line->status === CreditLimitStatus::EXCEEDED) {
                $exceeded++;
            }
        }

        return new CreditLimitDigestDataDto(
            language: $language,
            recipientAddress: 'kassenwart@example.org',
            recipientName: 'Karla Kassenwart',
            branding: new MailBranding(
                orgName: 'Beispiel-Ruderverein e.V.',
                addressLine: 'Musterweg 35, 60599 Frankfurt am Main',
                headerStyle: MailLayout::HEADER_PAPER,
            ),
            cadence: DigestCadence::WEEKLY,
            report: new CreditLimitDigestReportDto(
                lines: $lines,
                clubDefaultLimitCents: 10_000,
                warnThresholdPercent: 80,
                totalOwedCents: $total,
                exceededCount: $exceeded,
                omitted: $omitted,
            ),
        );
    }
}
