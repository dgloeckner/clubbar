<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Services;

use App\Modules\Backups\Services\BackupJournal;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * The run history that lives beside the archives rather than inside the
 * database they contain (ADR-0049 decision 8).
 *
 * Two properties are worth a unit test, and they pull in opposite directions.
 * The journal has to be **useful** — the minimum-interval guard reads the last
 * `started` line from it, and that guard is what stops `/api/cron/backup`
 * filling a webspace quota with dumps. And it has to be **harmless**: it is a
 * convenience, never a truth, so nothing it meets may take a backup down. A
 * missing file, an unwritable directory, a line somebody truncated with a
 * text editor — each is answered rather than thrown.
 *
 * The directory is a temp tree ({@see TempTree}), never a path built from a
 * property that might not be set — CLAUDE.md, destructive test cleanup.
 *
 * Part of #703, epic #686.
 */
class BackupJournalTest extends TestCase
{
    use TempTree;

    private string $tempTree = '';

    protected function setUp(): void
    {
        $this->tempTree = self::makeTempTree('clubbar-journal-test');
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->tempTree);
    }

    public function test_each_event_is_one_json_line_carrying_its_own_timestamp(): void
    {
        $journal = new BackupJournal($this->tempTree);

        $journal->started('cli');
        $journal->written('clubbar-20260825-030000-aabbccdd.cbb', 4096, str_repeat('a', 64), ['fp'], 27);

        $lines = $this->lines();

        $this->assertCount(2, $lines);
        $this->assertSame('started', $lines[0]['event']);
        $this->assertSame('cli', $lines[0]['trigger']);
        $this->assertSame('written', $lines[1]['event']);
        $this->assertSame(27, $lines[1]['tables']);
        $this->assertNotEmpty($lines[0]['at'], 'A line with no time answers nothing about when.');
    }

    /**
     * Appending, never rewriting: a journal a run could truncate would lose the
     * history of every run before it, which is the one thing it is for.
     */
    public function test_a_second_run_appends_rather_than_replacing(): void
    {
        (new BackupJournal($this->tempTree))->started('cli');
        (new BackupJournal($this->tempTree))->started('url');

        $this->assertSame(['cli', 'url'], array_column($this->lines(), 'trigger'));
    }

    public function test_the_last_start_is_what_the_interval_guard_reads(): void
    {
        $journal = new BackupJournal($this->tempTree);

        $this->assertNull(
            $journal->lastStartedAt(),
            'An installation that has never run must not be held back by a guard.'
        );

        $journal->started('cli');

        $this->assertEqualsWithDelta(time(), $journal->lastStartedAt(), 5);
    }

    /**
     * The guard keys on *attempts*, so a failure in between must not make the
     * last start look older than it was — the quota is spent by an attempt, and
     * keying on success would let a failing run be triggered in a loop.
     */
    public function test_a_failure_after_a_start_does_not_move_the_last_start(): void
    {
        $journal = new BackupJournal($this->tempTree);

        $journal->started('url');
        $journal->failed('the disk is full');

        $this->assertEqualsWithDelta(time(), $journal->lastStartedAt(), 5);
        $this->assertSame('failed', $this->lines()[1]['event']);
    }

    /**
     * A journal that is not there is a club that has not run a backup yet, or
     * one that deleted its backup directory. Neither is an error: the archives
     * are the record, and the journal is what is left of the attempts.
     */
    public function test_a_missing_journal_reads_as_no_history_rather_than_failing(): void
    {
        $this->assertNull((new BackupJournal($this->tempTree . '/never-created'))->lastStartedAt());
    }

    /**
     * The failure mode of a convenience: a line half-written by something that
     * died mid-append, or edited by hand.
     *
     * Skipped rather than fatal — and the guard falls through to the last line
     * it *can* read, so one damaged entry costs one attempt's history and not
     * the guard itself.
     */
    public function test_a_damaged_line_is_skipped_rather_than_taking_a_run_down(): void
    {
        $journal = new BackupJournal($this->tempTree);
        $journal->started('cli');

        file_put_contents($journal->path(), '{"at":"2026-08-25T03:00:00+00:0', FILE_APPEND);

        $this->assertEqualsWithDelta(time(), $journal->lastStartedAt(), 5);
    }

    /**
     * A timestamp that will not parse is treated as absent rather than as "now"
     * or as the epoch: one of those guesses the guard shut and the other guesses
     * it open, and neither is knowable from a broken line.
     */
    public function test_an_unparseable_timestamp_reads_as_no_history(): void
    {
        $journal = new BackupJournal($this->tempTree);
        file_put_contents($journal->path(), json_encode(['at' => 'whenever', 'event' => 'started']) . "\n");

        $this->assertNull($journal->lastStartedAt());
    }

    /**
     * An unwritable directory must not fail a run that already produced an
     * archive. The archive is the record; losing the journal line about it is a
     * lesser loss than turning a successful backup into a failed one.
     */
    public function test_an_unwritable_directory_is_swallowed_rather_than_thrown(): void
    {
        $journal = new BackupJournal($this->tempTree . '/does/not/exist');

        $journal->started('cli');

        $this->assertNull($journal->lastStartedAt());
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        $path = $this->tempTree . '/' . BackupJournal::FILENAME;

        return array_values(array_map(
            static fn (string $line): array => json_decode($line, true),
            array_filter(
                explode("\n", (string) file_get_contents($path)),
                static fn (string $line): bool => trim($line) !== ''
            )
        ));
    }
}
