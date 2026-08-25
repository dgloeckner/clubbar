<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Domain;

use App\Modules\Backups\Domain\BackupRetention;
use App\Modules\Backups\Domain\RemoteRetention;
use App\Modules\Backups\Transport\RemoteArchive;
use PHPUnit\Framework\TestCase;

/**
 * Which archives the remote store should stop holding.
 *
 * Remote retention is what gives an [ADR-0029](../../../../../../adr/0029-two-tier-retention-and-erasure.md)
 * erasure a **bounded** schedule: a member exercising Art. 17 is anonymised in
 * the live database immediately, and their old rows keep existing inside every
 * archive sealed before that day. Those archives have to age out, or the
 * erasure is a promise with no end date.
 *
 * Deciding *what* to delete is separated from *doing* it because the decision
 * is where the dangerous mistakes live and the deletion is not. Two rules make
 * this safe, and both exist because the credential doing the deleting can
 * delete anything in the library:
 *
 * 1. **Only this job's own archives**, and only ones whose *name* carries a
 *    date. Nothing else in that folder is ever a candidate.
 * 2. **Never the newest**, whatever the arithmetic says. An installation whose
 *    only remote archive is older than the window must end up reported, not
 *    empty.
 *
 * Part of #691, epic #686.
 */
class RemoteRetentionTest extends TestCase
{
    private const NOW = 1787000000; // 2026-08-17T09:33:20Z

    public function test_archives_past_the_window_are_selected_oldest_first(): void
    {
        $expired = RemoteRetention::expiredAmong(
            [
                $this->archive('clubbar-20260101-030000-aaaa.cbb'),
                $this->archive('clubbar-20260810-030000-cccc.cbb'),
                $this->archive('clubbar-20260201-030000-bbbb.cbb'),
            ],
            BackupRetention::defaults(),
            self::NOW,
        );

        $this->assertSame(
            ['clubbar-20260101-030000-aaaa.cbb', 'clubbar-20260201-030000-bbbb.cbb'],
            array_map(static fn (RemoteArchive $a): string => $a->name, $expired)
        );
    }

    /**
     * The rule that keeps an ageing club from ending up with an empty remote.
     *
     * A club whose backups stopped six months ago has a real problem, and
     * deleting the last archive it managed to push is the one action that makes
     * that problem unrecoverable instead of merely urgent.
     */
    public function test_the_newest_archive_is_never_selected_even_when_every_archive_is_expired(): void
    {
        $expired = RemoteRetention::expiredAmong(
            [
                $this->archive('clubbar-20250101-030000-aaaa.cbb'),
                $this->archive('clubbar-20250201-030000-bbbb.cbb'),
            ],
            BackupRetention::defaults(),
            self::NOW,
        );

        $this->assertSame(
            ['clubbar-20250101-030000-aaaa.cbb'],
            array_map(static fn (RemoteArchive $a): string => $a->name, $expired)
        );
    }

    public function test_a_single_expired_archive_is_kept_rather_than_leaving_the_remote_empty(): void
    {
        $this->assertSame([], RemoteRetention::expiredAmong(
            [$this->archive('clubbar-20250101-030000-aaaa.cbb')],
            BackupRetention::defaults(),
            self::NOW,
        ));
    }

    public function test_nothing_inside_the_window_is_selected(): void
    {
        $this->assertSame([], RemoteRetention::expiredAmong(
            [
                $this->archive('clubbar-20260810-030000-aaaa.cbb'),
                $this->archive('clubbar-20260811-030000-bbbb.cbb'),
            ],
            BackupRetention::defaults(),
            self::NOW,
        ));
    }

    /**
     * An archive whose name will not parse has no age, and something with no
     * age cannot be *too old*. Guessing — from the store's `createdDateTime`,
     * say — would delete on a property that changes when an archive is
     * re-uploaded after a failed night.
     */
    public function test_an_archive_whose_name_carries_no_date_is_left_alone(): void
    {
        $expired = RemoteRetention::expiredAmong(
            [
                $this->archive('clubbar-restored-by-hand.cbb'),
                $this->archive('clubbar-20250101-030000-aaaa.cbb'),
                $this->archive('clubbar-20260810-030000-cccc.cbb'),
            ],
            BackupRetention::defaults(),
            self::NOW,
        );

        $this->assertSame(
            ['clubbar-20250101-030000-aaaa.cbb'],
            array_map(static fn (RemoteArchive $a): string => $a->name, $expired)
        );
    }

    /** The window is configurable above the compiled default. */
    public function test_a_shorter_window_selects_more(): void
    {
        $archives = [
            $this->archive('clubbar-20260701-030000-aaaa.cbb'),
            $this->archive('clubbar-20260810-030000-bbbb.cbb'),
        ];

        $this->assertSame([], RemoteRetention::expiredAmong(
            $archives,
            BackupRetention::defaults(),
            self::NOW
        ));

        $this->assertCount(1, RemoteRetention::expiredAmong(
            $archives,
            BackupRetention::fromOverrides(null, null, 7),
            self::NOW
        ));
    }

    private function archive(string $name): RemoteArchive
    {
        return new RemoteArchive('id-' . $name, $name, 1024, 'drive-1');
    }
}
