<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\DTOs\CancellationNoticeDataDto;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * „Einzug entfällt" (#404).
 *
 * Only ever sent to a member whose announcement actually went out — the
 * cancellation path enqueues nothing for a `pending` row, because telling
 * somebody a collection is called off when they were never told it was coming
 * is worse than saying nothing (ADR-0038).
 *
 * It carries **no mandate reference and no creditor identifier**. Those exist
 * to authorise a collection; repeating them under "this will not be collected"
 * would read as a second announcement. The amount and the date are here for one
 * purpose only: so the member can tell *which* announcement this retracts.
 *
 * The message it retracts is not deleted. The announcement row stays `sent`,
 * because the club did announce, and the audit trail says so.
 */
final class CancellationNoticeMail
{
    public static function render(CancellationNoticeDataDto $data): MailMessage
    {
        $t = new MailStrings($data->language);

        $amount = MailFormat::money($data->amountCents, $data->language);
        $dueDate = MailFormat::date($data->dueDate, $data->language);

        $subject = $t->t('cancel.subject');

        // Amount and date alone stop identifying the retracted announcement as
        // soon as a member is announced the same figure twice; the reference
        // always does. Both renderers walk this array, so the row lands in the
        // HTML and the text part together.
        $rows = [
            $t->t('cancel.label_amount') => $amount,
            $t->t('cancel.label_due_date') => $dueDate,
            $t->t('cancel.label_reference') => $data->settlementReference,
        ];

        $html = MailLayout::document($data->branding, [
            'title' => $subject,
            'preview' => $t->t('cancel.preheader', ['date' => $dueDate]),
            'lang' => $data->language->value,
            'content' => self::html($t, $data, $rows, $amount, $dueDate),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $data->recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $data, $rows, $amount, $dueDate),
            toName: $data->recipientName,
        );
    }

    /** @param array<string,string> $rows */
    private static function html(MailStrings $t, CancellationNoticeDataDto $data, array $rows, string $amount, string $dueDate): string
    {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('cancel.eyebrow'))
            . MailLayout::title($t->t('cancel.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $data->salutationName)))
            . MailLayout::lede($t->t('cancel.lede', [
                'amount' => MailLayout::esc($amount),
                'date' => MailLayout::esc($dueDate),
            ]))
            . MailLayout::dataTable($rows)
            . MailLayout::paragraph(MailLayout::esc($t->t('cancel.no_action')))
            . MailLayout::paragraph(MailLayout::esc($t->t('cancel.next')))
            . MailLayout::paragraph(MailLayout::esc($t->t('questions')))
            . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
            . MailLayout::contentEnd();
    }

    /** @param array<string,string> $rows */
    private static function text(MailStrings $t, CancellationNoticeDataDto $data, array $rows, string $amount, string $dueDate): string
    {
        $out = [
            $t->t('cancel.title'),
            $t->t('text_separator'),
            '',
            MailTextBody::greeting($t, $data->salutationName),
            '',
            $t->t('cancel.lede_text', ['amount' => $amount, 'date' => $dueDate]),
            '',
        ];

        foreach ($rows as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        $out[] = '';
        $out[] = $t->t('cancel.no_action');
        $out[] = '';
        $out[] = $t->t('cancel.next');
        $out[] = '';
        $out[] = $t->t('questions');
        $out[] = '';
        $out[] = $t->t('signoff');
        $out[] = $data->branding->orgName;

        return MailTextBody::finish($out, $data->branding, $t);
    }
}
