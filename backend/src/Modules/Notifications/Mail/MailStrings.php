<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailLanguage;

/**
 * Every word a member reads, in both languages (#404, ADR-0002).
 *
 * A flat array rather than the JSON locale files the admin UI uses: these
 * strings are rendered by the drain, which runs from a cron with no HTTP
 * request and no frontend build around it, and a missing file at that point
 * would produce a silent blank announcement.
 *
 * **The fallback is per key, not per language.** `t()` reaches for German when
 * a key is missing from the requested language, so an untranslated string
 * arrives in German rather than as an empty gap where the amount should be. A
 * mail in the wrong language is a poor outcome; a mail with no amount in it is
 * an unusable one.
 *
 * ## Register
 *
 * German is formal (*Sie*), which differs from the terminal UI's informal *du*
 * (see #42). That is deliberate and this is the one place to change it: a
 * pre-notification quotes a creditor identifier and a mandate reference and
 * invites a formal objection within six weeks, and it is the register every
 * other SEPA pre-notification a member receives is written in. The bar
 * touchscreen is a different conversation.
 */
final class MailStrings
{
    public function __construct(
        public readonly MailLanguage $language,
    ) {}

    /**
     * @param array<string,string> $params Replaced as `{name}` placeholders.
     */
    public function t(string $key, array $params = []): string
    {
        $text = self::TABLE[$this->language->value][$key]
            ?? self::TABLE[MailLanguage::German->value][$key]
            ?? $key;

        if ($params === []) {
            return $text;
        }

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = $value;
        }

