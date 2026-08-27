<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Mail;

use App\Modules\Backups\Domain\BackupRetention;
use App\Modules\Backups\Services\BackupStatusCheck;
use App\Modules\Notifications\DTOs\BackupHealthDataDto;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\BackupHealthMail;
use App\Modules\Notifications\Mail\MailStrings;
use App\Shared\Mail\MailBranding;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * What the backup warning says, and what it refuses to say (#693, ADR-0049).
 *
 * Part of #693, epic #686.
 */
class BackupHealthMailTest extends TestCase
{
    use TempTree;

    /**
     * **The guard that keeps a new self-check row from arriving as a blank
     * line.** {@see BackupStatusCheck} can grow a row without this template
     * knowing about it; the fallback in `BackupHealthMail::key()` means such a
     * row still produces a warning, but a vague one.
     *
     * So the coupling is asserted from the other direction: every row that
     * class can emit must be explained here, in **both** languages. This fails
     * on the commit that adds a row, which is where it is cheap to fix.
     */
    public function test_every_row_the_check_can_emit_is_explained_in_both_languages(): void
    {
        $dir = self::makeTempTree('backup-mail-rows');

        try {
            // Every row id the check can produce, gathered from the states that
            // produce them: an empty directory yields the never-ran row, an
            // archive yields the other three.
            $ids = $this->rowIds($dir);
            file_put_contents($dir . '/clubbar-20260825-030000-1a2b3c4d.cbb', 'sealed');
            $ids = array_values(array_unique([...$ids, ...$this->rowIds($dir)]));

            $this->assertNotSame([], $ids, 'the fixture must produce rows to check');

            foreach ($ids as $id) {
                $this->assertContains(
                    $id,
                    BackupHealthMail::EXPLAINED_ROWS,
                    sprintf(
                        'BackupStatusCheck emits "%s" and the mail has no wording for it. Add '
                        . 'backup_health.row.%s.problem and .consequence in both languages.',
                        $id,
                        $id,
                    )
                );

                foreach ([MailLanguage::German, MailLanguage::English] as $language) {
                    $t = new MailStrings($language);

                    foreach (['problem', 'consequence'] as $part) {
                        $key = 'backup_health.row.' . $id . '.' . $part;
                        $text = $t->t($key);

                        $this->assertNotSame($key, $text, $key . ' is missing in ' . $language->value);
                        $this->assertNotSame('', trim($text));
                    }
                }
            }
        } finally {
            self::removeTempTree($dir);
        }
    }

    /**
     * The mail's job is to get somebody to go and look, so it has to name the
     * consequence rather than only the fault. "There is no recent archive" is a
     * fact nobody schedules around; "there is nothing to fall back on" is.
     */
    public function test_it_names_the_problem_and_what_it_costs(): void
    {
        $message = BackupHealthMail::render($this->data(['backup_ever_ran']));

        $this->assertStringContainsString('backup is not working', $message->subject);
        $this->assertStringContainsString('never run', $message->text);
        $this->assertStringContainsString('hosting panel', $message->text);
        $this->assertStringContainsString('Settings → Security', $message->text);
    }

    /** Each admin in their own language, like every other message this system sends. */
    public function test_it_renders_in_german_too(): void
    {
        $message = BackupHealthMail::render($this->data(['backup_ever_ran'], MailLanguage::German));

        $this->assertStringContainsString('Datensicherung', $message->subject);
        $this->assertStringContainsString('Cronjob', $message->text);
    }

    /**
     * **No paths, no sizes, no numbers.** The measured detail is on the security
     * self-check page, computed by the one class that decides what "stale"
     * means; restating it here would mean duplicating those measurements or
     * parsing their English prose, and the first is how a mail and a page come
     * to disagree about whether a club has backups.
     */
    public function test_it_carries_no_measurement_and_no_path(): void
    {
        $message = BackupHealthMail::render($this->data([
            'backup_ever_ran',
            'backup_last_run',
            'backup_last_upload',
            'backup_local_size',
        ]));

        $body = $message->text . ' ' . $message->html;

        $this->assertStringNotContainsString('/srv', $body);
        $this->assertStringNotContainsString('.cbb', $body);
        $this->assertStringNotContainsString('msgraph://', $body);
        // No byte counts or hour counts leaking in from a finding's `observed`.
        $this->assertDoesNotMatchRegularExpression('/\d+\s*(hours|MB|GB|bytes)/i', $body);
    }

    /**
     * A problem fixed between the scan and the drain is **good news**, not a
     * fault — the same call {@see \App\Modules\Notifications\Mail\CreditLimitDigestMail}
     * makes for an emptied list. Refusing to render would leave a red row in the
     * Notifications page for a backup that started working again.
     */
    public function test_a_problem_that_cleared_reads_as_good_news(): void
    {
        $message = BackupHealthMail::render($this->data([]));

        $this->assertStringContainsString('cleared', strtolower($message->subject));
        $this->assertStringContainsString('nothing to do', $message->text);
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<string> */
    private function rowIds(string $dir): array
    {
        $keys = 'admin:bb637d8ec1cb92bca0467e59faa6d61f6b7f8088103e5b89d7afdc01f1efa45c';

        return array_map(
            static fn ($finding): string => $finding->id,
            (new BackupStatusCheck($dir, $keys, BackupRetention::defaults()))->findings()
        );
    }

    /** @param list<string> $failing */
    private function data(array $failing, MailLanguage $language = MailLanguage::English): BackupHealthDataDto
    {
        return new BackupHealthDataDto(
            language: $language,
            recipientAddress: 'admin@example.org',
            recipientName: 'Vorstand',
            branding: new MailBranding(orgName: 'Club Bar'),
            failing: $failing,
        );
    }
}
