<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\DTOs\TerminalAnomalyDataDto;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * "A terminal may be in use on more than one device" (ADR-0041 §4).
 *
 * Addressed to an admin, never to a member: a member can do nothing about a
 * terminal credential, and telling them one is in doubt leaks the state of the
 * club's own security.
 *
 * Two things this deliberately does not do.
 *
 * It does not tell the admin to revoke. Every kind it reports has an innocent
 * explanation — a dual uplink, a restore from backup, a re-provisioning
 * somebody did that morning — and a mail that opens with "your till has been
 * stolen" will be wrong often enough to be ignored the one time it is right.
 * It reports what was observed and asks for a look.
 *
 * It carries no token material, no member data and no sales figures. The
 * evidence is addresses and cursor positions, which is exactly what somebody
 * deciding whether this is their own second device needs and nothing more.
 */
final class TerminalAnomalyMail
{
    public static function render(TerminalAnomalyDataDto $data): MailMessage
    {
        $t = new MailStrings($data->language);

        $subject = $t->t('terminal_anomaly.subject', ['terminal' => $data->terminalName]);

        // The evidence table is built by the detector, not here: what is worth
        // showing differs per kind, and a template that reconstructed it would
        // be a second place for the meaning of a `details` payload to live.
        $rows = $data->evidence;

        $html = MailLayout::document($data->branding, [
            'title' => $subject,
            'preview' => $t->t('terminal_anomaly.preheader', ['terminal' => $data->terminalName]),
            'lang' => $data->language->value,
            'content' => self::html($t, $data, $rows),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $data->recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $data, $rows),
            toName: $data->recipientName,
        );
    }

    /** @param array<string,string> $rows */
    private static function html(MailStrings $t, TerminalAnomalyDataDto $data, array $rows): string
    {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('terminal_anomaly.eyebrow'))
            . MailLayout::title($t->t('terminal_anomaly.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $data->recipientName)))
            . MailLayout::lede($t->t('terminal_anomaly.lede', [
                'terminal' => MailLayout::esc($data->terminalName),
            ]))
            . MailLayout::paragraph(MailLayout::esc($t->t('terminal_anomaly.kind.' . $data->kind)))
            . MailLayout::dataTable($rows)
            . MailLayout::paragraph(MailLayout::esc($t->t('terminal_anomaly.innocent.' . $data->kind)))
            . MailLayout::paragraph(MailLayout::esc($t->t('terminal_anomaly.next')))
            . MailLayout::paragraph(MailLayout::esc($t->t('terminal_anomaly.no_action_taken')))
            . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
            . MailLayout::contentEnd();
    }

    /** @param array<string,string> $rows */
    private static function text(MailStrings $t, TerminalAnomalyDataDto $data, array $rows): string
    {
        $lines = [
            MailTextBody::greeting($t, $data->recipientName),
            '',
            $t->t('terminal_anomaly.lede_text', ['terminal' => $data->terminalName]),
            '',
            $t->t('terminal_anomaly.kind.' . $data->kind),
            '',
        ];

        foreach ($rows as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        $lines[] = '';
        $lines[] = $t->t('terminal_anomaly.innocent.' . $data->kind);
        $lines[] = '';
        $lines[] = $t->t('terminal_anomaly.next');
        $lines[] = '';
        $lines[] = $t->t('terminal_anomaly.no_action_taken');

        return MailTextBody::finish($lines, $data->branding, $t);
    }
}
