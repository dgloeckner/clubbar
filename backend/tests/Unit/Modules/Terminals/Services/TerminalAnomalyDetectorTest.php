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

    // --- IPv6 /64 collapsing ---------------------------------------------------

    /**
     * A single dual-stack device on one line rotates its IPv6 privacy address
     * (RFC 4941) while staying in the same /64. Raw-string grouping saw that
     * as two addresses and, given enough uptime, a same-network "overlap".
     */
    public function testRotatingAddressesInTheSameIpv6SlashSixtyFourDoNotOverlap(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([
            $this->interval('2003:fb:6f09:c200:aaaa:bbbb:cccc:0001', '2026-08-15 18:00:00', '2026-08-15 19:30:00'),
            $this->interval('2003:fb:6f09:c200:1111:2222:3333:4444', '2026-08-15 19:00:00', '2026-08-15 21:00:00'),
        ]);

        $this->anomalies->expects($this->never())->method('open');

        $this->assertSame(0, $this->detector()->run()->opened);
    }

    /**
     * Two distinct /64s overlapping is still exactly what the detector exists
     * to catch, even though both addresses are IPv6.
     */
    public function testDifferentIpv6SlashSixtyFoursStillOverlap(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([
            $this->interval('2003:fb:6f09:c200::1', '2026-08-15 18:00:00', '2026-08-15 20:00:00'),
            $this->interval('2001:db8:dead:beef::1', '2026-08-15 19:00:00', '2026-08-15 21:00:00'),
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

        $this->assertSame(1, $this->detector()->run()->opened);
    }

    /**
     * The reported false positive (a real alert, reproduced address for
     * address): one terminal on a German consumer line, answering over IPv4
     * for part of the evening and over IPv6 for the rest, spending the whole
     * time inside a single 60-second polling cycle. Two addresses, one loop's
     * worth of requests between them — one device.
     */
    public function testDualStackPairAtOneClientsCadenceStaysQuiet(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([
            $this->interval('93.223.99.144', '2026-08-19 17:20:05', '2026-08-19 18:12:04', 150),
            $this->interval('2003:fb:6f09:c200:8aa2:9eff:fe9c:afdc', '2026-08-19 17:26:04', '2026-08-19 18:14:04', 66),
        ]);

        $this->anomalies->expects($this->never())->method('open');

        $this->assertSame(0, $this->detector()->run()->opened);
    }

    /**
     * The same shape carrying two polling loops is the case the exemption must
     * not swallow: a second device does not redistribute the terminal's
     * traffic, it adds its own on top.
     */
    public function testDualStackPairCarryingTwoClientsWorthOfTrafficStillAlerts(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([
            $this->interval('93.223.99.144', '2026-08-19 17:20:05', '2026-08-19 18:12:04', 430),
            $this->interval('2003:fb:6f09:c200:8aa2:9eff:fe9c:afdc', '2026-08-19 17:26:04', '2026-08-19 18:14:04', 420),
        ]);

        $this->anomalies->method('findOpen')->willReturn(null);
        $this->anomalies->expects($this->once())
            ->method('open')
            ->with(
                $this->anything(),
                'terminal-1',
                TerminalAnomalyKind::CONCURRENT_IP,
                $this->callback(fn(array $d) => $d['distinct_ips'] === 2),
                $this->anything(),
            );

        $this->assertSame(1, $this->detector()->run()->opened);
    }

    /**
     * Being dual-stack explains one address of each family and no more. A
     * third network is unexplained however quiet the traffic is, so the
     * exemption does not apply and the terminal is reported.
     */
    public function testDualStackPairAlongsideAThirdNetworkStillAlerts(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([
            $this->interval('93.223.99.144', '2026-08-19 17:20:05', '2026-08-19 18:12:04', 100),
            $this->interval('2003:fb:6f09:c200:8aa2:9eff:fe9c:afdc', '2026-08-19 17:26:04', '2026-08-19 18:14:04', 60),
            $this->interval('198.51.100.7', '2026-08-19 17:30:00', '2026-08-19 18:14:00', 56),
        ]);

        $this->anomalies->method('findOpen')->willReturn(null);
        $this->anomalies->expects($this->once())
            ->method('open')
            ->with(
                $this->anything(),
                'terminal-1',
                TerminalAnomalyKind::CONCURRENT_IP,
                $this->callback(fn(array $d) => $d['distinct_ips'] === 3),
                $this->anything(),
            );

        $this->assertSame(1, $this->detector()->run()->opened);
    }

    /**
     * Two addresses of the *same* family are two networks whatever the volume:
     * nothing about one client explains them, so the cadence test never runs.
     */
    public function testTwoIpv4AddressesAreNotExemptedByLowVolume(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([
            $this->interval('203.0.113.10', '2026-08-19 17:20:00', '2026-08-19 18:12:00', 8),
            $this->interval('198.51.100.7', '2026-08-19 17:26:00', '2026-08-19 18:14:00', 6),
        ]);

        $this->anomalies->method('findOpen')->willReturn(null);
        $this->anomalies->expects($this->once())->method('open');

        $this->assertSame(1, $this->detector()->run()->opened);
    }

    /**
     * When a /64 group is reported, the label says "rotating addresses"
     * rather than picking one raw address to stand in for the group — a
     * reviewer should not have to guess that more than one string was merged.
     */
    public function testCollapsedIpv6GroupIsLabelledAsRotatingAddresses(): void
    {
        $this->sightings = $this->createMock(TerminalIpSightingsRepository::class);
        $this->sightings->method('activeIntervalsSince')->willReturn([
            $this->interval('2003:fb:6f09:c200:aaaa::1', '2026-08-15 18:00:00', '2026-08-15 19:00:00', 5),
            $this->interval('2003:fb:6f09:c200:bbbb::1', '2026-08-15 18:30:00', '2026-08-15 19:30:00', 4),
            $this->interval('2001:db8:dead:beef::1', '2026-08-15 18:15:00', '2026-08-15 20:00:00', 30),
        ]);

        $this->anomalies->method('findOpen')->willReturn(null);
        $this->anomalies->expects($this->once())
            ->method('open')
            ->with(
                $this->anything(),
                'terminal-1',
                TerminalAnomalyKind::CONCURRENT_IP,
                $this->callback(function (array $d): bool {
                    if ($d['distinct_ips'] !== 2) {
                        return false;
                    }

                    $rotating = array_values(array_filter(
                        $d['ips'],
                        fn(array $ip) => str_contains((string) $ip['ip_address'], 'rotating addresses'),
                    ));

                    if (count($rotating) !== 1) {
                        return false;
                    }

                    return $rotating[0]['ip_address'] === '2003:fb:6f09:c200::/64 (2 rotating addresses)'
                        && $rotating[0]['request_count'] === 9;
                }),
                $this->anything(),
            );

        $this->assertSame(1, $this->detector()->run()->opened);
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
