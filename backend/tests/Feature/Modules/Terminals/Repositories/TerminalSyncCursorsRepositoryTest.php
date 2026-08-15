<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Terminals\Repositories;

use App\Modules\Terminals\Enums\SyncStream;
use App\Modules\Terminals\Repositories\TerminalSyncCursorsRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * The cursor high-water mark, against a real database (ADR-0041 §1).
 *
 * The load-bearing rule lives in SQL and nowhere else: an advance moves the mark
 * with `GREATEST`, and a regression moves `last_presented_cursor` **without**
 * touching the mark. Get the second one wrong and a forked pair ratchets the
 * mark down to the laggard's value on its first poll, after which neither device
 * ever looks anomalous again — the detector goes quiet and nothing tells you.
 */
class TerminalSyncCursorsRepositoryTest extends DatabaseTestCase
{
    private TerminalSyncCursorsRepository $repository;
    private string $terminalId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new TerminalSyncCursorsRepository($this->db);
        $this->terminalId = $this->createTerminal();
    }

    protected function tearDown(): void
    {
        $this->db->prepare('DELETE FROM terminal_sync_cursors WHERE terminal_id = ?')->execute([$this->terminalId]);
        $this->db->prepare('DELETE FROM terminals WHERE id = ?')->execute([$this->terminalId]);
        parent::tearDown();
    }

    private function createTerminal(): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            'INSERT INTO terminals (id, name, device_id, is_active) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$id, 'Cursor test', 'cursor-' . substr($id, 0, 8)]);

        return $id;
    }

    public function test_an_unseen_stream_has_no_row(): void
    {
        $this->assertNull($this->repository->find($this->terminalId, SyncStream::MEMBERS));
    }

    public function test_the_first_advance_creates_the_row(): void
    {
        $this->repository->recordAdvance($this->terminalId, SyncStream::MEMBERS, 5_000);

        $row = $this->repository->find($this->terminalId, SyncStream::MEMBERS);

        $this->assertSame(5_000, $row['high_water_cursor']);
        $this->assertSame(5_000, $row['last_presented_cursor']);
        $this->assertSame(0, $row['regression_count']);
    }

    public function test_the_streams_are_independent(): void
    {
        $this->repository->recordAdvance($this->terminalId, SyncStream::MEMBERS, 5_000);
        $this->repository->recordAdvance($this->terminalId, SyncStream::PRODUCTS, 9_000);

        $this->assertSame(5_000, $this->repository->find($this->terminalId, SyncStream::MEMBERS)['high_water_cursor']);
        $this->assertSame(9_000, $this->repository->find($this->terminalId, SyncStream::PRODUCTS)['high_water_cursor']);
        $this->assertNull($this->repository->find($this->terminalId, SyncStream::CATEGORIES));
    }

    public function test_an_advance_moves_the_mark_forward(): void
    {
        $this->repository->recordAdvance($this->terminalId, SyncStream::MEMBERS, 5_000);
        $this->repository->recordAdvance($this->terminalId, SyncStream::MEMBERS, 7_000);

        $this->assertSame(7_000, $this->repository->find($this->terminalId, SyncStream::MEMBERS)['high_water_cursor']);
    }

    /**
     * Two of the terminal's own requests can be in flight at once, and the
     * slower one carrying an older cursor must not lower the mark.
     */
    public function test_a_late_advance_carrying_an_older_cursor_cannot_lower_the_mark(): void
    {
        $this->repository->recordAdvance($this->terminalId, SyncStream::MEMBERS, 7_000);
        $this->repository->recordAdvance($this->terminalId, SyncStream::MEMBERS, 5_000);

        $row = $this->repository->find($this->terminalId, SyncStream::MEMBERS);

        $this->assertSame(7_000, $row['high_water_cursor'], 'GREATEST must hold the mark');
        $this->assertSame(5_000, $row['last_presented_cursor'], 'but what was actually presented is recorded');
    }

    /**
     * The single most important assertion in this file. See the class docblock.
     */
    public function test_a_regression_records_itself_without_lowering_the_mark(): void
    {
        $this->repository->recordAdvance($this->terminalId, SyncStream::MEMBERS, 9_000);

        $this->repository->recordRegression(
            $this->terminalId,
            SyncStream::MEMBERS,
            9_000,
            3_000,
            strtotime('2026-08-15 19:00:00'),
        );

        $row = $this->repository->find($this->terminalId, SyncStream::MEMBERS);

        $this->assertSame(9_000, $row['high_water_cursor']);
        $this->assertSame(3_000, $row['last_presented_cursor']);
        $this->assertSame(1, $row['regression_count']);
    }

    public function test_regressions_accumulate(): void
    {
        $this->repository->recordAdvance($this->terminalId, SyncStream::MEMBERS, 9_000);
        $at = strtotime('2026-08-15 19:00:00');

        $this->repository->recordRegression($this->terminalId, SyncStream::MEMBERS, 9_000, 3_000, $at);
        $this->repository->recordRegression($this->terminalId, SyncStream::MEMBERS, 9_000, 4_000, $at + 60);

        $this->assertSame(2, $this->repository->find($this->terminalId, SyncStream::MEMBERS)['regression_count']);
    }

    public function test_regressions_since_reports_the_details_the_detector_classifies_on(): void
    {
        $this->repository->recordAdvance($this->terminalId, SyncStream::PRODUCTS, 9_000);
        $this->repository->recordRegression(
            $this->terminalId,
            SyncStream::PRODUCTS,
            9_000,
            0,
            strtotime('2026-08-15 19:00:00'),
        );

        $found = array_values(array_filter(
            $this->repository->regressionsSince('2026-08-15 18:00:00'),
            fn(array $row) => $row['terminal_id'] === $this->terminalId,
        ));

        $this->assertCount(1, $found);
        $this->assertSame('products', $found[0]['stream']);
        $this->assertSame(9_000, $found[0]['last_regression_from']);
        // Zero is what the detector reads as `cursor_reset` rather than
        // `cursor_regression`, so it has to survive the round trip as 0 and not
        // as null.
        $this->assertSame(0, $found[0]['last_regression_to']);
    }

    public function test_a_stream_that_never_regressed_is_not_reported(): void
    {
        $this->repository->recordAdvance($this->terminalId, SyncStream::MEMBERS, 5_000);

        $found = array_filter(
            $this->repository->regressionsSince('2026-08-15 00:00:00'),
            fn(array $row) => $row['terminal_id'] === $this->terminalId,
        );

        $this->assertSame([], $found);
    }

    public function test_a_regression_older_than_the_window_is_not_reported(): void
    {
        $this->repository->recordAdvance($this->terminalId, SyncStream::MEMBERS, 9_000);
        $this->repository->recordRegression(
            $this->terminalId,
            SyncStream::MEMBERS,
            9_000,
            3_000,
            strtotime('2026-08-14 10:00:00'),
        );

        $found = array_filter(
            $this->repository->regressionsSince('2026-08-15 18:00:00'),
            fn(array $row) => $row['terminal_id'] === $this->terminalId,
        );

        $this->assertSame([], $found);
    }

    public function test_cursors_go_when_the_terminal_goes(): void
    {
        $this->repository->recordAdvance($this->terminalId, SyncStream::MEMBERS, 5_000);

        $this->db->prepare('DELETE FROM terminals WHERE id = ?')->execute([$this->terminalId]);

        $this->assertNull($this->repository->find($this->terminalId, SyncStream::MEMBERS));
    }
}
