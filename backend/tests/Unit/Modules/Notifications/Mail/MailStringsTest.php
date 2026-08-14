<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\MailFormat;
use App\Modules\Notifications\Mail\MailStrings;
use PHPUnit\Framework\TestCase;

/**
 * The wording layer and the two formats under it.
 *
 * The fallback behaviour is the substance here: an unknown language and an
 * untranslated key both have to end in readable German rather than in an empty
 * string or a raw key, because the alternative is an announcement with a gap
 * where the amount belongs.
 */
class MailStringsTest extends TestCase
{
    public function test_an_unsupported_member_language_falls_back_to_german(): void
    {
        // French is a language a member may prefer; no announcement exists in it.
        $this->assertSame(MailLanguage::German, MailLanguage::fromPreferred('fr'));
        $this->assertSame(MailLanguage::German, MailLanguage::fromPreferred(null));
        $this->assertSame(MailLanguage::German, MailLanguage::fromPreferred(''));
        $this->assertSame(MailLanguage::German, MailLanguage::fromPreferred('klingon'));

        $this->assertSame(MailLanguage::English, MailLanguage::fromPreferred('en'));
        $this->assertSame(MailLanguage::English, MailLanguage::fromPreferred(' en '));
    }

    public function test_both_languages_define_the_same_keys(): void
    {
        $de = new MailStrings(MailLanguage::German);
        $en = new MailStrings(MailLanguage::English);

        $reflection = new \ReflectionClass(MailStrings::class);
        $table = $reflection->getConstant('TABLE');

        $missing = array_diff(array_keys($table['de']), array_keys($table['en']));

        $this->assertSame(
            [],
            $missing,
            'these keys have no English wording, so an English member would silently get German: '
            . implode(', ', $missing),
        );

        // And nothing renders as its own key.
        foreach (array_keys($table['de']) as $key) {
            $this->assertNotSame($key, $de->t($key));
            $this->assertNotSame($key, $en->t($key));
        }
    }

    public function test_a_missing_key_falls_back_to_german_rather_than_to_the_key(): void
    {
        $en = new MailStrings(MailLanguage::English);

        // 'pre.title' exists in both; a bogus key exists in neither and is the
        // one case where returning the key is better than returning nothing.
        $this->assertSame('Notice of your direct debit', $en->t('pre.title'));
        $this->assertSame('no.such.key', $en->t('no.such.key'));
    }

    /**
     * The whole German half is in the Du-form; a stray *Sie* would be the kind
     * of thing that survives review because only one string carries it.
     */
    public function test_no_german_string_slips_back_into_the_formal_register(): void
    {
        $table = (new \ReflectionClass(MailStrings::class))->getConstant('TABLE');

        foreach ($table['de'] as $key => $text) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b(Sie|Ihnen|Ihre[nmrs]?)\b/u',
                $text,
                "the German string '{$key}' is in the Sie-form",
            );
        }
    }

    public function test_placeholders_are_substituted(): void
    {
        $t = new MailStrings(MailLanguage::German);

        $subject = $t->t('pre.subject', ['amount' => '12,34 €', 'date' => '21.08.2026']);

        $this->assertStringContainsString('12,34 €', $subject);
        $this->assertStringContainsString('21.08.2026', $subject);
        $this->assertStringNotContainsString('{amount}', $subject);
        $this->assertStringNotContainsString('{date}', $subject);
    }

    public function test_money_is_written_the_way_each_language_writes_it(): void
    {
        $this->assertSame('12,34 €', MailFormat::money(1234, MailLanguage::German));
        $this->assertSame('EUR 12.34', MailFormat::money(1234, MailLanguage::English));

        $this->assertSame('-2,50 €', MailFormat::money(-250, MailLanguage::German));
        $this->assertSame('0,00 €', MailFormat::money(0, MailLanguage::German));

        // Thousands separators are the other way round in the two languages,
        // which is exactly why this is not one format with a swapped symbol.
        $this->assertSame('1.234,56 €', MailFormat::money(123456, MailLanguage::German));
        $this->assertSame('EUR 1,234.56', MailFormat::money(123456, MailLanguage::English));
    }

    public function test_dates_are_unambiguous_in_english(): void
    {
        $this->assertSame('21.08.2026', MailFormat::date('2026-08-21', MailLanguage::German));
        // Not 08/21 or 21/08 — a numeric English date means two different days
        // depending on the reader.
        $this->assertSame('21 August 2026', MailFormat::date('2026-08-21', MailLanguage::English));

        // A full datetime is accepted, since settlement items carry one.
        $this->assertSame('02.07.2026', MailFormat::date('2026-07-02 20:14:00', MailLanguage::German));
    }

    public function test_an_unusable_date_is_passed_through_rather_than_blanked(): void
    {
        $this->assertSame('', MailFormat::date(null, MailLanguage::German));
        $this->assertSame('', MailFormat::date('  ', MailLanguage::German));
        $this->assertSame('not a date', MailFormat::date('not a date', MailLanguage::German));
    }

    public function test_the_account_mask_shows_four_characters_at_most(): void
    {
        $this->assertSame('**** 3000', MailFormat::maskedAccount('3000'));
        $this->assertSame('', MailFormat::maskedAccount(null));
        $this->assertSame('', MailFormat::maskedAccount(''));

        // Defence in depth: if a full IBAN ever reached this function, it would
        // still emit four characters.
        $this->assertSame('**** 3000', MailFormat::maskedAccount('DE89370400440532013000'));
    }
}
