<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Services;

use App\Modules\Notifications\DTOs\EnqueueResultDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Terminals\Enums\TerminalAnomalyKind;
use App\Modules\Terminals\Repositories\TerminalAnomaliesRepository;
use App\Modules\Terminals\Repositories\TerminalIpSightingsRepository;
use App\Modules\Terminals\Repositories\TerminalSyncCursorsRepository;
use App\Modules\Terminals\Services\TerminalAnomalyDetector;
use App\Shared\Enums\AuditAction;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * The detector's judgement (ADR-0041 §2).
 *
 * The cases that matter are the ones it must stay quiet for. A clubhouse on a
 * consumer line is re-addressed on a roughly daily forced reconnect, and if that
 * hands an admin an alarm every morning the whole mechanism gets ignored — so
 * the handover case is pinned here as firmly as the detection case.
 */
class TerminalAnomalyDetectorTest extends TestCase
{
    private TerminalIpSightingsRepository $sightings;
    private TerminalSyncCursorsRepository $cursors;
    private TerminalAnomaliesRepository $anomalies;
    private AdminNotifier $notifications;
    private AuditService $audit;

    protected function setUp(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->cursors = $this->createMock(TerminalSyncCursorsRepository::class);
        $this->anomalies = $this->createMock(TerminalAnomaliesRepository::class);
        $this->notifications = $this->createMock(AdminNotifier::class);
        $this->audit = $this->createMock(AuditService::class);

        $this->cursors->method('regressionsSince')->willReturn([]);
        $this->sightings->method('activeIntervalsSince')->willReturn([]);
        $this->notifications->method('warnAdmins')->willReturn(new EnqueueResultDto(1, []));
    }

    private function detector(): TerminalAnomalyDetector
    {
        return new TerminalAnomalyDetector(
            $this->sightings,
            $this->cursors,
            $this->anomalies,
            $this->notifications,
            $this->audit,
            $this->createMock(Logger::class),
        );
    }

    private function interval(string $ip, string $from, string $to, int $count = 10): array
    {
        return [
            'terminal_id' => 'terminal-1',
            'ip_address' => $ip,
            'first_seen_at' => $from,
            'last_seen_at' => $to,
            'request_count' => $count,
        ];
    }

    // --- overlap arithmetic ---------------------------------------------------

    public function testHandoverBetweenTwoAddressesDoesNotOverlap(): void
    {
        $overlap = TerminalAnomalyDetector::overlapSeconds(
            $this->interval('a', '2026-08-15 18:00:00', '2026-08-15 19:00:00'),
            $this->interval('b', '2026-08-15 19:00:00', '2026-08-15 20:00:00'),
        );

        $this->assertSame(0, $overlap);
    }

    public function testDisjointIntervalsReportZeroRatherThanANegativeNumber(): void
    {
        $overlap = TerminalAnomalyDetector::overlapSeconds(
            $this->interval('a', '2026-08-15 18:00:00', '2026-08-15 18:30:00'),
            $this->interval('b', '2026-08-15 20:00:00', '2026-08-15 21:00:00'),
        );

        $this->assertSame(0, $overlap);
    }

    public function testOverlapIsTheIntersectionOfTheTwoIntervals(): void
    {
        $overlap = TerminalAnomalyDetector::overlapSeconds(
            $this->interval('a', '2026-08-15 18:00:00', '2026-08-15 19:30:00'),
            $this->interval('b', '2026-08-15 19:00:00', '2026-08-15 21:00:00'),
        );

        $this->assertSame(1800, $overlap);
    }

    // --- what the scan does with it -------------------------------------------

    public function testCleanHandoverRaisesNothing(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([
            $this->interval('203.0.113.10', '2026-08-15 18:00:00', '2026-08-15 19:00:00'),
            $this->interval('203.0.113.11', '2026-08-15 19:00:00', '2026-08-15 20:00:00'),
        ]);

        $this->anomalies->expects($this->never())->method('open');

        $result = $this->detector()->run();

        $this->assertSame(0, $result->opened);
    }

