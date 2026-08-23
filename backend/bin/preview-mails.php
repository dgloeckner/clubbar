#!/usr/bin/env php
<?php

/**
 * Render every settlement mail variant to disk, so somebody can look at them.
 *
 * Usage:
 *   php bin/preview-mails.php                    # writes to backend/storage/mail-preview/
 *   php bin/preview-mails.php /tmp/out           # writes somewhere else
 *
 * Produces, for each variant and each of the two languages, an `.html` file and
 * a `.txt` file, plus an index. The Deckelauszug contributes four variants per
 * language rather than one, because its interesting cases are the ones a single
 * happy-path render never shows: a member past the credit limit, a member in
 * credit, an empty tab, and a capped list whose total is larger than the lines
 * it prints.
 *
 * No database, no configuration, no container: it renders from fixtures held in
 * this file. That is what makes it runnable on a fresh clone, and it is also
 * what keeps it honest — a preview that needed a seeded settlement would drift
 * out of use, and the design decision it exists to check (does this look like a
 * letterhead or like a newsletter?) does not depend on real data.
 *
 * After ADR-0038 there is no other way to look at these: the outbox stores no
 * body, so there is nothing to open in a database. Mailpit in the dev stack
 * (#409) shows what a *sent* one looks like; this shows what an unsent one
 * would look like, which is the loop you want while writing the wording.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Modules\CreditLimits\Domain\CreditLimitPolicy;
use App\Modules\Notifications\DTOs\CancellationNoticeDataDto;
use App\Modules\Notifications\DTOs\DeckelStatementDataDto;
use App\Modules\Notifications\DTOs\PreNotificationDataDto;
use App\Modules\Notifications\DTOs\StatementLineDto;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\CancellationNoticeMail;
use App\Modules\Notifications\Mail\DeckelStatementMail;
use App\Modules\Notifications\Mail\PreNotificationMail;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

$outDir = $argv[1] ?? __DIR__ . '/../storage/mail-preview';

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

/**
 * A club that is fully configured, so every optional field shows up. The point
 * of a preview is to see the maximum, not the minimum — a missing creditor
 * address is already covered by a unit test.
 */
$branding = new MailBranding(
    orgName: 'Beispiel-Ruderverein e.V.',
    addressLine: 'Musterweg 35, 60599 Frankfurt am Main',
    baseUrl: null,
    logoSrc: null,
    headerStyle: MailLayout::HEADER_PAPER,
    footerLinks: ['beispiel-ruderverein.de' => 'https://beispiel-ruderverein.de'],
);

$lines = [
    new StatementLineDto('Bier 0,5 l', '2026-07-02 20:14:00', 250),
    new StatementLineDto('Apfelschorle', '2026-07-02 20:31:00', 180),
    new StatementLineDto('Kaffee', '2026-07-09 18:02:00', 120),
    new StatementLineDto('Storno Bier 0,5 l', '2026-07-09 18:04:00', -250),
    new StatementLineDto('Bier 0,5 l', '2026-07-16 21:47:00', 250),
];
$total = array_sum(array_map(static fn (StatementLineDto $l): int => $l->amountCents, $lines));

$written = [];

foreach (MailLanguage::cases() as $language) {
    $written[] = write($outDir, "pre-notification-{$language->value}", PreNotificationMail::render(
        new PreNotificationDataDto(
            language: $language,
            recipientAddress: 'mitglied@example.org',
            recipientName: 'Erika Mustermann',
            salutationName: 'Erika',
            branding: $branding,
            amountCents: $total,
            dueDate: '2026-08-21',
            creditorName: 'Beispiel-Ruderverein e.V.',
            creditorAddress: 'Musterweg 35, 60599 Frankfurt am Main',
            creditorId: 'DE43ZZZ00000548506',
            mandateReference: 'F3332CA866B249E7A202BFBF4836B605',
            accountLast4: '3000',
            lines: $lines,
            periodStart: '2026-07-01',
            periodEnd: '2026-07-31',
            treasurerEmail: 'kasse@beispiel-ruderverein.de',
        )
    ));

    $written[] = write($outDir, "cancellation-notice-{$language->value}", CancellationNoticeMail::render(
        new CancellationNoticeDataDto(
            language: $language,
            recipientAddress: 'mitglied@example.org',
            recipientName: 'Erika Mustermann',
            salutationName: 'Erika',
            branding: $branding,
            amountCents: $total,
            dueDate: '2026-08-21',
            treasurerEmail: 'kasse@beispiel-ruderverein.de',
        )
    ));

    // The Deckelauszug (ADR-0039). Four variants, because the cases worth
    // looking at are the ones a happy-path render hides: does „Du bist über
    // Deinem Limit" read as a warning or as a demand? Does an empty tab still
    // look like a statement? Does a capped list explain itself?
    foreach (statementVariants($branding) as $variant => $statement) {
        $written[] = write(
            $outDir,
            "deckel-statement-{$variant}-{$language->value}",
            DeckelStatementMail::render(new DeckelStatementDataDto(...['language' => $language] + $statement))
        );
    }
}