        return strtr($text, $replacements);
    }

    /** @var array<string, array<string,string>> */
    private const TABLE = [
        'de' => [
            // ── Pre-notification ────────────────────────────────────────────
            'pre.subject'          => 'Vorabankündigung: SEPA-Lastschrift über {amount} am {date}',
            'pre.preheader'        => 'Fällig am {date} · Mandatsreferenz {mandate}',
            'pre.eyebrow'          => 'SEPA-Vorabankündigung',
            'pre.title'            => 'Ankündigung des Lastschrifteinzugs',
            'pre.lede'             => 'wir kündigen Ihnen den Einzug von <strong>{amount}</strong> von Ihrem Konto an. '
                                    . 'Der Betrag wird frühestens am <strong>{date}</strong> abgebucht.',
            'pre.lede_text'        => 'wir kündigen Ihnen den Einzug von {amount} von Ihrem Konto an. '
                                    . 'Der Betrag wird frühestens am {date} abgebucht.',
            'pre.data_heading'     => 'Angaben zum Einzug',
            'pre.label_creditor'   => 'Gläubiger',
            'pre.label_creditor_id' => 'Gläubiger-Identifikationsnummer',
            'pre.label_mandate'    => 'Mandatsreferenz',
            'pre.label_amount'     => 'Betrag',
            'pre.label_due_date'   => 'Fälligkeit',
            'pre.label_account'    => 'Ihr Konto',
            'pre.statement_heading' => 'Abrechnungsübersicht',
            'pre.statement_intro'  => 'Diese Buchungen ergeben den Betrag:',
            'pre.statement_period' => 'Zeitraum {from} bis {to}',
            'pre.statement_empty'  => 'Zu diesem Einzug liegen keine Einzelbuchungen vor.',
            'pre.total_label'      => 'Gesamtbetrag',
            'pre.funding_note'     => 'Bitte sorgen Sie dafür, dass Ihr Konto am Fälligkeitstag gedeckt ist. '
                                    . 'Die Kosten einer Rücklastschrift trägt das Mitglied.',
            'pre.objection_heading' => 'Beanstandungen',
            'pre.objection_text'   => 'Stimmt etwas nicht? Bitte melden Sie sich innerhalb von sechs Wochen '
                                    . 'beim Kassenwart{contact}. Eine Antwort auf diese E-Mail genügt.',
            'pre.objection_contact' => ' unter {email}',

            // ── Cancellation notice ─────────────────────────────────────────
            'cancel.subject'   => 'Der angekündigte Lastschrifteinzug entfällt',
            'cancel.preheader' => 'Die für den {date} angekündigte Lastschrift wird nicht eingezogen.',
            'cancel.eyebrow'   => 'Einzug entfällt',
            'cancel.title'     => 'Der angekündigte Einzug entfällt',
            'cancel.lede'      => 'die für den <strong>{date}</strong> angekündigte Lastschrift über '
                                . '<strong>{amount}</strong> wird <strong>nicht</strong> eingezogen.',
            'cancel.lede_text' => 'die für den {date} angekündigte Lastschrift über {amount} wird NICHT eingezogen.',
            'cancel.no_action' => 'Sie müssen nichts weiter tun. Ihr Konto wird für diesen Einzug nicht belastet.',
            'cancel.next'      => 'Offene Beträge bleiben offen und werden gegebenenfalls mit einem '
                                . 'späteren Einzug angekündigt.',
            'cancel.label_amount'   => 'Angekündigter Betrag',
            'cancel.label_due_date' => 'Angekündigte Fälligkeit',

            // ── Shared ──────────────────────────────────────────────────────
            'greeting'         => 'Guten Tag {name},',
            'greeting_generic' => 'Guten Tag,',
            'signoff'          => 'Mit freundlichen Grüßen',
            'questions'        => 'Fragen zu dieser Nachricht? Antworten Sie einfach darauf.',
            'text_separator'   => '----------------------------------------------------------',
            'automated_note'   => 'Diese Nachricht wurde automatisch erstellt.',
        ],

        'en' => [
            'pre.subject'          => 'Advance notice: SEPA direct debit of {amount} on {date}',
            'pre.preheader'        => 'Due on {date} · Mandate reference {mandate}',
            'pre.eyebrow'          => 'SEPA advance notice',
            'pre.title'            => 'Notice of your direct debit',
            'pre.lede'             => 'we are giving you advance notice that <strong>{amount}</strong> will be '
                                    . 'collected from your account, on or after <strong>{date}</strong>.',
            'pre.lede_text'        => 'we are giving you advance notice that {amount} will be collected from your '
                                    . 'account, on or after {date}.',
            'pre.data_heading'     => 'Collection details',
            'pre.label_creditor'   => 'Creditor',
            'pre.label_creditor_id' => 'Creditor identifier',
            'pre.label_mandate'    => 'Mandate reference',
            'pre.label_amount'     => 'Amount',
            'pre.label_due_date'   => 'Due date',
            'pre.label_account'    => 'Your account',
            'pre.statement_heading' => 'Itemised statement',
            'pre.statement_intro'  => 'These entries make up the amount:',
            'pre.statement_period' => 'Period {from} to {to}',
            'pre.statement_empty'  => 'No individual entries are recorded for this collection.',
            'pre.total_label'      => 'Total',
            'pre.funding_note'     => 'Please make sure your account is funded on the due date. '
                                    . 'The cost of a returned debit is charged to the member.',
            'pre.objection_heading' => 'Something wrong?',
            'pre.objection_text'   => 'Please raise it with the treasurer{contact} within six weeks. '
                                    . 'Replying to this email is enough.',
            'pre.objection_contact' => ' at {email}',

            'cancel.subject'   => 'The announced direct debit has been called off',
            'cancel.preheader' => 'The direct debit announced for {date} will not be collected.',
            'cancel.eyebrow'   => 'Collection called off',
            'cancel.title'     => 'The announced collection will not take place',
            'cancel.lede'      => 'the direct debit of <strong>{amount}</strong> announced for '
                                . '<strong>{date}</strong> will <strong>not</strong> be collected.',
            'cancel.lede_text' => 'the direct debit of {amount} announced for {date} will NOT be collected.',
            'cancel.no_action' => 'There is nothing for you to do. Your account will not be debited for this.',
            'cancel.next'      => 'Anything still owed stays open and may be announced again with a later collection.',
            'cancel.label_amount'   => 'Announced amount',
            'cancel.label_due_date' => 'Announced due date',

            'greeting'         => 'Hello {name},',
            'greeting_generic' => 'Hello,',
            'signoff'          => 'Kind regards',
            'questions'        => 'Questions about this message? Simply reply to it.',
            'text_separator'   => '----------------------------------------------------------',
            'automated_note'   => 'This message was generated automatically.',
        ],
    ];
}