    public function testSustainedOverlapIsRaisedAsConcurrentUse(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([
            $this->interval('203.0.113.10', '2026-08-15 18:00:00', '2026-08-15 20:00:00'),
            $this->interval('198.51.100.7', '2026-08-15 19:00:00', '2026-08-15 21:00:00'),
        ]);

        $this->anomalies->method('findOpen')->willReturn(null);
        $this->anomalies->expects($this->once())
            ->method('open')
            ->with(
                $this->anything(),
                'terminal-1',
                TerminalAnomalyKind::CONCURRENT_IP,
                $this->callback(fn(array $d) => $d['overlap_seconds'] === 3600 && $d['distinct_ips'] === 2),
                $this->anything(),
            );

        $result = $this->detector()->run();

        $this->assertSame(1, $result->opened);
    }

    /**
     * Below the threshold nothing is raised. Two addresses brushing past each
     * other for a few minutes is what a flapping uplink looks like.
     */
    public function testOverlapShorterThanTheThresholdIsIgnored(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([
            $this->interval('203.0.113.10', '2026-08-15 18:00:00', '2026-08-15 19:05:00'),
            $this->interval('198.51.100.7', '2026-08-15 19:00:00', '2026-08-15 20:00:00'),
        ]);

        $this->anomalies->expects($this->never())->method('open');

        $this->assertSame(0, $this->detector()->run()->opened);
    }

    public function testSingleAddressIsNeverConcurrent(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([
            $this->interval('203.0.113.10', '2026-08-15 18:00:00', '2026-08-15 23:00:00', 900),
        ]);

        $this->anomalies->expects($this->never())->method('open');

        $this->assertSame(0, $this->detector()->run()->opened);
    }

    // --- cursor findings ------------------------------------------------------

    public function testRegressionToANonZeroCursorIsARegression(): void
    {
        $this->cursors = $this->createMock(TerminalSyncCursorsRepository::class);
        $this->cursors->method('regressionsSince')->willReturn([[
            'terminal_id' => 'terminal-1',
            'stream' => 'members',
            'last_regression_at' => '2026-08-15 19:00:00',
            'last_regression_from' => 9_000,
            'last_regression_to' => 3_000,
            'regression_count' => 1,
        ]]);

        $this->anomalies->method('findOpen')->willReturn(null);
        $this->anomalies->expects($this->once())
            ->method('open')
            ->with($this->anything(), 'terminal-1', TerminalAnomalyKind::CURSOR_REGRESSION, $this->anything(), $this->anything());

        $this->assertSame(1, $this->detector()->run()->opened);
    }

    public function testRegressionToZeroIsAReset(): void
    {
        $this->cursors = $this->createMock(TerminalSyncCursorsRepository::class);
        $this->cursors->method('regressionsSince')->willReturn([[
            'terminal_id' => 'terminal-1',
            'stream' => 'products',
            'last_regression_at' => '2026-08-15 19:00:00',
            'last_regression_from' => 9_000,
            'last_regression_to' => 0,
            'regression_count' => 1,
        ]]);

        $this->anomalies->method('findOpen')->willReturn(null);
        $this->anomalies->expects($this->once())
            ->method('open')
            ->with($this->anything(), 'terminal-1', TerminalAnomalyKind::CURSOR_RESET, $this->anything(), $this->anything());

        $this->assertSame(1, $this->detector()->run()->opened);
    }

    // --- deduplication --------------------------------------------------------

    /**
     * An anomaly that is still true is refreshed, not reopened — otherwise a
     * cloned till running all evening mails the admins once per cron tick.
     */
    public function testOngoingAnomalyIsRefreshedAndDoesNotMailAgain(): void
    {
        $this->cursors = $this->createMock(TerminalSyncCursorsRepository::class);
        $this->cursors->method('regressionsSince')->willReturn([[
            'terminal_id' => 'terminal-1',
            'stream' => 'members',
            'last_regression_at' => '2026-08-15 19:00:00',
            'last_regression_from' => 9_000,
            'last_regression_to' => 3_000,
            'regression_count' => 4,
        ]]);

        $this->anomalies->method('findOpen')->willReturn([
            'id' => 'anomaly-1',
            'occurrence_count' => 3,
            'first_detected_at' => '2026-08-15 18:00:00',
        ]);

        $this->anomalies->expects($this->never())->method('open');
        $this->anomalies->expects($this->once())->method('touch');
        $this->notifications->expects($this->never())->method('warnAdmins');
        $this->audit->expects($this->never())->method('log');

        $result = $this->detector()->run();

        $this->assertSame(0, $result->opened);
        $this->assertSame(1, $result->refreshed);
        $this->assertSame(0, $result->mailsQueued);
    }