file_put_contents($outDir . '/index.html', index($written));

echo "Wrote " . (count($written) * 2 + 1) . " files to " . realpath($outDir) . "\n";
echo "Open index.html to browse them.\n";

/**
 * The four Deckelauszug variants, as named argument sets.
 *
 * `approaching` is the one to look at hardest. It is the only mail in this
 * system whose job is to change what somebody does next — settle up before the
 * terminal refuses them in front of the queue — and it has to do that without
 * sounding like a demand for money, which is a different message with its own
 * rules (`CONTEXT.md`: Vorabankündigung vs. Deckelauszug).
 *
 * @return array<string, array<string, mixed>>
 */
function statementVariants(MailBranding $branding): array
{
    $common = [
        'recipientAddress' => 'mitglied@example.org',
        'recipientName' => 'Erika Mustermann',
        'salutationName' => 'Erika',
        'branding' => $branding,
        'boundaryDate' => '2026-08-01',
        'creditLimitCents' => CreditLimitPolicy::shipped()->clubDefault()->limitCents,
        'treasurerEmail' => 'kasse@beispiel-ruderverein.de',
    ];

    $lines = [
        new StatementLineDto('Bier 0,5 l', '2026-07-02 20:14:00', 250),
        new StatementLineDto('Apfelschorle', '2026-07-02 20:31:00', 180),
        new StatementLineDto('Kaffee', '2026-07-09 18:02:00', 120),
        new StatementLineDto('Storno Bier 0,5 l (aus Abrechnung 06/2026)', '2026-07-09 18:04:00', -250),
        new StatementLineDto('Bier 0,5 l', '2026-07-16 21:47:00', 250),
    ];

    $many = [];
    for ($i = 0; $i < DeckelStatementDataDto::MAX_LINES; $i++) {
        $many[] = new StatementLineDto('Bier 0,5 l', sprintf('2026-07-%02d 20:%02d:00', ($i % 28) + 1, $i % 60), 250);
    }

    // Everything but `capped` states the sum of what it prints. That is not
    // laziness: a preview whose column does not add up is unreadable as a
    // preview — you spend the look wondering whether the renderer is broken
    // instead of judging the wording.
    $sum = static fn (array $set): int => array_sum(array_map(
        static fn (StatementLineDto $line): int => $line->amountCents,
        $set,
    ));

    $variants = [
        'ok' => $lines,
        // Into the warning band, by way of one large evening rather than by an
        // adjusted total.
        'approaching' => [...$lines, new StatementLineDto('Sommerfest — Runde', '2026-07-25 22:10:00', 8_100)],
        'credit' => [new StatementLineDto('Storno Bier 0,5 l (aus Abrechnung 06/2026)', '2026-07-09 18:04:00', -750)],
        'empty' => [],
    ];

    $out = [];
    foreach ($variants as $name => $set) {
        $total = $sum($set);
        $out[$name] = $common + [
            'totalCents' => $total,
            'lines' => $set,
            'creditStatus' => CreditLimitPolicy::shipped()->clubDefault()->status($total)->value,
        ];
    }

    // The exception, and the reason it exists: 100 printed lines, 143 counted.
    // This is the variant that shows whether the cap explains itself.
    $cappedTotal = $sum($many) + 43 * 250;
    $out['capped'] = $common + [
        'totalCents' => $cappedTotal,
        'lines' => $many,
        'omittedLines' => 43,
        'creditStatus' => CreditLimitPolicy::shipped()->clubDefault()->status($cappedTotal)->value,
    ];

    return $out;
}

/**
 * @return array{name: string, subject: string}
 */
function write(string $dir, string $name, MailMessage $message): array
{
    file_put_contents($dir . '/' . $name . '.html', $message->html);
    // The subject is not in the HTML body, and it is half of what a reader
    // judges — so the text file leads with it.
    file_put_contents($dir . '/' . $name . '.txt', 'Subject: ' . $message->subject . "\n\n" . $message->text);

    return ['name' => $name, 'subject' => $message->subject];
}

/** @param list<array{name: string, subject: string}> $written */
function index(array $written): string
{
    $rows = '';
    foreach ($written as $entry) {
        $rows .= sprintf(
            '<li><a href="%1$s.html">%1$s</a> &middot; <a href="%1$s.txt">text</a><br><small>%2$s</small></li>',
            htmlspecialchars($entry['name'], ENT_QUOTES),
            htmlspecialchars($entry['subject'], ENT_QUOTES),
        );
    }

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Settlement mail preview</title>'
        . '<style>body{font:16px/1.6 system-ui,sans-serif;margin:40px;max-width:44rem}'
        . 'li{margin-bottom:14px}small{color:#6B6B6B}</style></head><body>'
        . '<h1>Settlement mail preview</h1>'
        . '<p>Rendered by <code>backend/bin/preview-mails.php</code> from fixtures, not from the database.</p>'
        . '<ul>' . $rows . '</ul></body></html>';
}
