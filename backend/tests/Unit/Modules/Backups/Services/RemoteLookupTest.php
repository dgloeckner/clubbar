<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Services;

use App\Modules\Backups\Services\RemoteInventory;
use App\Modules\Backups\Services\RemoteLookup;
use App\Modules\Backups\Transport\BackupTransport;
use App\Modules\Backups\Transport\BackupTransportException;
use App\Modules\Backups\Transport\RemoteArchive;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

/**
 * The enrichment half of the backups page (#693, ADR-0049).
 *
 * Three sources, and the reader is always told which. Collapsing them to two
 * would be the bug worth guarding: *"the store says this archive is gone"* and
 * *"we could not ask, and last night it was there"* send a club to different
 * places, and only one of them means a backup is missing.
 *
 * Part of #693, epic #686.
 */
class RemoteLookupTest extends TestCase
{
    use TempTree;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = self::makeTempTree('remote-lookup');
    }

    protected function tearDown(): void
    {
        self::removeTempTree($this->dir);
    }

    /** Asked and answered: the freshest thing the page can say. */
    public function test_a_store_that_answers_is_reported_live(): void
    {
        $result = $this->lookup($this->transportListing(['clubbar-a.cbb', 'clubbar-b.cbb']))->look();

        $this->assertSame(RemoteLookup::SOURCE_LIVE, $result['source']);
        $this->assertSame(['clubbar-a.cbb', 'clubbar-b.cbb'], $result['names']);
        $this->assertGreaterThan(0, $result['taken_at'], 'a live answer dates itself too');
    }

    /**
     * **The fallback that makes the bounded call affordable.** Eight seconds and
     * no retries means a throttled tenant will sometimes not answer, and that
     * must degrade to last night rather than to nothing.
     */
    public function test_a_store_that_refuses_falls_back_to_the_snapshot(): void
    {
        $this->snapshot(['clubbar-a.cbb'], takenAt: 1788000000);

        $result = $this->lookup($this->transportThrowing())->look();

        $this->assertSame(RemoteLookup::SOURCE_SNAPSHOT, $result['source']);
        $this->assertSame(['clubbar-a.cbb'], $result['names']);
        // The date is not decoration: a reader has to weigh how old this is,
        // and "as of last night" is a different claim from "now".
        $this->assertSame(1788000000, $result['taken_at']);
    }

    /**
     * No `backup.dsn` is a legitimate state — a club may keep local archives
     * and carry them off by hand — and the snapshot is then the only source
     * there has ever been.
     */
    public function test_without_a_transport_the_snapshot_is_the_answer(): void
    {
        $this->snapshot(['clubbar-a.cbb'], takenAt: 1788000000);

        $result = (new RemoteLookup(new RemoteInventory($this->dir), $this->logger()))->look();

        $this->assertSame(RemoteLookup::SOURCE_SNAPSHOT, $result['source']);
    }

    /**
     * **Never "no".** Nothing to ask and nothing recorded means nobody has ever
     * looked — on a configured installation, that the nightly job has not run.
     * Rendering it as an empty store would tell a club its archives are missing
     * off-site, which hides the actual problem.
     */
    public function test_no_answer_and_no_snapshot_is_unavailable(): void
    {
        $result = $this->lookup($this->transportThrowing())->look();

        $this->assertSame(RemoteLookup::SOURCE_UNAVAILABLE, $result['source']);
        $this->assertSame([], $result['names']);
        $this->assertNull($result['taken_at'], 'there is no date to give');
    }

    /** A live answer wins over a snapshot, which is the whole point of asking. */
    public function test_a_live_answer_supersedes_the_snapshot(): void
    {
        $this->snapshot(['clubbar-old.cbb'], takenAt: 1788000000);

        $result = $this->lookup($this->transportListing(['clubbar-new.cbb']))->look();

        $this->assertSame(RemoteLookup::SOURCE_LIVE, $result['source']);
        $this->assertSame(['clubbar-new.cbb'], $result['names']);
    }

    /** An empty store answering is still an answer, not a failure to reach it. */
    public function test_an_empty_store_that_answers_is_live_and_empty(): void
    {
        $result = $this->lookup($this->transportListing([]))->look();

        $this->assertSame(RemoteLookup::SOURCE_LIVE, $result['source']);
        $this->assertSame([], $result['names']);
    }

    // ---------------------------------------------------------------- helpers

    private function lookup(BackupTransport $transport): RemoteLookup
    {
        return new RemoteLookup(new RemoteInventory($this->dir), $this->logger(), $transport);
    }

    private function logger(): Logger
    {
        return $this->createMock(Logger::class);
    }

    /** @param list<string> $names */
    private function snapshot(array $names, int $takenAt): void
    {
        (new RemoteInventory($this->dir))->record(
            array_map(
                static fn (string $name): RemoteArchive => new RemoteArchive(
                    id: 'item-' . $name,
                    name: $name,
                    size: 2048,
                    driveId: 'drive-1',
                ),
                $names
            ),
            'msgraph://tenant/drive/backups',
            $takenAt,
        );
    }

    /** @param list<string> $names */
    private function transportListing(array $names): BackupTransport
    {
        $transport = $this->createMock(BackupTransport::class);
        $transport->method('describe')->willReturn('msgraph://tenant/drive/backups');
        $transport->method('list')->willReturn(array_map(
            static fn (string $name): RemoteArchive => new RemoteArchive(
                id: 'item-' . $name,
                name: $name,
                size: 2048,
                driveId: 'drive-1',
            ),
            $names
        ));

        return $transport;
    }

    private function transportThrowing(): BackupTransport
    {
        $transport = $this->createMock(BackupTransport::class);
        $transport->method('describe')->willReturn('msgraph://tenant/drive/backups');
        $transport->method('list')->willThrowException(new BackupTransportException('the tenant is throttling'));

        return $transport;
    }
}