    public function testOpeningAnAnomalyAuditsItAndWarnsAdmins(): void
    {
        $this->cursors = $this->createMock(TerminalSyncCursorsRepository::class);
        $this->cursors->method('regressionsSince')->willReturn([[
            'terminal_id' => 'terminal-1',
            'stream' => 'members',
            'last_regression_at' => '2026-08-15 19:00:00',
            'last_regression_from' => 9_000,
            'last_regression_to' => 3_000,
            'regression_count' => 1,
        ]]);

        $this->anomalies->method('findOpen')->willReturn(null);

        $this->audit->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::TERMINAL_ANOMALY_DETECTED,
                $this->anything(),
                'terminal-1',
                $this->anything(),
                $this->anything(),
                null, // no admin acted — the tick did
            );

        $this->notifications->expects($this->once())
            ->method('warnAdmins')
            ->with(MailKind::TERMINAL_ANOMALY_WARNING, 'terminal-1', $this->anything());

        $this->detector()->run();
    }

    /**
     * The outbox dedup key is `occasion:adminUserId` in a VARCHAR(64), and an
     * admin id is 36 of those. If the occasion ever outgrows its budget the
     * column truncates and two different anomalies collapse into one message.
     */
    public function testMailOccasionFitsTheOutboxDedupKey(): void
    {
        $this->cursors = $this->createMock(TerminalSyncCursorsRepository::class);
        $this->cursors->method('regressionsSince')->willReturn([[
            'terminal_id' => 'terminal-1',
            'stream' => 'members',
            'last_regression_at' => '2026-08-15 19:00:00',
            'last_regression_from' => 9_000,
            'last_regression_to' => 3_000,
            'regression_count' => 1,
        ]]);

        $this->anomalies->method('findOpen')->willReturn(null);

        $this->notifications->expects($this->once())
            ->method('warnAdmins')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (string $occasion): bool {
                    // + ':' + a 36-character admin id must still fit in 64.
                    return strlen($occasion) + 1 + 36 <= 64;
                }),
            );

        $this->detector()->run();
    }

    // --- resilience -----------------------------------------------------------

    public function testScanFailureIsContainedSoTheMailDrainStillRuns(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willThrowException(new \RuntimeException('database gone'));

        $result = $this->detector()->run();

        $this->assertSame(0, $result->opened);
    }

    public function testMailFailureDoesNotLoseTheDetection(): void
    {
        $this->cursors = $this->createMock(TerminalSyncCursorsRepository::class);
        $this->cursors->method('regressionsSince')->willReturn([[
            'terminal_id' => 'terminal-1',
            'stream' => 'members',
            'last_regression_at' => '2026-08-15 19:00:00',
            'last_regression_from' => 9_000,
            'last_regression_to' => 3_000,
            'regression_count' => 1,
        ]]);

        $this->anomalies->method('findOpen')->willReturn(null);
        $this->notifications = $this->createMock(AdminNotifier::class);
        $this->notifications->method('warnAdmins')->willThrowException(new \RuntimeException('no transport'));

        $this->anomalies->expects($this->once())->method('open');

        $result = $this->detector()->run();

        $this->assertSame(1, $result->opened);
        $this->assertSame(0, $result->mailsQueued);
    }

    // --- retention ------------------------------------------------------------

    public function testSightingsArePruned(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([]);
        $this->sightings->expects($this->once())->method('pruneOlderThan')->willReturn(42);

        $this->assertSame(42, $this->detector()->run()->sightingsPruned);
    }
}
