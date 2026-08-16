<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Dashboard\Domain\CreditLimit;
use App\Modules\Notifications\DTOs\DeckelStatementDataDto;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * The periodic Deckelauszug (ADR-0039, #463).
 *
 * A statement, not a demand. Everything in it exists to answer one question a
 * member cannot otherwise answer between settlements — *where does my tab
 * stand, and am I close to the point where the bar stops serving me?* —
 * because `CreditLimit::LIMIT_CENTS` is enforced at the terminal, offline, in
 * front of whoever is queueing behind them.
 *
 * ### What it may never contain
 *
 * Mandate reference, Gläubiger-ID, IBAN, execution date, due date, the word
 * Mahnung. Not an omission but a rule: those belong to the Vorabankündigung,
 * and a statement that borrows them reads as a collection notice — the exact
 * confusion `CONTEXT.md` defines the two terms apart to prevent. There is no
 * field on {@see DeckelStatementDataDto} that could carry any of them, so the
 * rule is structural; `DeckelStatementMailTest` asserts it anyway, in both
 * languages, because the failure would be invisible in review.
 *
 * ### „Stand"
 *
 * The heading and the subject name the **period boundary**, never the send day.
 * The number will not match what the terminal shows today — a member who bought
 * a beer yesterday sees it on next month's statement — and a statement that
 * does not say which moment it describes generates precisely the confused
 * questions it exists to prevent.
 *
 * ### The total and the cap
 *
 * The total is computed over the full netted set by
 * {@see \App\Modules\Notifications\Services\DeckelStatementService}, never over
 * the lines printed here. Whenever the cap dropped anything, the mail says so
 * in as many words: a column that does not add up, unexplained, is worse than
 * no itemisation at all.
 */
final class DeckelStatementMail
{
    public static function render(DeckelStatementDataDto $data): MailMessage
    {
        $t = new MailStrings($data->language);
        $language = $data->language;

        $asOf = MailFormat::date($data->boundaryDate, $language);
        // Credit is stored as negative cents; a member reads „Guthaben 12,50 €"
        // rather than a minus sign in front of a euro amount.
        $magnitude = MailFormat::money(abs($data->totalCents), $language);

        $subject = $t->t('statement.subject', ['date' => $asOf]);

        $html = MailLayout::document($data->branding, [
            'title' => $subject,
            'preview' => $t->t('statement.preheader', ['date' => $asOf]),
            'lang' => $language->value,
            'content' => self::html($t, $data, $asOf, $magnitude),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $data->recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $data, $asOf, $magnitude),
            toName: $data->recipientName,
        );
    }

    /**
     * The headline block: what the tab stood at, and when.
     *
     * Two rows rather than one, and the label changes with the sign — „Offener
     * Betrag" and „Guthaben" are different facts about a member, and a single
     * label with a minus sign in front of the number leaves them to work out
     * which one applies to them.
     *
     * @return array<string,string>
     */
    private static function summaryRows(MailStrings $t, DeckelStatementDataDto $data, string $asOf, string $magnitude): array
    {
        $label = $data->totalCents < 0
            ? $t->t('statement.credit_label')
            : $t->t('statement.balance_label');

        return [
            $t->t('statement.as_of_label') => $asOf,
            $label => $magnitude,
        ];
    }

    /** @return list<array{label: string, quantity: string, amount: string}> */
    private static function itemLines(DeckelStatementDataDto $data, MailLanguage $language): array
    {
        $lines = [];
        foreach ($data->lines as $line) {
            $lines[] = [
                'label' => $line->label,
                // The layout's middle column. A statement has no quantity to
                // put there, and the booking date is what makes a line
                // recognisable — ADR-0039 keeps the *when* for that reason.
                'quantity' => MailFormat::date($line->occurredAt, $language),
                'amount' => MailFormat::money($line->amountCents, $language),
            ];
        }

        return $lines;
    }

    private static function html(MailStrings $t, DeckelStatementDataDto $data, string $asOf, string $magnitude): string
    {
        $language = $data->language;
        $lines = self::itemLines($data, $language);

        $content = MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('statement.eyebrow'))
            . MailLayout::title($t->t('statement.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $data->salutationName)))
            . MailLayout::lede($t->t('statement.lede', ['date' => MailLayout::esc($asOf)]))
            . MailLayout::dataTable(self::summaryRows($t, $data, $asOf, $magnitude))
            . MailLayout::subheading($t->t('statement.lines_heading'));

        if ($lines === []) {
            // A sentence, not an empty table (ADR-0039 decision 4).
            $content .= MailLayout::paragraph(MailLayout::esc($t->t('statement.empty')));
        } else {
            $content .= MailLayout::paragraph(MailLayout::esc($t->t('statement.lines_intro')))
                . MailLayout::itemTable($lines, $t->t('statement.total_label'), self::signedTotal($data));

            if ($data->omittedLines > 0) {
                $content .= MailLayout::paragraph($t->t('statement.capped', [
                    'count' => (string) $data->omittedLines,
                ]));
            }
        }

        $standing = self::standingNote($t, $data, $magnitude);
        if ($standing !== null) {
            $content .= MailLayout::noteBox($standing['heading'], MailLayout::esc($standing['text']));
        }

        return $content
            . MailLayout::paragraph(MailLayout::esc(self::questions($t, $data)))
            . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
            . MailLayout::contentEnd();
    }

    private static function text(MailStrings $t, DeckelStatementDataDto $data, string $asOf, string $magnitude): string
    {
        $language = $data->language;
        $lines = self::itemLines($data, $language);

        $out = [
            $t->t('statement.title'),
            $t->t('text_separator'),
            '',
            MailTextBody::greeting($t, $data->salutationName),
            '',
            $t->t('statement.lede_text', ['date' => $asOf]),
            '',
        ];

        foreach (self::summaryRows($t, $data, $asOf, $magnitude) as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        $out[] = '';
        $out[] = mb_strtoupper($t->t('statement.lines_heading'));

        if ($lines === []) {
            $out[] = $t->t('statement.empty');
        } else {
            $out[] = $t->t('statement.lines_intro');
            foreach ($lines as $line) {
                $out[] = sprintf(
                    '  %-12s %-34s %s',
                    $line['quantity'],
                    self::truncate($line['label'], 34),
                    $line['amount'],
                );
            }
            $out[] = sprintf('  %-47s %s', $t->t('statement.total_label'), self::signedTotal($data));

            if ($data->omittedLines > 0) {
                $out[] = '';
                $out[] = $t->t('statement.capped_text', ['count' => (string) $data->omittedLines]);
            }
        }

        $standing = self::standingNote($t, $data, $magnitude);
        if ($standing !== null) {
            $out[] = '';
            $out[] = mb_strtoupper($standing['heading']);
            $out[] = $standing['text'];
        }

        $out[] = '';
        $out[] = self::questions($t, $data);
        $out[] = '';
        $out[] = $t->t('signoff');
        $out[] = $data->branding->orgName;

        return MailTextBody::finish($out, $data->branding, $t);
    }

    /**
     * The total under the item table, sign included.
     *
     * Unlike the summary block this one keeps the minus: it is the foot of a
     * column of signed amounts, and stripping the sign there would make the
     * arithmetic above it appear wrong.
     */
    private static function signedTotal(DeckelStatementDataDto $data): string
    {
        return MailFormat::money($data->totalCents, $data->language);
    }

    /**
     * Where the member stands — the concrete job this mail does.
     *
     * Three bands, and the one that matters is the middle: a member who is
     * warned before they are refused at the bar can settle up first. Past the
     * limit the sentence is blunt about what the terminal will do, because by
     * then the member will otherwise find out in a queue.
     *
     * A member in credit gets the credit sentence instead. Their limit is not
     * a fact about their situation, and offering one would suggest a ceiling on
     * a balance that is on the club's side of the ledger. The sentence is also
     * careful not to promise a Payout: that is a separate act somebody has to
     * ask for (`CONTEXT.md`).
     *
     * The heading travels with the sentence rather than being fixed, because a
     * box headed „Dein Limit" whose only content is „Es steht nichts offen" is
     * a small piece of nonsense that a member reads before they read anything
     * else.
     *
     * @return array{heading: string, text: string}|null
     */
    private static function standingNote(MailStrings $t, DeckelStatementDataDto $data, string $magnitude): ?array
    {
        if ($data->totalCents < 0) {
            return [
                'heading' => $t->t('statement.credit_label'),
                'text' => $t->t('statement.credit_note', ['amount' => $magnitude]),
            ];
        }

        if ($data->totalCents === 0) {
            return [
                'heading' => $t->t('statement.balance_label'),
                'text' => $t->t('statement.zero_note'),
            ];
        }

        if ($data->creditLimitCents <= 0) {
            // Enforcement is off on this installation; there is no line to name.
            return null;
        }

        $limit = MailFormat::money($data->creditLimitCents, $data->language);

        return [
            'heading' => $t->t('statement.limit_heading'),
            'text' => match ($data->creditStatus) {
                CreditLimit::STATUS_EXCEEDED => $t->t('statement.limit_exceeded', ['limit' => $limit]),
                CreditLimit::STATUS_APPROACHING => $t->t('statement.limit_approaching', ['limit' => $limit]),
                default => $t->t('statement.limit_ok', ['limit' => $limit]),
            },
        ];
    }

    private static function questions(MailStrings $t, DeckelStatementDataDto $data): string
    {
        $email = trim((string) $data->treasurerEmail);
        $contact = $email === '' ? '' : $t->t('statement.questions_contact', ['email' => $email]);

        return $t->t('statement.questions_text', ['contact' => $contact]);
    }

    private static function truncate(string $text, int $width): string
    {
        return mb_strlen($text) <= $width ? $text : mb_substr($text, 0, $width - 1) . '…';
    }
}
