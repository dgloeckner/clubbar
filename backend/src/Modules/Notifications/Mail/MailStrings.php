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
 * German is the **Du-form**, capitalised as correspondence conventionally
 * capitalises it, and members are addressed by first name alone. That matches
 * the terminal UI (#42) and how the club talks to its own members: a bank's
 * register would be borrowed formality from an institution nobody here is.
 *
 * It is the one place to change it — every pronoun in the mails is in this
 * table, and nothing else in the codebase inflects German.
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
            'pre.lede'             => 'wir kündigen Dir den Einzug von <strong>{amount}</strong> von Deinem Konto an. '
                                    . 'Der Betrag wird frühestens am <strong>{date}</strong> abgebucht.',
            'pre.lede_text'        => 'wir kündigen Dir den Einzug von {amount} von Deinem Konto an. '
                                    . 'Der Betrag wird frühestens am {date} abgebucht.',
            'pre.data_heading'     => 'Angaben zum Einzug',
            'pre.label_creditor'   => 'Gläubiger',
            'pre.label_creditor_id' => 'Gläubiger-Identifikationsnummer',
            'pre.label_mandate'    => 'Mandatsreferenz',
            'pre.label_amount'     => 'Betrag',
            'pre.label_due_date'   => 'Fälligkeit',
            'pre.label_account'    => 'Dein Konto',
            'pre.statement_heading' => 'Abrechnungsübersicht',
            'pre.statement_intro'  => 'Diese Buchungen ergeben den Betrag:',
            'pre.statement_period' => 'Zeitraum {from} bis {to}',
            'pre.statement_empty'  => 'Zu diesem Einzug liegen keine Einzelbuchungen vor.',
            'pre.total_label'      => 'Gesamtbetrag',
            'pre.funding_note'     => 'Bitte sorge dafür, dass Dein Konto am Fälligkeitstag gedeckt ist. '
                                    . 'Die Kosten einer Rücklastschrift trägt das Mitglied.',
            'pre.objection_heading' => 'Beanstandungen',
            'pre.objection_text'   => 'Stimmt etwas nicht? Bitte melde Dich innerhalb von sechs Wochen '
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
            'cancel.no_action' => 'Du musst nichts weiter tun. Dein Konto wird für diesen Einzug nicht belastet.',
            'cancel.next'      => 'Offene Beträge bleiben offen und werden gegebenenfalls mit einem '
                                . 'späteren Einzug angekündigt.',
            'cancel.label_amount'   => 'Angekündigter Betrag',
            'cancel.label_due_date' => 'Angekündigte Fälligkeit',

            // ── Terminal-Auffälligkeit (ADR-0041) ───────────────────────────
            // Bewusst zurückhaltend formuliert: jede gemeldete Art hat eine
            // harmlose Erklärung, und eine Mail, die mit „Dein Terminal wurde
            // gestohlen" beginnt, wird so oft falsch liegen, dass sie beim
            // einen Mal, an dem sie recht hat, ignoriert wird.
            'terminal_anomaly.subject'   => 'Terminal „{terminal}“: bitte einmal prüfen',
            'terminal_anomaly.preheader' => 'Möglicherweise wird der Zugang von „{terminal}“ auf mehr als einem Gerät verwendet',
            'terminal_anomaly.eyebrow'   => 'Terminal-Zugang',
            'terminal_anomaly.title'     => 'Auffälligkeit bei einem Terminal-Zugang',
            'terminal_anomaly.lede'      => 'beim Terminal <strong>{terminal}</strong> sieht es so aus, '
                                          . 'als würde sein Zugang auf mehr als einem Gerät verwendet.',
            'terminal_anomaly.lede_text' => 'beim Terminal {terminal} sieht es so aus, '
                                          . 'als würde sein Zugang auf mehr als einem Gerät verwendet.',
            'terminal_anomaly.kind.concurrent_ip' => 'Zwei verschiedene Netzwerk-Adressen waren gleichzeitig aktiv — '
                                          . 'nicht nacheinander, sondern über einen längeren Zeitraum parallel.',
            'terminal_anomaly.kind.cursor_regression' => 'Das Terminal hat einen älteren Synchronisationsstand gemeldet, '
                                          . 'als es zuvor bereits erhalten hatte.',
            'terminal_anomaly.kind.cursor_reset' => 'Das Terminal hat gar keinen Synchronisationsstand gemeldet, '
                                          . 'obwohl es zuvor bereits einen hatte.',
            'terminal_anomaly.innocent.concurrent_ip' => 'Harmlos ist das, wenn der Anschluss zwei Internet-Leitungen '
                                          . 'parallel nutzt. Ein gewöhnlicher Wechsel der IP-Adresse löst diese '
                                          . 'Meldung nicht aus.',
            'terminal_anomaly.innocent.cursor_regression' => 'Harmlos ist das, wenn das Terminal aus einer Sicherung '
                                          . 'wiederhergestellt wurde.',
            'terminal_anomaly.innocent.cursor_reset' => 'Harmlos ist das, wenn das Terminal neu eingerichtet oder '
                                          . 'seine lokale Datenbank geleert wurde.',
            'terminal_anomaly.next'      => 'Bitte prüfe, ob Du die zweite Verwendung erklären kannst. '
                                          . 'Falls nicht, kannst Du den Zugang im Admin-Bereich erneuern oder entziehen.',
            'terminal_anomaly.no_action_taken' => 'Es wurde nichts gesperrt und nichts geändert — der Betrieb an der '
                                          . 'Theke läuft unverändert weiter.',

            // ── Shared ──────────────────────────────────────────────────────
            'greeting'         => 'Hallo {name},',
            'greeting_generic' => 'Hallo,',
            'signoff'          => 'Viele Grüße',
            'questions'        => 'Fragen zu dieser Nachricht? Antworte einfach darauf.',
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

            'terminal_anomaly.subject'   => 'Terminal "{terminal}": worth a look',
            'terminal_anomaly.preheader' => 'The credential for "{terminal}" may be in use on more than one device',
            'terminal_anomaly.eyebrow'   => 'Terminal credential',
            'terminal_anomaly.title'     => 'Something looks off about a terminal credential',
            'terminal_anomaly.lede'      => 'the credential for terminal <strong>{terminal}</strong> looks as though '
                                          . 'it is being used on more than one device.',
            'terminal_anomaly.lede_text' => 'the credential for terminal {terminal} looks as though '
                                          . 'it is being used on more than one device.',
            'terminal_anomaly.kind.concurrent_ip' => 'Two different network addresses were active at the same time — '
                                          . 'not one after the other, but overlapping for a sustained period.',
            'terminal_anomaly.kind.cursor_regression' => 'The terminal reported an older synchronisation position '
                                          . 'than one it had already been given.',
            'terminal_anomaly.kind.cursor_reset' => 'The terminal reported no synchronisation position at all, '
                                          . 'having previously had one.',
            'terminal_anomaly.innocent.concurrent_ip' => 'This is harmless if the site runs two internet connections '
                                          . 'at once. An ordinary change of IP address does not trigger this message.',
            'terminal_anomaly.innocent.cursor_regression' => 'This is harmless if the terminal was restored from a backup.',
            'terminal_anomaly.innocent.cursor_reset' => 'This is harmless if the terminal was re-provisioned or its '
                                          . 'local database was cleared.',
            'terminal_anomaly.next'      => 'Please check whether you can account for the second use. If not, you can '
                                          . 'rotate or revoke the credential in the admin panel.',
            'terminal_anomaly.no_action_taken' => 'Nothing has been blocked and nothing has been changed — the bar '
                                          . 'carries on exactly as before.',

            'greeting'         => 'Hello {name},',
            'greeting_generic' => 'Hello,',
            'signoff'          => 'Kind regards',
            'questions'        => 'Questions about this message? Simply reply to it.',
            'text_separator'   => '----------------------------------------------------------',
            'automated_note'   => 'This message was generated automatically.',
        ],
    ];
}
