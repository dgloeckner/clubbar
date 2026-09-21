<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Services;

use App\Modules\Terminals\DTOs\DispenserStatusReport;
use App\Modules\Terminals\Exceptions\InvalidDispenserReportException;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Logging\Logger;

/**
 * Records what a terminal last said about its dispenser (ADR-0057, #952).
 *
 * **Fail-open, and the word is load-bearing.** Every refusal in here ends the
 * same way: nothing is written, a line is logged, and the caller answers `204`.
 * A terminal must never fail a sync cycle over telemetry — a bar that cannot
 * sell beer because the kiosk could not file a status report is a far worse
 * outcome than an admin panel one report out of date.
 *
 * **Last write wins, and there is no history.** The device's own counters are
 * cumulative, so a trend is recoverable from two reads without a table nobody
 * prunes — and there is no cron on shared hosting to prune one with
 * (ADR-0031). The one thing that cannot be recovered that way is *when this
 * episode began*, which is why `state_since` is carried forward here rather
 * than being computed later from rows that do not exist.
 */
class DispenserStatusService
{
    public function __construct(
        private TerminalsRepository $terminals,
        private Logger $logger,
    ) {}

    /**
     * @param mixed    $parsedBody The decoded request body, as Slim parsed it.
     * @param int|null $bodyBytes  Size of the raw body, where the caller knows it.
     *
     * @return bool Whether the report was stored. The HTTP answer does not
     *              depend on it; the return exists so a test can tell the
     *              difference between "kept the previous value" and "wrote a
     *              new one" without reading the log.
     */
    public function record(
        string $terminalId,
        mixed $parsedBody,
        ?int $bodyBytes = null,
        ?\DateTimeImmutable $now = null,
    ): bool
    {
        try {
            $report = self::parse($parsedBody, $bodyBytes);
        } catch (InvalidDispenserReportException $e) {
            // Warning, not error: the terminal is fine and the sync succeeded.
            // What has stopped is the panel's view of one peripheral, and the
            // reason has to be nameable when somebody asks why the cell froze.
            $this->logger->warning('Dispenser status report dropped', [
                'terminal_id' => $terminalId,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }

        // One clock read for both stamps, at two precisions. The column is a
        // DATETIME and holds seconds; `state_since` keeps the milliseconds,
        // because a terminal reports a state change immediately and two
        // episodes can land in the same second — at second precision the
        // second one would inherit the first one's *since …* and the panel
        // would date a jam to the moment the machine was still idle.
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $receivedAt = $now->format('Y-m-d H:i:s');

        $previous = $this->terminals->findDispenserStatus($terminalId);
        $stateSince = self::stateSince($report, $previous, $now);

        $this->terminals->updateDispenserStatus(
            $terminalId,
            (string) json_encode($report->toStoredDocument($stateSince), JSON_UNESCAPED_UNICODE),
            $receivedAt,
        );

        return true;
    }

    /**
     * @throws InvalidDispenserReportException
     */
    private static function parse(mixed $parsedBody, ?int $bodyBytes): DispenserStatusReport
    {
        if ($bodyBytes !== null && $bodyBytes > DispenserStatusReport::MAX_BODY_BYTES) {
            throw new InvalidDispenserReportException(
                'body exceeds ' . DispenserStatusReport::MAX_BODY_BYTES . ' bytes',
            );
        }

        if (!is_array($parsedBody) || array_is_list($parsedBody)) {
            throw new InvalidDispenserReportException('body must be a JSON object');
        }

        if (!array_key_exists('dispenser', $parsedBody)) {
            // An envelope rather than a bare document, because a terminal
            // reports *itself* here and the dispenser is the first of possibly
            // several peripherals — ADR-0057's whole reason for being a new
            // route rather than a second version of the version header.
            throw new InvalidDispenserReportException('body has no dispenser object');
        }

        return DispenserStatusReport::fromWire($parsedBody['dispenser']);
    }

    /**
     * When the state the report describes began.
     *
     * The same episode keeps its original stamp however often it is re-reported
     * — a jam re-sent every thirty seconds is one fault that started an hour
     * ago, not a fault that started thirty seconds ago — and a different one
     * starts now.
     *
     * *Now* is the backend's clock, not the device's `observed_at`. A kiosk
     * whose clock is a year out would otherwise date a fault to next spring,
     * and every surface downstream (the panel's *since …*, #956's dedup key)
     * reads this as an age.
     *
     * @param array<string, mixed>|null $previous
     */
    private static function stateSince(
        DispenserStatusReport $report,
        ?array $previous,
        \DateTimeImmutable $now,
    ): string {
        $carried = $previous['state_since'] ?? null;

        if (
            is_array($previous)
            && is_string($carried)
            && DispenserStatusReport::episodeKeyOf($previous) === $report->episodeKey()
        ) {
            return $carried;
        }

        return $now->format('Y-m-d\TH:i:s.v\Z');
    }
}
