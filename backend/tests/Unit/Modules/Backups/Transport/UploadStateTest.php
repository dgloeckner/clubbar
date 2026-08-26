<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Transport;

use App\Modules\Backups\Transport\UploadState;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * The sidecar that lets a cut-short upload continue tomorrow night.
 *
 * ADR-0049 decision 8: *"the backup directory holds the archives, an
 * append-only journal, and, while an upload is in flight, a sidecar file with
 * its resume state."* Not a database row — a run that stored its progress
 * inside the database it is dumping would put half an upload into the next
 * archive, and a restore would resurrect it.
 *
 * It is beside the archive and named after it, so the two are deleted together
 * by anybody tidying the directory by hand, and so an orphan is obvious rather
 * than mysterious.
 *
 * Part of #691, epic #686.
 */
class UploadStateTest extends TestCase
{
    use TempTree;

    private string $dir;

    /**
     * A session that has not expired yet — **relative to now**, never a literal.
     *
     * A fixed timestamp here is a time bomb, and this file shipped with three
     * of them. `read()` treats an expired session as absent by design, so a
     * date written eight hours in the future passed on the day it was written
     * and failed every run after it, with an assertion message —
     * *"Failed asserting that null is not null"* — that says nothing about
     * clocks. The one thing this class is *about* is a timestamp, which is
     * exactly where a literal one cannot be allowed to live.
     */
    private static function inAnHour(): string
    {
        return gmdate('c', time() + 3600);
    }

    protected function setUp(): void
    {
        $this->dir = $this->makeTempTree('upload-state');
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->dir);
    }

    public function test_nothing_is_remembered_before_an_upload_starts(): void
    {
        $state = new UploadState($this->dir . '/clubbar-20260825-030000-1a2b3c4d.cbb');

        $this->assertNull($state->read());
    }

    public function test_it_remembers_the_session_url_and_how_far_the_upload_got(): void
    {
        $archive = $this->dir . '/clubbar-20260825-030000-1a2b3c4d.cbb';
        $state = new UploadState($archive);

        $state->write('https://upload.example/session/abc', self::inAnHour(), 3276800, 9000000);

        $read = (new UploadState($archive))->read();

        $this->assertNotNull($read);
        $this->assertSame('https://upload.example/session/abc', $read->uploadUrl);
        $this->assertSame(3276800, $read->uploaded);
        $this->assertSame(9000000, $read->size);
    }

    /** The sidecar sits beside the archive and names it, so an orphan is obvious. */
    public function test_the_sidecar_is_named_after_the_archive_it_belongs_to(): void
    {
        $state = new UploadState($this->dir . '/clubbar-20260825-030000-1a2b3c4d.cbb');
        $state->write('https://upload.example/s', self::inAnHour(), 0, 10);

        $this->assertFileExists($this->dir . '/clubbar-20260825-030000-1a2b3c4d.cbb.upload.json');
    }

    /**
     * A finished upload leaves nothing behind: a sidecar *is* the signal that
     * an upload is unfinished, so one left after success would make the next
     * run resume an upload that already completed.
     */
    public function test_completing_clears_the_sidecar(): void
    {
        $archive = $this->dir . '/clubbar-20260825-030000-1a2b3c4d.cbb';
        $state = new UploadState($archive);
        $state->write('https://upload.example/s', self::inAnHour(), 5, 10);

        $state->clear();

        $this->assertFileDoesNotExist($archive . UploadState::SUFFIX);
        $this->assertNull($state->read());
    }

    /**
     * A session Graph has already expired is worse than no session: resuming it
     * gets a 404 on every chunk until the archive ages out. An expired sidecar
     * reads as absent, so the next run creates a fresh session instead.
     */
    public function test_an_expired_session_reads_as_no_session_at_all(): void
    {
        $archive = $this->dir . '/clubbar-20260825-030000-1a2b3c4d.cbb';
        (new UploadState($archive))->write(
            'https://upload.example/s',
            gmdate('c', time() - 3600),
            5,
            10
        );

        $this->assertNull((new UploadState($archive))->read());
    }

    /** A half-written or hand-edited sidecar is discarded, never half-believed. */
    public function test_an_unreadable_sidecar_is_discarded_rather_than_half_believed(): void
    {
        $archive = $this->dir . '/clubbar-20260825-030000-1a2b3c4d.cbb';
        file_put_contents($archive . UploadState::SUFFIX, '{"uploadUrl": "https://upl');

        $this->assertNull((new UploadState($archive))->read());
    }

    /** Every archive in the directory that still has one, for the resume sweep. */
    public function test_pending_uploads_are_discoverable_by_scanning_the_directory(): void
    {
        file_put_contents($this->dir . '/clubbar-a.cbb', 'x');
        file_put_contents($this->dir . '/clubbar-b.cbb', 'x');
        (new UploadState($this->dir . '/clubbar-a.cbb'))
            ->write('https://upload.example/s', gmdate('c', time() + 3600), 1, 2);

        $pending = UploadState::pendingIn($this->dir);

        $this->assertSame(['clubbar-a.cbb'], array_map('basename', $pending));
    }
}
