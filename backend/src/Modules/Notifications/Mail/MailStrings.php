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

            // ── Deckelauszug (ADR-0039) ─────────────────────────────────────
            // Kein Wort aus der Vorabankündigung wandert hierher: kein Einzug,
            // keine Fälligkeit, keine Mandatsreferenz, kein „Mahnung". Der
            // Auszug berichtet einen Stand und fordert nichts.
            'statement.subject'      => 'Dein Deckel — Stand {date}',
            'statement.preheader'    => 'Dein Kontostand an der Theke zum {date}',
            'statement.eyebrow'      => 'Deckelauszug',
            'statement.title'        => 'Dein Deckel',
            'statement.lede'         => 'hier ist Dein Deckel mit Stand vom <strong>{date}</strong>. '
                                      . 'Das ist eine reine Übersicht — Du musst nichts tun und es wird '
                                      . 'nichts eingezogen.',
            'statement.lede_text'    => 'hier ist Dein Deckel mit Stand vom {date}. Das ist eine reine '
                                      . 'Übersicht — Du musst nichts tun und es wird nichts eingezogen.',
            'statement.as_of_label'  => 'Stand',
            'statement.balance_label' => 'Offener Betrag',
            'statement.credit_label' => 'Guthaben',
            'statement.lines_heading' => 'Deine Buchungen',
            'statement.lines_intro'  => 'Diese Buchungen sind noch nicht abgerechnet:',
            'statement.empty'        => 'Zum Stichtag war Dein Deckel ausgeglichen — es sind keine offenen '
                                      . 'Buchungen vorhanden.',
            'statement.total_label'  => 'Summe',
            'statement.capped'       => '…und {count} weitere Buchungen. Die Summe oben ist über '
                                      . '<strong>alle</strong> offenen Buchungen gerechnet, nicht nur über '
                                      . 'die hier aufgeführten.',
            'statement.capped_text'  => '…und {count} weitere Buchungen. Die Summe oben ist über ALLE offenen '
                                      . 'Buchungen gerechnet, nicht nur über die hier aufgeführten.',
            'statement.credit_note'  => 'Der Verein schuldet Dir derzeit {amount}. Das Guthaben wird mit '
                                      . 'Deinen nächsten Buchungen verrechnet; eine Auszahlung erfolgt nur '
                                      . 'auf Anfrage.',
            'statement.zero_note'    => 'Es steht nichts offen.',
            'statement.limit_ok'     => 'Dein Limit an der Theke liegt bei {limit}.',
            'statement.limit_approaching' => 'Dein Limit an der Theke liegt bei {limit} — Du näherst Dich ihm. '
                                      . 'Ist es erreicht, nimmt das Terminal keine weitere Bestellung mehr an.',
            'statement.limit_exceeded' => 'Damit ist Dein Limit von {limit} überschritten: das Terminal nimmt '
                                      . 'keine weitere Bestellung an, bis der Deckel abgerechnet ist.',
            'statement.limit_heading' => 'Dein Limit',
            'statement.questions_heading' => 'Stimmt etwas nicht?',
            'statement.questions_text' => 'Melde Dich einfach beim Kassenwart{contact} — eine Antwort auf '
                                      . 'diese E-Mail genügt.',
            'statement.questions_contact' => ' unter {email}',
            'statement.line_storno'  => 'Storno {label}',
            'statement.line_storno_from' => '{label} (aus Abrechnung {period})',

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

            // ── Admin email change ──────────────────────────────────────────
            'email_changed.subject'   => 'Die Anmelde-E-Mail-Adresse Deines Admin-Kontos wurde geändert',
            'email_changed.preheader' => 'Diese Adresse ist nicht mehr die Anmeldeadresse des Kontos.',
            'email_changed.eyebrow'   => 'Sicherheitshinweis',
            'email_changed.title'     => 'Anmeldeadresse geändert',
            'email_changed.lede'      => 'die Anmelde-E-Mail-Adresse Deines Admin-Kontos wurde geändert. '
                                       . 'Diese Adresse — <strong>{former}</strong> — kann sich nicht mehr anmelden.',
            'email_changed.lede_text' => 'die Anmelde-E-Mail-Adresse Deines Admin-Kontos wurde geändert. '
                                       . 'Diese Adresse ({former}) kann sich nicht mehr anmelden.',
            'email_changed.label_former' => 'Bisherige Adresse',
            'email_changed.label_when'   => 'Geändert am',
            'email_changed.expected'  => 'Wenn Du das selbst warst, ist nichts weiter zu tun.',
            'email_changed.unexpected' => 'Wenn nicht, wende Dich bitte sofort an eine andere '
                                        . 'Administratorin oder einen anderen Administrator: '
                                        . 'nur sie können das Konto zurücksetzen.',

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

            'statement.subject'      => 'Your tab — as at {date}',
            'statement.preheader'    => 'What your bar tab stood at on {date}',
            'statement.eyebrow'      => 'Tab statement',
            'statement.title'        => 'Your tab',
            'statement.lede'         => 'here is your tab as it stood on <strong>{date}</strong>. This is a '
                                      . 'statement only — there is nothing to do and nothing will be collected.',
            'statement.lede_text'    => 'here is your tab as it stood on {date}. This is a statement only — '
                                      . 'there is nothing to do and nothing will be collected.',
            'statement.as_of_label'  => 'As at',
            'statement.balance_label' => 'Outstanding',
            'statement.credit_label' => 'In credit',
            'statement.lines_heading' => 'Your entries',
            'statement.lines_intro'  => 'These entries have not been settled yet:',
            'statement.empty'        => 'Your tab was clear on that date — there are no outstanding entries.',
            'statement.total_label'  => 'Total',
            'statement.capped'       => '…and {count} further entries. The total above is calculated over '
                                      . '<strong>all</strong> outstanding entries, not only the ones listed here.',
            'statement.capped_text'  => '…and {count} further entries. The total above is calculated over ALL '
                                      . 'outstanding entries, not only the ones listed here.',
            'statement.credit_note'  => 'The club currently owes you {amount}. The credit is set against your '
                                      . 'next entries; a payout happens only on request.',
            'statement.zero_note'    => 'Nothing is outstanding.',
            'statement.limit_ok'     => 'Your limit at the bar is {limit}.',
            'statement.limit_approaching' => 'Your limit at the bar is {limit}, and you are approaching it. Once '
                                      . 'it is reached the terminal will not accept another order.',
            'statement.limit_exceeded' => 'That puts you past your limit of {limit}: the terminal will not accept '
                                      . 'another order until the tab has been settled.',
            'statement.limit_heading' => 'Your limit',
            'statement.questions_heading' => 'Something wrong?',
            'statement.questions_text' => 'Just raise it with the treasurer{contact} — replying to this email '
                                      . 'is enough.',
            'statement.questions_contact' => ' at {email}',
            'statement.line_storno'  => 'Reversal of {label}',
            'statement.line_storno_from' => '{label} (from settlement {period})',

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

            'email_changed.subject'   => 'The sign-in email address for your admin account was changed',
            'email_changed.preheader' => 'This address is no longer the account’s sign-in address.',
            'email_changed.eyebrow'   => 'Security notice',
            'email_changed.title'     => 'Sign-in address changed',
            'email_changed.lede'      => 'the sign-in email address for your admin account was changed. '
                                       . 'This address — <strong>{former}</strong> — can no longer sign in.',
            'email_changed.lede_text' => 'the sign-in email address for your admin account was changed. '
                                       . 'This address ({former}) can no longer sign in.',
            'email_changed.label_former' => 'Previous address',
            'email_changed.label_when'   => 'Changed on',
            'email_changed.expected'  => 'If this was you, there is nothing further to do.',
            'email_changed.unexpected' => 'If it was not, contact another administrator immediately: '
                                        . 'only they can reset the account.',

            'greeting'         => 'Hello {name},',
            'greeting_generic' => 'Hello,',
            'signoff'          => 'Kind regards',
            'questions'        => 'Questions about this message? Simply reply to it.',
            'text_separator'   => '----------------------------------------------------------',
            'automated_note'   => 'This message was generated automatically.',
        ],
    ];
}
