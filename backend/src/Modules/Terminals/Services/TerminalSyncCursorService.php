<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Services;

use App\Modules\Terminals\Enums\SyncStream;
use App\Modules\Terminals\Repositories\TerminalSyncCursorsRepository;
use App\Shared\Logging\Logger;

/**
 * Watches the delta cursor a terminal presents (ADR-0041 §1).
 *
 * The cursor is the one piece of per-terminal state the protocol already
 * carries and the server has never kept: `SyncCursor` issues a millisecond
 * watermark, the terminal stores it, and it comes back on the next pull as
 * `?since=`. For an honest client that value only ever goes up — the terminal
 * persists exactly what it was given, and `SyncCursor::next()` returns
 * `max($sinceMs, …)`. A client presenting less is not the client this token has
 * been talking to.
 *
 * **This never changes a response.** The delta is served from the presented
 * cursor exactly as it was before, whatever this observes. A detector that
 * altered sync behaviour would be enforcement wearing a different hat, and
 * ADR-0041 decided against enforcement.
 */
class TerminalSyncCursorService
{
    public function __construct(
        private TerminalSyncCursorsRepository $cursors,
        private Logger $logger,
    ) {}

    /**
     * Record what this terminal asked for.
     *
     * Never throws. This sits on the sync path, and no observation is worth
     * failing a pull the bar is waiting on — a lost sighting costs a detection,
     * a 500 costs the round.
     */
    public function observe(?string $terminalId, SyncStream $stream, int $presented): void
    {
        if ($terminalId === null || $terminalId === '' || $presented < 0) {
            return;
        }

        try {
            $existing = $this->cursors->find($terminalId, $stream);

            // Nothing to compare against yet. Trust on first use, exactly as
            // ADR-0035 does for the backend's instance id in the other
            // direction: the first value observed becomes the baseline.
            if ($existing === null) {
                $this->cursors->recordAdvance($terminalId, $stream, $presented);
                return;
            }

            $highWater = $existing['high_water_cursor'];

            if ($presented >= $highWater) {
                $this->cursors->recordAdvance($terminalId, $stream, $presented);
                return;
            }

            $this->cursors->recordRegression($terminalId, $stream, $highWater, $presented, time());

            // Logged as well as recorded: the cron tick turns this into an
            // alert minutes later, and somebody reading the log during an
            // incident should not have to wait for that.
            $this->logger->warning('Terminal presented a sync cursor below its high-water mark', [
                'terminal_id' => $terminalId,
                'stream' => $stream->value,
                'high_water_cursor' => $highWater,
                'presented_cursor' => $presented,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Could not record terminal sync cursor', [
                'terminal_id' => $terminalId,
                'stream' => $stream->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
