<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Services;

use App\Modules\Terminals\Enums\SyncStream;
use App\Modules\Terminals\Repositories\TerminalSyncCursorsRepository;
use App\Modules\Terminals\Services\TerminalSyncCursorService;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The cursor half of ADR-0041.
 *
 * What is pinned here is the asymmetry the whole signal rests on: a cursor that
 * moves forward is recorded and forgotten, while one that moves backward is
 * recorded *without* lowering the high-water mark. Get that second part wrong
 * and a forked pair walks the mark down to the laggard's value on its first
 * poll, after which neither device ever looks anomalous again.
 */
class TerminalSyncCursorServiceTest extends TestCase
{
    private TerminalSyncCursorsRepository $cursors;
    private TerminalSyncCursorService $service;

    protected function setUp(): void
    {
        $this->cursors = $this->createMock(TerminalSyncCursorsRepository::class);
        $this->service = new TerminalSyncCursorService($this->cursors, $this->createMock(Logger::class));
    }

    public function testFirstSightingIsAdoptedWithoutSuspicion(): void
    {
        $this->cursors->method('find')->willReturn(null);

        $this->cursors->expects($this->once())
            ->method('recordAdvance')
            ->with('terminal-1', SyncStream::MEMBERS, 1_700_000_000_000);
        $this->cursors->expects($this->never())->method('recordRegression');

        $this->service->observe('terminal-1', SyncStream::MEMBERS, 1_700_000_000_000);
    }

    public function testAdvancingCursorIsRecordedAsAnAdvance(): void
    {
        $this->cursors->method('find')->willReturn([
            'high_water_cursor' => 1_000,
            'last_presented_cursor' => 1_000,
            'regression_count' => 0,
        ]);

        $this->cursors->expects($this->once())->method('recordAdvance');
        $this->cursors->expects($this->never())->method('recordRegression');

        $this->service->observe('terminal-1', SyncStream::MEMBERS, 2_000);
    }

    /**
     * `SyncCursor::next()` echoes the client's own cursor back when nothing
     * changed, so a quiet club has every terminal re-presenting the same value
     * indefinitely. That is the single most common request in the system and it
     * must never look like a regression.
     */
    public function testRepresentingTheSameCursorIsNotARegression(): void
    {
        $this->cursors->method('find')->willReturn([
            'high_water_cursor' => 5_000,
            'last_presented_cursor' => 5_000,
            'regression_count' => 0,
        ]);

        $this->cursors->expects($this->once())->method('recordAdvance');
        $this->cursors->expects($this->never())->method('recordRegression');

        $this->service->observe('terminal-1', SyncStream::PRODUCTS, 5_000);
    }

    public function testCursorBelowTheHighWaterMarkIsARegression(): void
    {
        $this->cursors->method('find')->willReturn([
            'high_water_cursor' => 9_000,
            'last_presented_cursor' => 9_000,
            'regression_count' => 0,
        ]);

        $this->cursors->expects($this->once())
            ->method('recordRegression')
            ->with('terminal-1', SyncStream::MEMBERS, 9_000, 3_000, $this->anything());
        $this->cursors->expects($this->never())->method('recordAdvance');

        $this->service->observe('terminal-1', SyncStream::MEMBERS, 3_000);
    }

    /**
     * A caller holding only the token has no cursor to present. It reaches the
     * repository as an ordinary regression to zero; the detector is what reads
     * that zero as its own kind.
     */
    public function testZeroCursorAgainstANonZeroMarkIsARegressionToZero(): void
    {
        $this->cursors->method('find')->willReturn([
            'high_water_cursor' => 9_000,
            'last_presented_cursor' => 9_000,
            'regression_count' => 0,
        ]);

        $this->cursors->expects($this->once())
            ->method('recordRegression')
            ->with('terminal-1', SyncStream::CATEGORIES, 9_000, 0, $this->anything());

        $this->service->observe('terminal-1', SyncStream::CATEGORIES, 0);
    }

    public function testUnauthenticatedRequestIsNotRecorded(): void
    {
        $this->cursors->expects($this->never())->method('find');
        $this->cursors->expects($this->never())->method('recordAdvance');

        $this->service->observe(null, SyncStream::MEMBERS, 1_000);
    }

    /**
     * This sits on the path the bar is waiting on. A storage failure costs a
     * detection; it must never cost the round.
     */
    public function testStorageFailureDoesNotEscape(): void
    {
        $this->cursors->method('find')->willThrowException(new \RuntimeException('database gone'));

        $this->service->observe('terminal-1', SyncStream::MEMBERS, 1_000);

        $this->addToAssertionCount(1);
    }
}
