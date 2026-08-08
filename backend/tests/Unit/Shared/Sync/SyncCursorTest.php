<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Sync;

use App\Shared\Sync\SyncCursor;
use PHPUnit\Framework\TestCase;

/**
 * The delta window has to survive MySQL's second precision (#84): a member or
 * price written later in the same second the cursor names must still be inside
 * the next window, or the terminal never sees it again.
 */
class SyncCursorTest extends TestCase
{
    /** Unix second for a fixed wall-clock time, in whatever timezone PHP runs in. */
    private function second(string $mysqlDateTime): int
    {
        return (new \DateTime($mysqlDateTime))->getTimestamp();
    }

    private function row(?string $updatedAt, ?string $deletedAt = null): array
    {
        return ['id' => 'row', 'updated_at' => $updatedAt, 'deleted_at' => $deletedAt];
    }

    public function test_lowerBound_truncates_the_sub_second_part(): void
    {
        $t = $this->second('2026-08-08 12:00:00');

        // Rounding up here would skip exactly the rows the inclusive bound exists for.
        $this->assertSame('2026-08-08 12:00:00', SyncCursor::lowerBound($t * 1000 + 999));
        $this->assertSame('2026-08-08 12:00:00', SyncCursor::lowerBound($t * 1000));
    }

    public function test_next_echoes_the_client_cursor_when_nothing_changed(): void
    {
        $this->assertSame(9999999999999, SyncCursor::next([], 9999999999999, time()));
    }

    public function test_next_holds_the_cursor_inside_the_second_the_query_ran_in(): void
    {
        $t = $this->second('2026-08-08 12:00:00');

        // Member A was written at 12:00:00.2 and the sync ran at 12:00:00.5, so
        // the second is still open: member B may yet be written at 12:00:00.8.
        $cursor = SyncCursor::next([$this->row('2026-08-08 12:00:00')], 0, $t);

        $this->assertSame($t * 1000, $cursor);
        // …and because the bound is inclusive, B is inside the next window.
        $this->assertSame('2026-08-08 12:00:00', SyncCursor::lowerBound($cursor));
    }

    public function test_next_steps_past_a_second_that_was_already_over(): void
    {
        $t = $this->second('2026-08-08 12:00:00');

        // Query ran five seconds later: nothing can still land in 12:00:00, so
        // the cursor moves on instead of re-sending that second forever.
        $cursor = SyncCursor::next([$this->row('2026-08-08 12:00:00')], 0, $t + 5);

        $this->assertSame(($t + 1) * 1000, $cursor);
        $this->assertSame('2026-08-08 12:00:01', SyncCursor::lowerBound($cursor));
    }

    public function test_next_takes_the_newest_timestamp_across_every_row_and_column(): void
    {
        $t = $this->second('2026-08-08 12:00:03');

        // A tombstone carries its time in deleted_at; reading only the last
        // row's updated_at used to fall back to "now" and skip the gap.
        $cursor = SyncCursor::next(
            [
                $this->row('2026-08-08 12:00:01'),
                $this->row(null, '2026-08-08 12:00:03'),
            ],
            0,
            $t + 5,
        );

        $this->assertSame(($t + 1) * 1000, $cursor);
    }

    public function test_next_never_walks_backwards(): void
    {
        $t = $this->second('2026-08-08 12:00:00');

        // The client's cursor carries a sub-second part the rows cannot express;
        // handing back less would widen the window on every poll.
        $cursor = SyncCursor::next([$this->row('2026-08-08 12:00:00')], $t * 1000 + 500, $t);

        $this->assertSame($t * 1000 + 500, $cursor);
    }

    public function test_next_falls_back_to_the_client_cursor_when_no_row_has_a_usable_timestamp(): void
    {
        $since = $this->second('2026-08-08 12:00:00') * 1000;

        $this->assertSame($since, SyncCursor::next([$this->row(null, null)], $since, time()));
    }

    /**
     * The whole point, end to end: the second write of a pair that share a
     * second is delivered, instead of being lost the way #84 describes.
     */
    public function test_a_same_second_write_survives_the_cursor_round_trip(): void
    {
        $t = $this->second('2026-08-08 12:00:00');

        // Sync at 12:00:00.5 sees only member A.
        $cursor = SyncCursor::next([$this->row('2026-08-08 12:00:00')], 0, $t);

        // Member B is written at 12:00:00.8 and stored as 12:00:00. The next
        // window opens at 12:00:00 inclusive, so the query still reaches it.
        $bound = SyncCursor::lowerBound($cursor);
        $this->assertLessThanOrEqual($this->second('2026-08-08 12:00:00'), $this->second($bound));
    }
}
