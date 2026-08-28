<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Services;

use App\Modules\Backups\Services\RemoteInventory;
use App\Modules\Backups\Transport\RemoteArchive;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * The one answer the local directory cannot give (#693, ADR-0049).
 *
 * A local archive and an uploaded one are the same bytes in the same folder —
 * an upload is a copy, not a move — so *"is this still off-site"* can only come
 * from the store. The backups page may ask it — on a separate request, bounded,
 * enriching a view that has already rendered — but the local view must never
 * wait on a throttled tenant, and the self-check and the every-page banner may
 * not ask at all.
 *
 * So the cheap answer is taken where the cost is already paid: the nightly run
 * lists the store anyway, to age archives out, and this writes that listing
 * down. It is the instant seed before a live call returns, and the truthful
 * fallback when one fails.
 *
 * Part of #693, epic #686.
 */
class RemoteInventoryTest extends TestCase
{
    use TempTree;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = self::makeTempTree('remote-inventory');
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->dir);
    }

    /**
     * **Three-valued, and the third value is the point.** "Nobody has looked"
     * must not collapse into "the store says no": one is a scheduler problem
     * and the other is a missing backup, and a club acts on them differently.
     */
    public function test_no_snapshot_is_unknown_rather_than_absent(): void
    {
        $inventory = new RemoteInventory($this->dir);

        $this->assertNull($inventory->read());
        $this->assertNull($inventory->holds('clubbar-20260825-030000-1a2b3c4d.cbb'));
    }

    public function test_it_records_what_the_store_answered(): void
    {
        $inventory = new RemoteInventory($this->dir);

        $this->assertTrue($inventory->record(
            [$this->archive('clubbar-20260825-030000-1a2b3c4d.cbb'), $this->archive('clubbar-20260826-030000-99887766.cbb')],
            'msgraph://tenant/drive/backups',
            takenAt: 1788000000,
        ));

        $snapshot = $inventory->read();

        $this->assertSame(1788000000, $snapshot['taken_at']);
        $this->assertSame('msgraph://tenant/drive/backups', $snapshot['remote']);
        $this->assertTrue($inventory->holds('clubbar-20260825-030000-1a2b3c4d.cbb'));
        $this->assertFalse($inventory->holds('clubbar-20260101-030000-deadbeef.cbb'), 'the store was asked and said no');
    }

    /**
     * An empty store is a real answer and must not read as "never looked" — a
     * club whose uploads have all failed needs the difference.
     */
    public function test_an_empty_store_is_an_answer_not_a_silence(): void
    {
        $inventory = new RemoteInventory($this->dir);
        $inventory->record([], 'msgraph://tenant/drive/backups', takenAt: 1788000000);

        $this->assertNotNull($inventory->read());
        $this->assertFalse($inventory->holds('clubbar-20260825-030000-1a2b3c4d.cbb'));
    }

    /**
     * **Stale beats absent.** A run whose listing failed leaves the previous
     * snapshot alone — last night's answer is imperfect, but no answer sends
     * somebody to the provider's portal to establish what they were told a day
     * ago. The timestamp is what lets a reader judge.
     */
    public function test_a_snapshot_survives_a_run_that_could_not_list(): void
    {
        $inventory = new RemoteInventory($this->dir);
        $inventory->record([$this->archive('clubbar-20260825-030000-1a2b3c4d.cbb')], 'msgraph://x', takenAt: 1788000000);

        // A failed listing never calls record() at all — BackupService returns
        // early — so the file is simply still there, with its original date.
        $snapshot = $inventory->read();

        $this->assertSame(1788000000, $snapshot['taken_at']);
        $this->assertTrue($inventory->holds('clubbar-20260825-030000-1a2b3c4d.cbb'));
    }

    /**
     * A truncated or hand-edited file reads as no snapshot rather than as an
     * empty store, which is the same conservative direction as everything else
     * here: never claim the store was asked when it was not.
     */
    public function test_an_unreadable_snapshot_is_treated_as_no_snapshot(): void
    {
        file_put_contents($this->dir . '/' . RemoteInventory::FILENAME, '{"archives": [{"name": "half');

        $this->assertNull((new RemoteInventory($this->dir))->read());
    }

    /**
     * The archive scan decides what retention may **delete**, so this file must
     * be invisible to it — a sidecar swept up by a prune would be a page losing
     * its answer every night.
     */
    public function test_the_snapshot_is_not_mistaken_for_an_archive(): void
    {
        (new RemoteInventory($this->dir))->record([], 'msgraph://x');

        $this->assertSame(
            [],
            (new \App\Modules\Backups\Services\ArchiveDirectory($this->dir))->oldestFirst()
        );
    }

    /**
     * Written atomically, so a page loading while the nightly run rewrites the
     * file gets one whole version or the other — never a truncated array that
     * would report a club has no off-site copies.
     */
    public function test_the_write_leaves_no_temporary_file_behind(): void
    {
        (new RemoteInventory($this->dir))->record([$this->archive('clubbar-x.cbb')], 'msgraph://x');

        $this->assertSame([], glob($this->dir . '/*.tmp') ?: []);
    }

    private function archive(string $name): RemoteArchive
    {
        // `createdAt()` is derived from the filename rather than stored — the
        // store's own timestamp disagrees exactly when it matters.
        return new RemoteArchive(id: 'item-' . $name, name: $name, size: 2048, driveId: 'drive-1');
    }
}
