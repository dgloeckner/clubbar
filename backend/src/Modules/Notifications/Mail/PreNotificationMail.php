<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\DTOs\PreNotificationDataDto;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * The SEPA pre-notification (#361, #404).
 *
 * Six things have to be in it, and every one is a commitment somebody already
 * made rather than a design choice available here:
 *
 * | Field | Where the obligation comes from |
 * |---|---|
 * | Creditor identifier | EPC SDD Core Rulebook — it must reach the debtor before the first collection |
 * | Mandate reference | Same, and the registration form promises it arrives *with this mail* |
 * | Amount and due date | Nutzungsordnung § 7 Abs. 3, at least seven days ahead |
 * | Itemised statement | Nutzungsordnung § 7 Abs. 1 — the Abrechnungsübersicht |
 * | Six-week objection hint | Nutzungsordnung § 4 Abs. 6 |
 * | Funding reminder | Satzung § 7 Abs. 3 |
 *
 * A field that is not configured is left out rather than rendered empty —
 * `MailLayout::dataTable()` drops blank rows — so a club that has not filled in
 * its creditor address still sends a usable announcement. The two fields with
 * no such escape are the amount and the due date, and they are constructor
 * arguments, not nullable ones.
 *
 * Renders both parts. The text part states the same facts as the HTML part,
 * because a text alternative that says "see the HTML version" is exactly the
 * failure `MailMessage` refuses to construct around.
 */
final class PreNotificationMail
{
    public static function render(PreNotificationDataDto $data): MailMessage
    {
        $t = new MailStrings($data->language);
        $language = $data->language;

        $amount = MailFormat::money($data->amountCents, $language);
        $dueDate = MailFormat::date($data->dueDate, $language);

        $subject = $t->t('pre.subject', ['amount' => $amount, 'date' => $dueDate]);
        $preheader = $t->t('pre.preheader', [
            'date' => $dueDate,
            'mandate' => (string) $data->mandateReference,
        ]);

        $rows = self::dataRows($t, $data, $amount, $dueDate);
        $lines = self::statementLines($data, $language);

        $html = MailLayout::document($data->branding, [
            'title' => $subject,
            'preview' => $preheader,
            'lang' => $language->value,
            'content' => self::html($t, $data, $rows, $lines, $amount),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $data->recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $data, $rows, $lines, $amount),
            toName: $data->recipientName,
        );
    }

    /**
     * The two-column block. Order is the order a member checks it in: who is
     * collecting, under which authority, how much, when — and finally, what to
     * quote if they want to ask about it.
     *
     * The reference comes last because it is the only row that is not about the
     * collection itself. It is the same string the member will find in the
     * Verwendungszweck on their bank statement, which is what makes a question
     * answerable: before it existed, a member could describe a debit only by
     * its amount and date, and so could the Kassenwart looking for it.
     *
     * Both the HTML and the plain-text renderer walk this array, so a row added
     * here appears in both parts — which is what {@see \App\Shared\Mail\MailMessage}
     * requires of every message.
     *
     * @return array<string,string> Blank values are dropped by the layout.
     */
    private static function dataRows(MailStrings $t, PreNotificationDataDto $data, string $amount, string $dueDate): array
    {
        $creditor = trim((string) $data->creditorName);
        if ($creditor !== '' && trim((string) $data->creditorAddress) !== '') {
            // Two lines in one cell; the layout turns the newline into a <br>.
            $creditor .= "\n" . trim((string) $data->creditorAddress);
        }

        return [
            $t->t('pre.label_creditor') => $creditor,
            $t->t('pre.label_creditor_id') => (string) $data->creditorId,
            $t->t('pre.label_mandate') => (string) $data->mandateReference,
            $t->t('pre.label_amount') => $amount,
            $t->t('pre.label_due_date') => $dueDate,
            $t->t('pre.label_account') => MailFormat::maskedAccount($data->accountLast4),
            $t->t('pre.label_reference') => $data->settlementReference,
        ];
    }

    /**
     * @return list<array{label: string, quantity: string, amount: string}>
     *         `quantity` is the layout's middle column; a statement has no
     *         quantity to put there, and the booking date is what a member
     *         actually needs to recognise the line.
     */
    private static function statementLines(PreNotificationDataDto $data, MailLanguage $language): array
    {
        $lines = [];
        foreach ($data->lines as $line) {
            $lines[] = [
                'label' => $line->label,
                'quantity' => MailFormat::date($line->occurredAt, $language),
                'amount' => MailFormat::money($line->amountCents, $language),
            ];
        }

        return $lines;
    }

    /** @param array<string,string> $rows */
    private static function html(MailStrings $t, PreNotificationDataDto $data, array $rows, array $lines, string $amount): string
    {
        $language = $data->language;

        $content = MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('pre.eyebrow'))
            . MailLayout::title($t->t('pre.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $data->salutationName)))
            . MailLayout::lede($t->t('pre.lede', [
                'amount' => MailLayout::esc($amount),
                'date' => MailLayout::esc(MailFormat::date($data->dueDate, $language)),
            ]))
            . MailLayout::subheading($t->t('pre.data_heading'))
            . MailLayout::dataTable($rows)
            . MailLayout::subheading($t->t('pre.statement_heading'));

        $period = self::period($t, $data);
        $content .= MailLayout::paragraph(MailLayout::esc(
            $lines === [] ? $t->t('pre.statement_empty') : $t->t('pre.statement_intro')
        ) . ($period === '' ? '' : '<br>' . MailLayout::esc($period)));

        if ($lines !== []) {
            $content .= MailLayout::itemTable($lines, $t->t('pre.total_label'), $amount);
        }

        return $content
            . MailLayout::noteBox($t->t('pre.objection_heading'), MailLayout::esc(self::objection($t, $data)))
            . MailLayout::paragraph(MailLayout::esc($t->t('pre.funding_note')))
            . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
            . MailLayout::contentEnd();
    }

    /** @param array<string,string> $rows */
    private static function text(MailStrings $t, PreNotificationDataDto $data, array $rows, array $lines, string $amount): string
    {
        $language = $data->language;
        $rule = $t->t('text_separator');

        $out = [
            $t->t('pre.title'),
            $rule,
            '',
            MailTextBody::greeting($t, $data->salutationName),
            '',
            $t->t('pre.lede_text', [
                'amount' => $amount,
                'date' => MailFormat::date($data->dueDate, $language),
            ]),
            '',
            mb_strtoupper($t->t('pre.data_heading')),
        ];

        foreach ($rows as $label => $value) {
            if (trim($value) === '') {
                continue;
            }
            // A multi-line cell (creditor plus address) becomes one indented
            // block rather than a second unlabelled line.
            $out[] = $label . ': ' . str_replace("\n", "\n  ", $value);
        }

        $out[] = '';
        $out[] = mb_strtoupper($t->t('pre.statement_heading'));

        $period = self::period($t, $data);
        if ($period !== '') {
            $out[] = $period;
        }

        if ($lines === []) {
            $out[] = $t->t('pre.statement_empty');
        } else {
            $out[] = $t->t('pre.statement_intro');
            foreach ($lines as $line) {
                $out[] = sprintf(
                    '  %-12s %-34s %s',
                    $line['quantity'],
                    self::truncate($line['label'], 34),
                    $line['amount'],
                );
            }
            $out[] = sprintf('  %-47s %s', $t->t('pre.total_label'), $amount);
        }

        $out[] = '';
        $out[] = mb_strtoupper($t->t('pre.objection_heading'));
        $out[] = self::objection($t, $data);
        $out[] = '';
        $out[] = $t->t('pre.funding_note');
        $out[] = '';
        $out[] = $t->t('signoff');
        $out[] = $data->branding->orgName;

        return MailTextBody::finish($out, $data->branding, $t);
    }

    private static function objection(MailStrings $t, PreNotificationDataDto $data): string
    {
        $email = trim((string) $data->treasurerEmail);
        $contact = $email === '' ? '' : $t->t('pre.objection_contact', ['email' => $email]);

        return $t->t('pre.objection_text', ['contact' => $contact]);
    }

    private static function period(MailStrings $t, PreNotificationDataDto $data): string
    {
        if ($data->periodStart === null || $data->periodEnd === null) {
            return '';
        }

        return $t->t('pre.statement_period', [
            'from' => MailFormat::date($data->periodStart, $data->language),
            'to' => MailFormat::date($data->periodEnd, $data->language),
        ]);
    }

    private static function truncate(string $text, int $width): string
    {
        return mb_strlen($text) <= $width ? $text : mb_substr($text, 0, $width - 1) . '…';
    }
}
