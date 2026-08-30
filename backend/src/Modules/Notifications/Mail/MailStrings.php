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
            'pre.label_reference'  => 'Abrechnungsnummer',
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
            'cancel.label_reference' => 'Abrechnungsnummer',

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
            'jugendschutz.subject'   => 'Jugendschutz: ein Verkauf muss geprüft werden',
            'jugendschutz.preheader' => 'Ein altersbeschränktes Getränk wurde unter dem Mindestalter verkauft',
            'jugendschutz.eyebrow'   => 'Jugendschutz',
            'jugendschutz.title'     => 'Ein Verkauf unter dem Mindestalter',
            'jugendschutz.lede'      => 'am Tresen wurde ein Getränk ausgegeben, das ein Mindestalter von '
                . '<strong>{age} Jahren</strong> hat — an jemanden, der dieses Alter zu diesem Zeitpunkt '
                . 'noch nicht erreicht hatte.',
            'jugendschutz.lede_text' => 'am Tresen wurde ein Getränk ausgegeben, das ein Mindestalter von '
                . '{age} Jahren hat — an jemanden, der dieses Alter zu diesem Zeitpunkt noch nicht erreicht hatte.',
            'jugendschutz.why'       => 'Das Terminal weist solche Verkäufe normalerweise ab. Dieser hier ist '
                . 'durchgekommen, weil das Terminal zum Verkaufszeitpunkt noch einen älteren Datenstand hatte.',
            'jugendschutz.row.product'     => 'Getränk',
            'jugendschutz.row.required'    => 'Mindestalter',
            'jugendschutz.row.occurred'    => 'Verkauft am',
            'jugendschutz.row.terminal'    => 'Terminal',
            'jugendschutz.row.transaction' => 'Buchung',
            'jugendschutz.product_deleted' => '(inzwischen gelöscht)',
            'jugendschutz.years'     => '{age} Jahre',
            'jugendschutz.next'      => 'Die Buchung bleibt bestehen — sie ist der Beleg dafür, dass der Verkauf '
                . 'stattgefunden hat, und wird nicht storniert. Bitte im Vorstand klären, wie damit umgegangen wird, '
                . 'und den Hinweis anschließend im Dashboard als erledigt markieren.',
            'jugendschutz.no_member' => 'Wer das Getränk gekauft hat, steht bewusst nicht in dieser E-Mail. '
                . 'Die Buchungsnummer oben führt zum Vorgang.',

            // Der Deckel-Übersicht (ADR-0047, Migration 054). Sie-Form wäre hier
            // ebenso falsch wie überall sonst in dieser Tabelle: es ist dieselbe
            // Ansprache wie am Tresen, nur an den Vorstand statt an das Mitglied.

            // The backup job has stopped (#693, ADR-0049). Row ids in, whole
            // sentences out: the self-check's own text is English, and this
            // mail goes to each admin in their own language.
            'backup_health.subject'          => 'Die Datensicherung funktioniert nicht',
            'backup_health.subject_cleared'  => 'Datensicherung: das Problem hat sich erledigt',
            'backup_health.preheader'        => 'Es gibt derzeit keine verwendbare Sicherung dieser Installation.',
            'backup_health.preheader_cleared' => 'Die Warnung war berechtigt, die Sicherung läuft inzwischen wieder.',
            'backup_health.eyebrow'          => 'Datensicherung',
            'backup_health.title'            => 'Die Datensicherung funktioniert nicht',
            'backup_health.lede'             => 'Die nächtliche verschlüsselte Sicherung dieser Installation ist eingeschaltet, tut aber nicht, was sie soll. Solange das so bleibt, gibt es im Ernstfall nichts, worauf zurückgegriffen werden kann.',
            'backup_health.cleared_lede'     => 'Diese Nachricht wurde verschickt, weil die Sicherung nicht lief. Inzwischen läuft sie wieder — es ist nichts zu tun.',
            'backup_health.where'            => 'Die gemessenen Werte — wie alt das jüngste Archiv ist, wann zuletzt etwas den Server verlassen hat, wie viel Platz belegt ist — stehen im Admin-Bereich unter Einstellungen → Sicherheit.',
            'backup_health.no_detail'        => 'Diese E-Mail nennt bewusst keine Pfade, Dateinamen oder Größen.',
            'backup_health.row.backup_ever_ran.problem'      => 'Die Sicherung ist eingeschaltet, wurde aber noch nie ausgeführt',
            'backup_health.row.backup_ever_ran.consequence'  => 'Sehr wahrscheinlich fehlt der nächtliche Cronjob im Hosting-Panel. Der letzte Schritt des Installationsassistenten zeigt den Befehl, der dort einzutragen ist.',
            'backup_health.row.backup_last_run.problem'      => 'Es liegt kein aktuelles Archiv vor',
            'backup_health.row.backup_last_run.consequence'  => 'Entweder läuft die geplante Aufgabe nicht mehr, oder jeder Lauf bricht ab, bevor etwas geschrieben wird. Der Sicherungsordner enthält ein Journal, das den Grund nennt.',
            'backup_health.row.backup_last_upload.problem'     => 'Es geht nichts mehr außer Haus',
            'backup_health.row.backup_last_upload.consequence' => 'Lokal wird weiter gesichert, die Kopie außerhalb des Servers veraltet aber. Das ist der Ausfall, der am längsten unbemerkt bleibt: Wer den Webspace verliert, verliert dann beides.',
            'backup_health.row.backup_local_size.problem'      => 'Die Archive überschreiten das eingestellte Limit',
            'backup_health.row.backup_local_size.consequence'  => 'Ein volles Webspace nimmt gar nichts mehr an — keine Mandate, keine Protokolle, keine weitere Sicherung. Die Aufräumroutine kommt offenbar nicht hinterher.',
            'backup_health.row.unknown.problem'      => 'Eine Prüfung der Datensicherung schlägt fehl',
            'backup_health.row.unknown.consequence'  => 'Welche, steht im Admin-Bereich unter Einstellungen → Sicherheit.',

            'credit_digest.subject'        => '{count} Mitglieder nahe am Deckel-Limit',
            'credit_digest.subject_empty'  => 'Deckel-Übersicht: derzeit niemand nahe am Limit',
            'credit_digest.preheader'      => '{count} Mitglieder, zusammen {total} offen',
            'credit_digest.preheader_empty' => 'Zurzeit ist kein Deckel im Warnbereich',
            'credit_digest.eyebrow'        => 'Deckel-Übersicht',
            'credit_digest.title'          => 'Mitglieder nahe am Deckel-Limit',
            'credit_digest.lede'           => 'bei <strong>{count}</strong> Mitgliedern hat der Deckel den '
                . 'Warnbereich erreicht. Das Terminal weist sie beim nächsten Einkauf darauf hin und lehnt ab, '
                . 'sobald das Limit überschritten wäre.',
            'credit_digest.lede_text'      => 'bei {count} Mitgliedern hat der Deckel den Warnbereich erreicht. '
                . 'Das Terminal weist sie beim nächsten Einkauf darauf hin und lehnt ab, sobald das Limit '
                . 'überschritten wäre.',
            'credit_digest.empty_lede'     => 'zurzeit ist kein Deckel im Warnbereich — es ist also nichts zu tun. '
                . 'Diese Nachricht wurde verschickt, weil sich zwischen Erstellung und Versand noch etwas '
                . 'geändert hat.',
            'credit_digest.empty_lede_text' => 'zurzeit ist kein Deckel im Warnbereich — es ist also nichts zu tun. '
                . 'Diese Nachricht wurde verschickt, weil sich zwischen Erstellung und Versand noch etwas '
                . 'geändert hat.',
            'credit_digest.percent'        => '{percent} %',
            'credit_digest.of_limit'       => '{balance} von {limit}',
            'credit_digest.total_label'    => 'Summe der offenen Deckel',
            'credit_digest.status.exceeded' => 'Limit überschritten',
            'credit_digest.exceeded_heading' => 'Bereits über dem Limit',
            'credit_digest.exceeded_body'  => '{count} davon sind über ihrem Limit. Diese Mitglieder können am '
                . 'Terminal nichts mehr kaufen, bis der Deckel abgerechnet oder das Limit angehoben wird.',
            'credit_digest.policy'         => 'Das Vereins-Limit liegt bei {limit}; gewarnt wird ab {percent} % '
                . 'davon. Einzelne Mitglieder können ein eigenes Limit haben — in der Tabelle steht immer das, '
                . 'das für das jeweilige Mitglied gilt.',
            'credit_digest.next'           => 'Zu ändern ist hier nichts: Diese Übersicht sammelt nur, was im '
                . 'Dashboard ohnehin steht. Wie oft sie kommt, lässt sich unter Einstellungen → E-Mail einstellen.',
            'credit_digest.omitted'        => 'Weitere {count} Mitglieder sind ebenfalls im Warnbereich; diese Liste zeigt nur die vollsten Deckel. Die vollständige Liste steht im Dashboard.',
            'credit_digest.unnamed_member' => '(ohne Namen)',
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

            // ── Ablaufende Zugangsdaten (#438, ADR-0036) ────────────────────
            // Der Ton steigt mit der Stufe, der Inhalt nicht: dieselben vier
            // Angaben bei 90, 30 und 7 Tagen. Was sich ändert, ist die
            // Dringlichkeit — und die Folge, die im Betreff schon bei 7 Tagen
            // steht, weil dann niemand mehr eine Mail aufklappt, um sie zu
            // finden.
            'credential_expiry.subject.90' => '{name}: läuft in {days} Tagen ab',
            'credential_expiry.subject.30' => '{name}: läuft in {days} Tagen ab — bitte einplanen',
            'credential_expiry.subject.7'  => 'Dringend: {name} läuft in {days} Tagen ab',
            'credential_expiry.preheader'  => 'Gültig bis {date} · danach {consequence}',
            'credential_expiry.eyebrow'    => 'Zugangsdaten',
            'credential_expiry.title'      => 'Ein Zugang läuft ab',

            'credential_expiry.lede.key'  => 'der Schlüssel, mit dem die Bankverbindungen der Mitglieder '
                                           . 'verschlüsselt werden, läuft am <strong>{date}</strong> ab — '
                                           . 'in <strong>{days} Tagen</strong>.',
            'credential_expiry.lede_text.key' => 'der Schlüssel, mit dem die Bankverbindungen der Mitglieder '
                                           . 'verschlüsselt werden, läuft am {date} ab — in {days} Tagen.',
            'credential_expiry.lede.terminal' => 'der Zugang des Terminals <strong>{name}</strong> läuft am '
                                           . '<strong>{date}</strong> ab — in <strong>{days} Tagen</strong>.',
            'credential_expiry.lede_text.terminal' => 'der Zugang des Terminals {name} läuft am {date} ab — '
                                           . 'in {days} Tagen.',
            'credential_expiry.lede.backup_secret' => 'das Kennwort, mit dem die nächtliche Sicherung zu '
                                           . '<strong>{name}</strong> hochgeladen wird, läuft am '
                                           . '<strong>{date}</strong> ab — in <strong>{days} Tagen</strong>.',
            'credential_expiry.lede_text.backup_secret' => 'das Kennwort, mit dem die nächtliche Sicherung zu '
                                           . '{name} hochgeladen wird, läuft am {date} ab — in {days} Tagen.',

            // „Art", not „Zugang": the value in this row is already the word
            // Zugang, and a table line reading „Zugang: Terminal-Zugang" says
            // nothing twice.
            'credential_expiry.label_credential' => 'Art',
            'credential_expiry.label_name'       => 'Bezeichnung',
            'credential_expiry.label_expires'    => 'Gültig bis',
            'credential_expiry.label_days'       => 'Verbleibend',
            'credential_expiry.days'             => '{days} Tage',
            'credential_expiry.credential.key'      => 'Schlüssel für Bankverbindungen',
            'credential_expiry.credential.terminal' => 'Terminal-Zugang',
            'credential_expiry.credential.backup_secret' => 'Kennwort für die Sicherung',
            'credential_expiry.subject_name.key'      => 'Schlüssel für Bankverbindungen',
            'credential_expiry.subject_name.terminal' => 'Terminal-Zugang „{name}“',
            'credential_expiry.subject_name.backup_secret' => 'Kennwort für die Sicherung',

            'credential_expiry.consequence.key' => 'Läuft er ab, können keine Bankverbindungen mehr gespeichert '
                                           . 'und keine SEPA-Dateien mehr erzeugt werden. Bestehende Daten '
                                           . 'bleiben unverändert erhalten.',
            'credential_expiry.consequence.terminal' => 'Läuft er ab, kann sich das Terminal nicht mehr anmelden '
                                           . 'und an der Theke nichts mehr gebucht werden. Bereits erfasste '
                                           . 'Buchungen bleiben auf dem Gerät erhalten.',
            // Der Satz, auf den es ankommt: die Sicherung *läuft weiter* und
            // sieht gesund aus. Nur die Hälfte, die sie aus dem Haus bringt,
            // hört auf — und darüber sagt Microsoft von sich aus nichts.
            'credential_expiry.consequence.backup_secret' => 'Läuft es ab, wird die Sicherung weiterhin '
                                           . 'geschrieben und verschlüsselt, aber nicht mehr hochgeladen. Die '
                                           . 'Sicherung liegt dann nur noch auf demselben Webspace wie die '
                                           . 'Datenbank — ein Ausfall des Hosting-Kontos nimmt beide mit. Der '
                                           . 'nächtliche Lauf meldet den Fehler, sonst weist nichts darauf hin.',
            'credential_expiry.consequence_short.key'      => 'keine SEPA-Dateien mehr',
            'credential_expiry.consequence_short.terminal' => 'keine Buchungen mehr an diesem Terminal',
            'credential_expiry.consequence_short.backup_secret' => 'keine Sicherung mehr außer Haus',

            // Beide Texte benennen den Weg so, wie die Oberfläche ihn
            // beschriftet — Reiter „Schlüssel“, Knopf „Token rotieren“. Eine
            // Mail, die zu einem Menüpunkt schickt, den es nicht gibt, kostet
            // mehr Zeit als sie spart.
            'credential_expiry.next.key' => 'Unter Einstellungen → Schlüssel registrierst und aktivierst Du den '
                                           . 'Nachfolger; das geht ohne den privaten Schlüssel. Zum Umschlüsseln '
                                           . 'der bereits gespeicherten Bankverbindungen wird anschließend die '
                                           . 'private Hälfte des bisherigen Schlüssels gebraucht — die liegt '
                                           . 'beim Verein.',
            'credential_expiry.next.terminal' => 'Unter Einstellungen → Terminals genügt „Token rotieren“. Das '
                                           . 'bisherige Token funktioniert weiter, bis das neue am Terminal zum '
                                           . 'ersten Mal verwendet wird — die Theke bleibt in der Zwischenzeit '
                                           . 'online.',
            'credential_expiry.next.backup_secret' => 'Ein neues Kennwort legst Du mit '
                                           . 'scripts/setup-msgraph-backup.ps1 -RotateSecretOnly an; die '
                                           . 'Anleitung steht in docs/m365-backup-target.md. Trage es zusammen '
                                           . 'mit dem neuen Ablaufdatum in die config.php ein, warte einen '
                                           . 'erfolgreichen Lauf ab und lösche erst danach das alte — bis dahin '
                                           . 'funktionieren beide.',
            'credential_expiry.no_secret' => 'Diese Nachricht enthält bewusst keinerlei Schlüssel- oder '
                                           . 'Zugangsdaten — nur, worum es geht und bis wann.',

            // ── Terminal-Zugang ausgestellt (ADR-0043) ──────────────────────
            'terminal_token_issued.subject.enrolled' => 'Neues Terminal „{name}“ eingerichtet',
            'terminal_token_issued.subject.rotated'  => 'Neuer Zugang für Terminal „{name}“ ausgestellt',
            'terminal_token_issued.preheader' => '{name} · {moment} — falls das nicht wir waren, bitte sperren.',
            'terminal_token_issued.eyebrow'   => 'Sicherheitshinweis',
            'terminal_token_issued.title'     => 'Ein Terminal-Zugang wurde ausgestellt',
            'terminal_token_issued.lede.enrolled' => 'am {moment} wurde das Terminal <strong>{name}</strong> '
                                           . 'eingerichtet und hat einen eigenen Zugang bekommen. Damit kann es '
                                           . 'die Mitgliederliste abrufen und Buchungen anlegen.',
            'terminal_token_issued.lede_text.enrolled' => 'am {moment} wurde das Terminal {name} eingerichtet und '
                                           . 'hat einen eigenen Zugang bekommen. Damit kann es die Mitgliederliste '
                                           . 'abrufen und Buchungen anlegen.',
            'terminal_token_issued.lede.rotated' => 'am {moment} wurde für das Terminal <strong>{name}</strong> ein '
                                           . 'neuer Zugang ausgestellt. Der bisherige funktioniert weiter, bis der '
                                           . 'neue am Terminal zum ersten Mal verwendet wird.',
            'terminal_token_issued.lede_text.rotated' => 'am {moment} wurde für das Terminal {name} ein neuer Zugang '
                                           . 'ausgestellt. Der bisherige funktioniert weiter, bis der neue am '
                                           . 'Terminal zum ersten Mal verwendet wird.',
            'terminal_token_issued.label_terminal' => 'Terminal',
            'terminal_token_issued.label_device'   => 'Geräte-Kennung',
            'terminal_token_issued.label_event'    => 'Vorgang',
            'terminal_token_issued.label_actor'    => 'Ausgeführt von',
            'terminal_token_issued.label_issued'   => 'Ausgestellt am',
            'terminal_token_issued.label_expires'  => 'Gültig bis',
            'terminal_token_issued.event.enrolled' => 'Terminal eingerichtet',
            'terminal_token_issued.event.rotated'  => 'Token rotiert',
            'terminal_token_issued.action_heading' => 'War das nicht der Verein?',
            'terminal_token_issued.action' => 'Dann sperre den Zugang sofort: Einstellungen → Terminals → '
                                           . '„Zugang sperren“. Das Terminal ist danach abgemeldet, und wer das '
                                           . 'Token hat, kann nichts mehr damit anfangen. Wer den Zugang '
                                           . 'ausgestellt hat, steht im Protokoll unter Einstellungen → Protokoll.',
            'terminal_token_issued.why_everyone' => 'Diese Nachricht geht an alle aktiven Administratorinnen und '
                                           . 'Administratoren — auch an die Person, die den Zugang ausgestellt '
                                           . 'hat. Genau das ist der Zweck: Wer ein Konto übernommen hat, erreicht '
                                           . 'die Postfächer der anderen nicht.',
            'terminal_token_issued.no_secret' => 'Diese Nachricht enthält bewusst keinerlei Zugangsdaten — das '
                                           . 'Token selbst wird nur einmal im Adminbereich angezeigt und nirgends '
                                           . 'gespeichert.',

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

            // ── Einladung eines neuen Admin-Kontos (Migration 058) ──────────
            // Die einzige Nachricht, deren Text ein gültiges Zugangsmittel
            // enthält. Der Link steht deshalb zusätzlich als Klartext im
            // Fließtext, und die Gültigkeit wird ausdrücklich genannt: ein
            // stillschweigend abgelaufener Link liest sich als Fehler und nicht
            // als Absicht.
            'admin_invitation.subject'   => 'Dein Admin-Zugang für {org}',
            'admin_invitation.preheader' => 'Lege ein Passwort fest, um Deinen Admin-Zugang zu aktivieren.',
            'admin_invitation.eyebrow'   => 'Einladung',
            'admin_invitation.title'     => 'Willkommen im Admin-Bereich',
            'admin_invitation.lede'      => 'für Dich wurde ein Admin-Zugang zu <strong>{org}</strong> angelegt. '
                                          . 'Über den Link unten legst Du Dein eigenes Passwort fest — '
                                          . 'niemand sonst kennt es.',
            'admin_invitation.lede_text' => 'für Dich wurde ein Admin-Zugang zu {org} angelegt. '
                                          . 'Über den Link unten legst Du Dein eigenes Passwort fest — '
                                          . 'niemand sonst kennt es.',
            'admin_invitation.label_login'   => 'Anmeldeadresse',
            'admin_invitation.label_expires' => 'Link gültig bis',
            'admin_invitation.cta'       => 'Passwort festlegen',
            'admin_invitation.fallback'  => 'Falls der Button nicht funktioniert, öffne diese Adresse im Browser:',
            'admin_invitation.next_step' => 'Danach meldest Du Dich einmal ganz normal an. Dabei richtest Du '
                                          . 'die Zwei-Faktor-Authentifizierung mit einer Authenticator-App '
                                          . 'ein — das gehört zur Anmeldung dazu und dauert eine Minute.',
            'admin_invitation.unexpected' => 'Wenn Du damit nichts anfangen kannst, ignoriere diese E-Mail. '
                                           . 'Der Link läuft von selbst ab, und ohne ihn passiert nichts.',

            // ── Admin-Lebenszyklus (ADR-0044) ───────────────────────────────
            // Geht an alle aktiven Admins *und* an die Vereinsadresse: bei
            // genau einem Admin ginge die Nachricht sonst von der handelnden
            // Person an dieselbe Person über etwas, das sie gerade selbst
            // getan hat.
            'admin_lifecycle.eyebrow'          => 'Sicherheitshinweis',
            'admin_lifecycle.created.subject'  => 'Ein neues Admin-Konto wurde angelegt',
            'admin_lifecycle.created.preheader' => 'Ein neues Konto hat Zugriff auf den Adminbereich.',
            'admin_lifecycle.created.title'    => 'Neues Admin-Konto',
            'admin_lifecycle.created.lede'     => 'im Adminbereich wurde ein neues Konto angelegt: '
                                                . '<strong>{account}</strong>.',
            'admin_lifecycle.created.lede_text' => 'im Adminbereich wurde ein neues Konto angelegt: {account}.',
            'admin_lifecycle.roles.subject'    => 'Die Rollen eines Admin-Kontos wurden geändert',
            'admin_lifecycle.roles.preheader'  => 'Ein Konto hat jetzt andere Rechte im Adminbereich.',
            'admin_lifecycle.roles.title'      => 'Rollen geändert',
            'admin_lifecycle.roles.lede'       => 'die Rollen des Admin-Kontos <strong>{account}</strong> '
                                                . 'wurden geändert.',
            'admin_lifecycle.roles.lede_text'  => 'die Rollen des Admin-Kontos {account} wurden geändert.',
            'admin_lifecycle.label_account'    => 'Konto',
            'admin_lifecycle.label_roles'      => 'Rollen jetzt',
            'admin_lifecycle.label_when'       => 'Zeitpunkt',
            'admin_lifecycle.label_actor'      => 'Ausgeführt von',
            'admin_lifecycle.roles_none'       => '(keine)',
            'admin_lifecycle.expected'         => 'Wenn das so gewollt war, ist nichts weiter zu tun.',
            'admin_lifecycle.unexpected'       => 'Wenn nicht: Im Adminbereich unter Einstellungen → '
                                                . 'Admin-Konten lässt sich das Konto deaktivieren, und das '
                                                . 'Audit-Log zeigt, wer welche Rolle vergeben oder entzogen hat.',
            'admin_lifecycle.no_secret'        => 'Diese Nachricht enthält bewusst keine Zugangsdaten. Ein '
                                                . 'neu vergebenes Passwort wird nur einmal im Adminbereich '
                                                . 'angezeigt und nirgends gespeichert.',
            // Rollennamen bleiben in beiden Sprachen die Wörter, die der Verein
            // benutzt (ADR-0044) — wie Storno und Deckel.
            'admin_lifecycle.role.admin'         => 'Admin',
            'admin_lifecycle.role.kassenwart'    => 'Kassenwart',
            'admin_lifecycle.role.getraenkewart' => 'Getränkewart',

            // ── Schlüssel-Lebenszyklus (ADR-0036) ───────────────────────────
            'key_event.subject.registered' => 'Ein neuer Schlüssel für Bankverbindungen wurde hinterlegt',
            'key_event.subject.activated'  => 'Die Bankverbindungen werden ab sofort mit einem anderen '
                                            . 'Schlüssel verschlüsselt',
            'key_event.subject.revoked'    => 'Ein Schlüssel für Bankverbindungen wurde zurückgezogen',
            'key_event.preheader'          => '{name} · Fingerabdruck {short}',
            'key_event.eyebrow'            => 'Sicherheitshinweis',
            'key_event.title.registered'   => 'Neuer Schlüssel hinterlegt',
            'key_event.title.activated'    => 'Schlüssel in Kraft gesetzt',
            'key_event.title.revoked'      => 'Schlüssel zurückgezogen',

            'key_event.lede.registered' => 'ein neuer Schlüssel für die Verschlüsselung der '
                                         . 'Bankverbindungen wurde hinterlegt. Er verschlüsselt noch '
                                         . 'nichts — das geschieht erst beim Aktivieren.',
            'key_event.lede.activated'  => 'ab sofort wird jede gespeicherte Bankverbindung mit diesem '
                                         . 'Schlüssel versiegelt. Nur die private Hälfte aus dem '
                                         . 'Vereinsarchiv kann sie wieder lesen.',
            'key_event.lede.revoked'    => 'ein Schlüssel für die Verschlüsselung der Bankverbindungen '
                                         . 'wurde zurückgezogen.',

            'key_event.label_name'        => 'Bezeichnung',
            'key_event.label_fingerprint' => 'Fingerabdruck (SHA-256)',
            'key_event.label_actor'       => 'Ausgeführt von',
            'key_event.label_when'        => 'Zeitpunkt',

            'key_event.verify'     => 'Bitte vergleiche den Fingerabdruck mit dem, den der '
                                    . 'Offline-Generator beim Erzeugen des Schlüsselpaars angezeigt '
                                    . 'hat. Stimmt er überein, war es der Schlüssel des Vereins.',
            'key_event.unexpected' => 'Wenn niemand im Verein das veranlasst hat, prüfe bitte sofort '
                                    . 'unter Einstellungen → Sicherheit & Zugangsdaten, welcher '
                                    . 'Schlüssel aktiv ist — und ändere vorher keine Bankverbindungen.',

            // ── Member lifecycle (ADR-0051) ─────────────────────────────────
            'member_welcome.subject'   => 'Willkommen bei {club} — deine Karte ist aktiv',
            'member_welcome.preheader' => 'Deine Mitgliedskarte ist freigeschaltet.',
            'member_welcome.eyebrow'   => 'Willkommen',
            'member_welcome.title'     => 'Deine Karte ist aktiv',
            'member_welcome.lede'      => 'deine Mitgliedskarte ist jetzt <strong>freigeschaltet</strong> — '
                                        . 'du kannst damit am Tresen bezahlen.',
            'member_welcome.lede_text' => 'deine Mitgliedskarte ist jetzt freigeschaltet — du kannst damit '
                                        . 'am Tresen bezahlen.',
            'member_welcome.body'      => 'Halte die Karte einfach an das Lesegerät, wähle aus, was du '
                                        . 'nimmst, und bestätige. Bezahlt wird nicht sofort: Was du '
                                        . 'nimmst, läuft auf deinem Deckel zusammen.',
            'member_welcome.not_yet'   => 'Falls du die Karte noch gar nicht bekommen hast: Hier ist sie '
                                        . 'schon eingerichtet und funktioniert ab dem Moment, in dem du '
                                        . 'sie in der Hand hältst. Der Kassenwart gibt sie dir.',
            'member_welcome.next'      => 'Der Deckel wird in Abständen per SEPA-Lastschrift eingezogen. '
                                        . 'Vor jedem Einzug bekommst du mindestens sieben Tage vorher '
                                        . 'eine Vorabankündigung per E-Mail, in der Betrag und '
                                        . 'Fälligkeitsdatum stehen. Es wird nie etwas eingezogen, ohne '
                                        . 'dass du es vorher schriftlich hattest.',
            'member_welcome.unexpected' => 'Wir haben zu dir Name, Geburtsdatum, Kontaktdaten und — für '
                                        . 'den Einzug — deine Bankverbindung gespeichert. Stimmt etwas '
                                        . 'davon nicht, oder hast du diese Nachricht ohne Grund '
                                        . 'bekommen? Antworte einfach darauf.',

            'member_card.subject'   => 'Deine neue Karte ist aktiv',
            'member_card.preheader' => 'Eine andere Karte gilt jetzt für dich.',
            'member_card.eyebrow'   => 'Karte gewechselt',
            'member_card.title'     => 'Deine neue Karte ist aktiv',
            'member_card.lede'      => 'für dich gilt ab sofort eine <strong>andere Karte</strong>.',
            'member_card.lede_text' => 'für dich gilt ab sofort eine andere Karte.',
            'member_card.body'      => 'Die bisherige Karte funktioniert nicht mehr. Dein Deckel und '
                                     . 'deine bisherigen Buchungen bleiben unverändert — es wechselt nur '
                                     . 'die Karte, mit der du dich am Tresen anmeldest.',
            'member_card.not_yet'   => 'Falls du die neue Karte noch gar nicht bekommen hast: Hier ist sie '
                                     . 'schon eingerichtet und funktioniert, sobald du sie in der Hand '
                                     . 'hältst. Bis dahin kannst du am Tresen nicht bezahlen — die alte '
                                     . 'Karte gilt ab sofort nicht mehr.',
            'member_card.next'      => 'Die alte Karte kannst du wegwerfen.',
            'member_card.unexpected' => 'Wenn du keinen Kartenwechsel veranlasst hast, melde dich bitte '
                                     . 'beim Kassenwart — antworte dazu einfach auf diese Nachricht.',

            'member_mail_former.subject'       => 'Deine E-Mail-Adresse wurde geändert',
            'member_mail_former.preheader'     => 'Diese Adresse erhält künftig keine Vereinspost mehr.',
            'member_mail_former.eyebrow'       => 'Adresse geändert',
            'member_mail_former.title'         => 'Deine E-Mail-Adresse wurde geändert',
            'member_mail_former.lede'          => 'für dich ist ab sofort eine <strong>andere '
                                                . 'E-Mail-Adresse</strong> hinterlegt.',
            'member_mail_former.lede_text'     => 'für dich ist ab sofort eine andere E-Mail-Adresse '
                                                . 'hinterlegt.',
            'member_mail_former.label_address' => 'Bisherige Adresse',
            'member_mail_former.label_when'    => 'Geändert am',
            'member_mail_former.expected'      => 'An diese Adresse geht künftig keine Vereinspost mehr — '
                                                . 'keine Vorabankündigung und kein Deckelauszug. Das ist '
                                                . 'die letzte Nachricht hierher.',
            'member_mail_former.unexpected'    => 'Wenn du diese Änderung nicht veranlasst hast, melde '
                                                . 'dich bitte beim Kassenwart. Diese Nachricht geht '
                                                . 'bewusst an deine alte Adresse — nur dorthin erreicht '
                                                . 'dich eine Änderung, die du nicht selbst veranlasst '
                                                . 'hast.',

            'member_mail_current.subject'       => 'Diese Adresse ist jetzt deine Vereinsadresse',
            'member_mail_current.preheader'     => 'Vereinspost geht ab sofort hierher.',
            'member_mail_current.eyebrow'       => 'Adresse aktiv',
            'member_mail_current.title'         => 'Diese Adresse ist jetzt deine Vereinsadresse',
            'member_mail_current.lede'          => 'ab sofort erreicht dich unsere Post unter '
                                                 . '<strong>dieser Adresse</strong>.',
            'member_mail_current.lede_text'     => 'ab sofort erreicht dich unsere Post unter dieser '
                                                 . 'Adresse.',
            'member_mail_current.label_address' => 'Neue Adresse',
            'member_mail_current.label_when'    => 'Geändert am',
            'member_mail_current.expected'      => 'Hierher gehen künftig die Vorabankündigung vor jedem '
                                                 . 'Einzug und — falls der Verein ihn verschickt — der '
                                                 . 'Deckelauszug. Du musst nichts bestätigen; die Adresse '
                                                 . 'ist bereits hinterlegt.',
            'member_mail_current.unexpected'    => 'Wenn du diese Änderung nicht veranlasst hast, melde '
                                                 . 'dich bitte beim Kassenwart — antworte dazu einfach '
                                                 . 'auf diese Nachricht.',

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
            'pre.label_reference'  => 'Settlement reference',
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
            'cancel.label_reference' => 'Settlement reference',

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

            'jugendschutz.subject'   => 'Youth protection: a sale needs review',
            'jugendschutz.preheader' => 'An age-restricted drink was sold below its minimum age',
            'jugendschutz.eyebrow'   => 'Youth protection',
            'jugendschutz.title'     => 'A sale below the minimum age',
            'jugendschutz.lede'      => 'a drink carrying a minimum age of <strong>{age}</strong> was handed over '
                . 'at the bar — to somebody who had not reached that age at the time.',
            'jugendschutz.lede_text' => 'a drink carrying a minimum age of {age} was handed over at the bar — '
                . 'to somebody who had not reached that age at the time.',
            'jugendschutz.why'       => 'The terminal normally refuses such a sale. This one went through because '
                . 'the terminal was still working from an older sync when it happened.',
            'jugendschutz.row.product'     => 'Drink',
            'jugendschutz.row.required'    => 'Minimum age',
            'jugendschutz.row.occurred'    => 'Sold at',
            'jugendschutz.row.terminal'    => 'Terminal',
            'jugendschutz.row.transaction' => 'Transaction',
            'jugendschutz.product_deleted' => '(deleted since)',
            'jugendschutz.years'     => '{age} years',
            'jugendschutz.next'      => 'The booking stands — it is the record that the sale happened and is not '
                . 'reversed. Please agree in the committee how to handle it, then mark the notice as dealt with on '
                . 'the dashboard.',
            'jugendschutz.no_member' => 'Who bought the drink is deliberately not in this email. The transaction '
                . 'number above leads to the record.',

            // The near-limit digest (ADR-0047, migration 054).

            // The backup job has stopped (#693, ADR-0049).
            'backup_health.subject'          => 'The backup is not working',
            'backup_health.subject_cleared'  => 'Backup: the problem has cleared',
            'backup_health.preheader'        => 'There is currently no usable backup of this installation.',
            'backup_health.preheader_cleared' => 'The warning was justified; the backup is running again.',
            'backup_health.eyebrow'          => 'Backup',
            'backup_health.title'            => 'The backup is not working',
            'backup_health.lede'             => 'The nightly encrypted backup of this installation is switched on but is not doing its job. While that stays true, there is nothing to fall back on if something goes wrong.',
            'backup_health.cleared_lede'     => 'This message was sent because the backup was not running. It is running again — there is nothing to do.',
            'backup_health.where'            => 'The measured detail — how old the newest archive is, when anything last left this server, how much space is in use — is in the admin panel under Settings → Security.',
            'backup_health.no_detail'        => 'This email deliberately names no paths, filenames or sizes.',
            'backup_health.row.backup_ever_ran.problem'      => 'Backups are switched on but have never run',
            'backup_health.row.backup_ever_ran.consequence'  => 'The nightly cron job is almost certainly missing from the hosting panel. The installer\'s last step prints the exact command to paste there.',
            'backup_health.row.backup_last_run.problem'      => 'There is no recent archive',
            'backup_health.row.backup_last_run.consequence'  => 'Either the scheduled job has stopped, or every run is failing before it writes anything. The journal beside the backup directory names the reason.',
            'backup_health.row.backup_last_upload.problem'     => 'Nothing is leaving this server any more',
            'backup_health.row.backup_last_upload.consequence' => 'Local archives are still being written, but the off-site copy is ageing out. This is the failure that survives longest unnoticed: losing the webspace then loses both.',
            'backup_health.row.backup_local_size.problem'      => 'The archives are over the configured limit',
            'backup_health.row.backup_local_size.consequence'  => 'A full webspace stops accepting everything — mandate uploads, logs, the next backup. Retention is evidently not keeping up.',
            'backup_health.row.unknown.problem'      => 'A backup check is failing',
            'backup_health.row.unknown.consequence'  => 'Which one is shown in the admin panel under Settings → Security.',

            'credit_digest.subject'        => '{count} members near their Deckel limit',
            'credit_digest.subject_empty'  => 'Deckel overview: nobody near their limit',
            'credit_digest.preheader'      => '{count} members, {total} outstanding between them',
            'credit_digest.preheader_empty' => 'No tab is currently in the warning band',
            'credit_digest.eyebrow'        => 'Deckel overview',
            'credit_digest.title'          => 'Members near their Deckel limit',
            'credit_digest.lede'           => '<strong>{count}</strong> members have a tab that has reached the '
                . 'warning band. The terminal tells them so at their next purchase, and refuses one that would '
                . 'take them past the limit.',
            'credit_digest.lede_text'      => '{count} members have a tab that has reached the warning band. The '
                . 'terminal tells them so at their next purchase, and refuses one that would take them past the '
                . 'limit.',
            'credit_digest.empty_lede'     => 'no tab is in the warning band at the moment, so there is nothing to '
                . 'do. This message went out because something changed between it being queued and being sent.',
            'credit_digest.empty_lede_text' => 'no tab is in the warning band at the moment, so there is nothing to '
                . 'do. This message went out because something changed between it being queued and being sent.',
            'credit_digest.percent'        => '{percent}%',
            'credit_digest.of_limit'       => '{balance} of {limit}',
            'credit_digest.total_label'    => 'Total outstanding',
            'credit_digest.status.exceeded' => 'over the limit',
            'credit_digest.exceeded_heading' => 'Already over the limit',
            'credit_digest.exceeded_body'  => '{count} of them are past their limit. Those members cannot buy '
                . 'anything at the terminal until the tab is settled or the limit is raised.',
            'credit_digest.policy'         => 'The club limit is {limit}, with the warning starting at {percent}% '
                . 'of it. Individual members may have a limit of their own — the table always shows the one that '
                . 'applies to that member.',
            'credit_digest.next'           => 'Nothing needs changing here: this overview only collects what the '
                . 'dashboard already shows. How often it arrives is set under Settings → Mail.',
            'credit_digest.omitted'        => 'Another {count} members are in the warning band as well; this list shows only the fullest tabs. The dashboard has the complete list.',
            'credit_digest.unnamed_member' => '(no name)',
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

            'credential_expiry.subject.90' => '{name}: expires in {days} days',
            'credential_expiry.subject.30' => '{name}: expires in {days} days — worth scheduling',
            'credential_expiry.subject.7'  => 'Urgent: {name} expires in {days} days',
            'credential_expiry.preheader'  => 'Valid until {date} · after that, {consequence}',
            'credential_expiry.eyebrow'    => 'Credentials',
            'credential_expiry.title'      => 'A credential is running out',

            'credential_expiry.lede.key'  => 'the key that encrypts members’ bank details expires on '
                                           . '<strong>{date}</strong> — in <strong>{days} days</strong>.',
            'credential_expiry.lede_text.key' => 'the key that encrypts members’ bank details expires on {date} — '
                                           . 'in {days} days.',
            'credential_expiry.lede.terminal' => 'the credential for terminal <strong>{name}</strong> expires on '
                                           . '<strong>{date}</strong> — in <strong>{days} days</strong>.',
            'credential_expiry.lede_text.terminal' => 'the credential for terminal {name} expires on {date} — '
                                           . 'in {days} days.',
            'credential_expiry.lede.backup_secret' => 'the password the nightly backup uses to upload to '
                                           . '<strong>{name}</strong> expires on <strong>{date}</strong> — '
                                           . 'in <strong>{days} days</strong>.',
            'credential_expiry.lede_text.backup_secret' => 'the password the nightly backup uses to upload to '
                                           . '{name} expires on {date} — in {days} days.',

            'credential_expiry.label_credential' => 'Type',
            'credential_expiry.label_name'       => 'Name',
            'credential_expiry.label_expires'    => 'Valid until',
            'credential_expiry.label_days'       => 'Remaining',
            'credential_expiry.days'             => '{days} days',
            'credential_expiry.credential.key'      => 'Bank-details encryption key',
            'credential_expiry.credential.terminal' => 'Terminal credential',
            'credential_expiry.credential.backup_secret' => 'Backup upload password',
            'credential_expiry.subject_name.key'      => 'Bank-details encryption key',
            'credential_expiry.subject_name.terminal' => 'Terminal credential “{name}”',
            'credential_expiry.subject_name.backup_secret' => 'Backup upload password',

            'credential_expiry.consequence.key' => 'Once it expires, no bank details can be stored and no SEPA '
                                           . 'files can be produced. Data already stored is unaffected.',
            'credential_expiry.consequence.terminal' => 'Once it expires, the terminal can no longer sign in and '
                                           . 'nothing can be rung up on it. Transactions already recorded stay '
                                           . 'on the device.',
            // The sentence that matters: the backup keeps running and keeps
            // looking healthy. Only the half that gets it out of the building
            // stops — and Microsoft says nothing about that on its own.
            'credential_expiry.consequence.backup_secret' => 'Once it expires the backup is still written and '
                                           . 'still sealed, but no longer uploaded. It then exists only on the '
                                           . 'same webspace as the database, so one lost hosting account takes '
                                           . 'both. The nightly run reports the failure; nothing else will.',
            'credential_expiry.consequence_short.key'      => 'no more SEPA files',
            'credential_expiry.consequence_short.terminal' => 'nothing can be rung up on this terminal',
            'credential_expiry.consequence_short.backup_secret' => 'backups stop leaving the server',

            'credential_expiry.next.key' => 'Settings → Credentials is where you register and activate the '
                                           . 'successor, and that part needs no private key. Re-encrypting the '
                                           . 'bank details already stored does need the private half of the old '
                                           . 'key afterwards — the one the club keeps offline.',
            'credential_expiry.next.terminal' => 'Settings → Terminals, then “Rotate Token”. The current token '
                                           . 'keeps working until the new one is used at the terminal for the '
                                           . 'first time, so the bar stays online while you walk over.',
            'credential_expiry.next.backup_secret' => 'Mint a new one with '
                                           . 'scripts/setup-msgraph-backup.ps1 -RotateSecretOnly; the procedure '
                                           . 'is in docs/m365-backup-target.md. Put it in config.php together '
                                           . 'with the new expiry date, wait for one successful run, and only '
                                           . 'then delete the old one — both work until you do.',
            'credential_expiry.no_secret' => 'This message deliberately carries no key or token material — only '
                                           . 'what is expiring and by when.',

            'terminal_token_issued.subject.enrolled' => 'New terminal “{name}” set up',
            'terminal_token_issued.subject.rotated'  => 'New credential issued for terminal “{name}”',
            'terminal_token_issued.preheader' => '{name} · {moment} — if this was not us, revoke it.',
            'terminal_token_issued.eyebrow'   => 'Security notice',
            'terminal_token_issued.title'     => 'A terminal credential was issued',
            'terminal_token_issued.lede.enrolled' => 'on {moment} the terminal <strong>{name}</strong> was set up '
                                           . 'and given its own credential. It can now read the membership list '
                                           . 'and record sales.',
            'terminal_token_issued.lede_text.enrolled' => 'on {moment} the terminal {name} was set up and given its '
                                           . 'own credential. It can now read the membership list and record sales.',
            'terminal_token_issued.lede.rotated' => 'on {moment} a new credential was issued for terminal '
                                           . '<strong>{name}</strong>. The current one keeps working until the new '
                                           . 'one is used at the terminal for the first time.',
            'terminal_token_issued.lede_text.rotated' => 'on {moment} a new credential was issued for terminal '
                                           . '{name}. The current one keeps working until the new one is used at '
                                           . 'the terminal for the first time.',
            'terminal_token_issued.label_terminal' => 'Terminal',
            'terminal_token_issued.label_device'   => 'Device ID',
            'terminal_token_issued.label_event'    => 'Event',
            'terminal_token_issued.label_actor'    => 'Performed by',
            'terminal_token_issued.label_issued'   => 'Issued on',
            'terminal_token_issued.label_expires'  => 'Valid until',
            'terminal_token_issued.event.enrolled' => 'Terminal set up',
            'terminal_token_issued.event.rotated'  => 'Token rotated',
            'terminal_token_issued.action_heading' => 'Was this not the club?',
            'terminal_token_issued.action' => 'Then revoke it now: Settings → Terminals → “Revoke Access”. The '
                                           . 'terminal is signed out immediately and whoever holds the token can '
                                           . 'do nothing with it. Who issued it is recorded under '
                                           . 'Settings → Audit Log.',
            'terminal_token_issued.why_everyone' => 'This message goes to every active administrator, including '
                                           . 'whoever issued the credential. That is the point: somebody who has '
                                           . 'taken over one account cannot reach the other mailboxes.',
            'terminal_token_issued.no_secret' => 'This message deliberately carries no credentials — the token '
                                           . 'itself is shown once in the admin panel and stored nowhere.',

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

            // ── Admin invitation (migration 058) ────────────────────────────
            'admin_invitation.subject'   => 'Your admin account for {org}',
            'admin_invitation.preheader' => 'Set a password to activate your admin account.',
            'admin_invitation.eyebrow'   => 'Invitation',
            'admin_invitation.title'     => 'Welcome to the admin panel',
            'admin_invitation.lede'      => 'an admin account has been created for you at '
                                          . '<strong>{org}</strong>. Use the link below to set your own '
                                          . 'password — nobody else will know it.',
            'admin_invitation.lede_text' => 'an admin account has been created for you at {org}. '
                                          . 'Use the link below to set your own password — nobody else '
                                          . 'will know it.',
            'admin_invitation.label_login'   => 'Sign-in address',
            'admin_invitation.label_expires' => 'Link valid until',
            'admin_invitation.cta'       => 'Set your password',
            'admin_invitation.fallback'  => 'If the button does not work, open this address in your browser:',
            'admin_invitation.next_step' => 'After that, sign in once as normal. You will set up two-factor '
                                          . 'authentication with an authenticator app as part of signing in — '
                                          . 'it is a required step and takes a minute.',
            'admin_invitation.unexpected' => 'If this means nothing to you, ignore this email. The link '
                                           . 'expires on its own, and nothing happens without it.',

            // ── Admin lifecycle (ADR-0044) ──────────────────────────────────
            'admin_lifecycle.eyebrow'          => 'Security notice',
            'admin_lifecycle.created.subject'  => 'A new admin account was created',
            'admin_lifecycle.created.preheader' => 'A new account has access to the admin panel.',
            'admin_lifecycle.created.title'    => 'New admin account',
            'admin_lifecycle.created.lede'     => 'a new account was created in the admin panel: '
                                                . '<strong>{account}</strong>.',
            'admin_lifecycle.created.lede_text' => 'a new account was created in the admin panel: {account}.',
            'admin_lifecycle.roles.subject'    => 'The roles on an admin account were changed',
            'admin_lifecycle.roles.preheader'  => 'An account now has different rights in the admin panel.',
            'admin_lifecycle.roles.title'      => 'Roles changed',
            'admin_lifecycle.roles.lede'       => 'the roles on the admin account <strong>{account}</strong> '
                                                . 'were changed.',
            'admin_lifecycle.roles.lede_text'  => 'the roles on the admin account {account} were changed.',
            'admin_lifecycle.label_account'    => 'Account',
            'admin_lifecycle.label_roles'      => 'Roles now',
            'admin_lifecycle.label_when'       => 'When',
            'admin_lifecycle.label_actor'      => 'Performed by',
            'admin_lifecycle.roles_none'       => '(none)',
            'admin_lifecycle.expected'         => 'If this was intended, there is nothing further to do.',
            'admin_lifecycle.unexpected'       => 'If it was not: the account can be deactivated under '
                                                . 'Settings → Admin accounts, and the audit log shows who '
                                                . 'granted or revoked which role.',
            'admin_lifecycle.no_secret'        => 'This message deliberately carries no credentials. A newly '
                                                . 'issued password is shown once in the admin panel and '
                                                . 'stored nowhere.',
            // Role names stay the words the club uses, in both languages
            // (ADR-0044) — as Storno and Deckel do.
            'admin_lifecycle.role.admin'         => 'Admin',
            'admin_lifecycle.role.kassenwart'    => 'Kassenwart',
            'admin_lifecycle.role.getraenkewart' => 'Getränkewart',

            // ── Key lifecycle (ADR-0036) ────────────────────────────────────
            'key_event.subject.registered' => 'A new key for bank details was added',
            'key_event.subject.activated'  => 'Members’ bank details are now sealed under a different key',
            'key_event.subject.revoked'    => 'A key for bank details was withdrawn',
            'key_event.preheader'          => '{name} · fingerprint {short}',
            'key_event.eyebrow'            => 'Security notice',
            'key_event.title.registered'   => 'New key added',
            'key_event.title.activated'    => 'Key put in force',
            'key_event.title.revoked'      => 'Key withdrawn',

            'key_event.lede.registered' => 'a new key for encrypting members’ bank details was added. '
                                         . 'It is not encrypting anything yet — that starts when it is '
                                         . 'activated.',
            'key_event.lede.activated'  => 'from now on every stored bank account is sealed under this '
                                         . 'key. Only the private half from the club’s archive can read '
                                         . 'them back.',
            'key_event.lede.revoked'    => 'a key for encrypting members’ bank details was withdrawn.',

            'key_event.label_name'        => 'Name',
            'key_event.label_fingerprint' => 'Fingerprint (SHA-256)',
            'key_event.label_actor'       => 'Performed by',
            'key_event.label_when'        => 'When',

            'key_event.verify'     => 'Please compare the fingerprint with the one the offline '
                                    . 'generator showed when the keypair was created. If they match, '
                                    . 'this was the club’s own key.',
            'key_event.unexpected' => 'If nobody at the club did this, check which key is active under '
                                    . 'Settings → Security & Credentials straight away — and do not '
                                    . 'change any bank details before you have.',

            // ── Member lifecycle (ADR-0051) ─────────────────────────────────
            'member_welcome.subject'   => 'Welcome to {club} — your card is active',
            'member_welcome.preheader' => 'Your membership card has been activated.',
            'member_welcome.eyebrow'   => 'Welcome',
            'member_welcome.title'     => 'Your card is active',
            'member_welcome.lede'      => 'your membership card is now <strong>active</strong> — you can '
                                        . 'use it to pay at the bar.',
            'member_welcome.lede_text' => 'your membership card is now active — you can use it to pay at '
                                        . 'the bar.',
            'member_welcome.body'      => 'Hold the card against the reader, choose what you are having, '
                                        . 'and confirm. Nothing is paid on the spot: what you take runs '
                                        . 'up on your tab.',
            'member_welcome.not_yet'   => 'If the card has not reached you yet: it is already set up here '
                                        . 'and will work from the moment you hold it. The Kassenwart '
                                        . 'will hand it over.',
            'member_welcome.next'      => 'The tab is collected periodically by SEPA direct debit. Before '
                                        . 'every collection you receive an advance notice by email, at '
                                        . 'least seven days ahead, stating the amount and the due date. '
                                        . 'Nothing is ever collected without you having had that notice '
                                        . 'in writing first.',
            'member_welcome.unexpected' => 'We hold your name, date of birth, contact details and — for '
                                        . 'the collection — your bank details. If any of that is wrong, '
                                        . 'or you received this message unexpectedly, simply reply to it.',

            'member_card.subject'   => 'Your new card is active',
            'member_card.preheader' => 'A different card now identifies you.',
            'member_card.eyebrow'   => 'Card replaced',
            'member_card.title'     => 'Your new card is active',
            'member_card.lede'      => 'a <strong>different card</strong> now identifies you.',
            'member_card.lede_text' => 'a different card now identifies you.',
            'member_card.body'      => 'The previous card no longer works. Your tab and your existing '
                                     . 'bookings are unchanged — only the card you check in with at the '
                                     . 'bar has changed.',
            'member_card.not_yet'   => 'If the new card has not reached you yet: it is already set up here '
                                     . 'and will work as soon as you hold it. Until then you cannot pay '
                                     . 'at the bar — the old card stopped working immediately.',
            'member_card.next'      => 'You can throw the old card away.',
            'member_card.unexpected' => 'If you did not ask for a new card, please contact the Kassenwart '
                                     . '— simply reply to this message.',

            'member_mail_former.subject'       => 'Your email address has been changed',
            'member_mail_former.preheader'     => 'This address will no longer receive club mail.',
            'member_mail_former.eyebrow'       => 'Address changed',
            'member_mail_former.title'         => 'Your email address has been changed',
            'member_mail_former.lede'          => 'a <strong>different email address</strong> is now on '
                                                . 'file for you.',
            'member_mail_former.lede_text'     => 'a different email address is now on file for you.',
            'member_mail_former.label_address' => 'Previous address',
            'member_mail_former.label_when'    => 'Changed on',
            'member_mail_former.expected'      => 'No club mail will be sent to this address from now on '
                                                . '— no advance notice and no statement. This is the last '
                                                . 'message it will receive.',
            'member_mail_former.unexpected'    => 'If you did not ask for this change, please contact the '
                                                . 'Kassenwart. This message goes to your old address on '
                                                . 'purpose: it is the only channel through which a change '
                                                . 'you did not want can still reach you.',

            'member_mail_current.subject'       => 'This address is now your club address',
            'member_mail_current.preheader'     => 'Club mail now goes here.',
            'member_mail_current.eyebrow'       => 'Address active',
            'member_mail_current.title'         => 'This address is now your club address',
            'member_mail_current.lede'          => 'our mail now reaches you at <strong>this '
                                                 . 'address</strong>.',
            'member_mail_current.lede_text'     => 'our mail now reaches you at this address.',
            'member_mail_current.label_address' => 'New address',
            'member_mail_current.label_when'    => 'Changed on',
            'member_mail_current.expected'      => 'The advance notice before every collection — and the '
                                                 . 'statement, if the club sends one — will arrive here. '
                                                 . 'There is nothing to confirm; the address is already '
                                                 . 'in use.',
            'member_mail_current.unexpected'    => 'If you did not ask for this change, please contact '
                                                 . 'the Kassenwart — simply reply to this message.',

            'greeting'         => 'Hello {name},',
            'greeting_generic' => 'Hello,',
            'signoff'          => 'Kind regards',
            'questions'        => 'Questions about this message? Simply reply to it.',
            'text_separator'   => '----------------------------------------------------------',
            'automated_note'   => 'This message was generated automatically.',
        ],
    ];
}
