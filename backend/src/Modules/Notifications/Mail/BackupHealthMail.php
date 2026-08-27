<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\DTOs\BackupHealthDataDto;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * "The backup job has stopped working" (#693, ADR-0049).
 *
 * The companion to {@see CredentialExpiryMail}'s backup arm, and the one that
 * covers what a date cannot: that warning says a credential *will* stop working,
 * this says something already has.
 *
 * Addressed to the Admin — whoever holds the server — and to nobody else. An
 * archive carries the audit log, every admin's TOTP ciphertext and the database
 * password; ADR-0049 draws that office boundary for custody of the key, and the
 * same boundary decides who hears that the archive is not being written.
 *
 * ## It names the consequence, not the measurement
 *
 * Each failing row renders as *what is wrong* and *what that means*, in the
 * reader's own language, from a fixed set of translation keys. No paths, no
 * sizes, no hours, no hostnames.
 *
 * That is not squeamishness about detail — it is where the detail belongs. The
 * measured numbers are on the security self-check page, computed by the one
 * class that decides what "stale" means; a mail restating them would either
 * duplicate those measurements or parse their English prose, and the first is
 * how a mail and a page come to disagree about whether a club has backups. So
 * this message's whole job is to get somebody to go and look, and it says where.
 *
 * ## A cleared problem is not a fault
 *
 * The row is rendered at send time (ADR-0038 rule 5), and by then the condition
 * may have gone: a nightly run that finally succeeded, a prune that freed the
 * cap. That is good news rather than an error, so the mail says so in a sentence
 * instead of refusing to render — the same call {@see CreditLimitDigestMail}
 * makes for an emptied list, and for the same reason. Refusing would put a red
 * row in the Notifications page for a backup that started working again.
 */
final class BackupHealthMail
{
    /**
     * Every self-check row this mail knows how to explain.
     *
     * Stated as a list rather than left implicit, so a row added to
     * {@see \App\Modules\Backups\Services\BackupStatusCheck} without a
     * translation is caught by a test rather than by a club receiving a mail
     * with a blank line in it. {@see self::line()} falls back to a generic
     * sentence for anything not here — a warning that says less is much better
     * than one that says nothing.
     */
    public const EXPLAINED_ROWS = [
        'backup_ever_ran',
        'backup_last_run',
        'backup_last_upload',
        'backup_local_size',
    ];

    public static function render(BackupHealthDataDto $data): MailMessage
    {
        $t = new MailStrings($data->language);

        $subject = $data->isEmpty()
            ? $t->t('backup_health.subject_cleared')
            : $t->t('backup_health.subject');

        $html = MailLayout::document($data->branding, [
            'title' => $subject,
            'preview' => $data->isEmpty()
                ? $t->t('backup_health.preheader_cleared')
                : $t->t('backup_health.preheader'),
            'lang' => $data->language->value,
            'content' => self::html($t, $data),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $data->recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $data),
            toName: $data->recipientName,
        );
    }

    private static function html(MailStrings $t, BackupHealthDataDto $data): string
    {
        $html = MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('backup_health.eyebrow'))
            . MailLayout::title($t->t('backup_health.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $data->recipientName)));

        if ($data->isEmpty()) {
            return $html
                . MailLayout::lede($t->t('backup_health.cleared_lede'))
                . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
                . MailLayout::contentEnd();
        }

        $html .= MailLayout::lede($t->t('backup_health.lede'));

        // One block per failing row: the problem as a heading, the consequence
        // underneath. A bare list of problem names would tell an admin their
        // backups are broken without telling them what that costs, and the
        // second half is what gets somebody to act this week.
        foreach ($data->failing as $row) {
            $html .= MailLayout::noteBox(
                $t->t(self::key($row, 'problem')),
                MailLayout::esc($t->t(self::key($row, 'consequence')))
            );
        }

        return $html
            . MailLayout::paragraph(MailLayout::esc($t->t('backup_health.where')))
            . MailLayout::paragraph(MailLayout::esc($t->t('backup_health.no_detail')))
            . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
            . MailLayout::contentEnd();
    }

    private static function text(MailStrings $t, BackupHealthDataDto $data): string
    {
        $lines = [MailTextBody::greeting($t, $data->recipientName), ''];

        if ($data->isEmpty()) {
            $lines[] = $t->t('backup_health.cleared_lede');

            return MailTextBody::finish($lines, $data->branding, $t);
        }

        $lines[] = $t->t('backup_health.lede');
        $lines[] = '';

        foreach ($data->failing as $row) {
            $lines[] = '- ' . $t->t(self::key($row, 'problem'));
            $lines[] = '  ' . $t->t(self::key($row, 'consequence'));
            $lines[] = '';
        }

        $lines[] = $t->t('backup_health.where');
        $lines[] = '';
        $lines[] = $t->t('backup_health.no_detail');

        return MailTextBody::finish($lines, $data->branding, $t);
    }

    /**
     * The translation key for one row, or the generic one for a row this
     * template has not been taught about.
     *
     * The fallback is deliberate and is the reason `EXPLAINED_ROWS` is asserted
     * in a test instead of being enforced here: a new self-check row should
     * produce a slightly vague warning, never a silent one. Losing the mail
     * would lose the only alarm a club without an external monitor has.
     */
    private static function key(string $row, string $part): string
    {
        return in_array($row, self::EXPLAINED_ROWS, true)
            ? 'backup_health.row.' . $row . '.' . $part
            : 'backup_health.row.unknown.' . $part;
    }
}
