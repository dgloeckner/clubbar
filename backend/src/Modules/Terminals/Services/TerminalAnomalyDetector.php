<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Services;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Terminals\DTOs\AnomalyScanResultDto;
use App\Modules\Terminals\Enums\TerminalAnomalyKind;
use App\Modules\Terminals\Repositories\TerminalAnomaliesRepository;
use App\Modules\Terminals\Repositories\TerminalIpSightingsRepository;
use App\Modules\Terminals\Repositories\TerminalSyncCursorsRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use App\Shared\Utils\Uuid;

/**
 * Turns terminal observations into anomalies, and anomalies into an alert
 * somebody will see (ADR-0041 §2).
 *
 * Runs on the existing scheduler tick, before the mail drain, so an alert
 * raised here leaves in the same tick. It never blocks, revokes, or
 * rate-limits: the output is a row, a banner, and a mail.
 *
 * Why this is a periodic job and not a request-time check, when almost every
 * other periodic concern in this codebase is computed at request time: the
 * sustained-overlap rule is a question about a *window*. Answering it on each
 * of ~300 requests per terminal per hour would mean the same aggregate scan
 * 300 times over. The cursor comparison is the opposite case and does happen at
 * request time, in {@see TerminalSyncCursorService}, because the presented
 * cursor exists nowhere else.
 */
class TerminalAnomalyDetector
{
    /**
     * How far back a scan looks. Comfortably more than the widest sensible cron
     * interval, so a tick that is skipped or runs late does not leave a blind
     * spot — rescanning ground already covered is free, because an anomaly
     * still true is refreshed rather than reopened.
     */
    public const DEFAULT_LOOKBACK_MINUTES = 60;

    /**
     * How much genuine overlap between two addresses counts as concurrent use.
     *
     * The number has to clear the handover case rather than the steady case: an
     * ISP reconnect stops one address and starts another with an overlap at or
     * near zero, while two devices working one evening overlap for hours.
     * Fifteen minutes sits far from both.
     */
    public const DEFAULT_MIN_OVERLAP_MINUTES = 15;

    /** How long a source IP is kept (ADR-0041 §5). */
    public const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        private TerminalIpSightingsRepository $sightings,
        private TerminalSyncCursorsRepository $cursors,
        private TerminalAnomaliesRepository $anomalies,
        private AdminNotifier $adminNotifier,
        private AuditService $auditService,
        private Logger $logger,
        private int $lookbackMinutes = self::DEFAULT_LOOKBACK_MINUTES,
        private int $minOverlapMinutes = self::DEFAULT_MIN_OVERLAP_MINUTES,
        private int $retentionDays = self::DEFAULT_RETENTION_DAYS,
    ) {}

    /**
     * Never throws. The caller is the cron tick, whose other job is draining
     * the mail queue — a detector that could not read a table must not stop
     * announcements from going out.
     */
    public function run(?int $now = null): AnomalyScanResultDto
    {
        $now ??= time();
        $startedAt = microtime(true);

        $opened = 0;
        $refreshed = 0;
        $mailsQueued = 0;
        $pruned = 0;
        $terminals = [];

        try {
            $since = date('Y-m-d H:i:s', $now - ($this->lookbackMinutes * 60));

            foreach ($this->scanConcurrentIps($since) as $finding) {
                $terminals[$finding['terminal_id']] = true;
                $outcome = $this->raise($finding['terminal_id'], TerminalAnomalyKind::CONCURRENT_IP, $finding['details'], $now);
                $opened += $outcome['opened'];
                $refreshed += $outcome['refreshed'];
                $mailsQueued += $outcome['mails'];
            }

            foreach ($this->scanCursorAnomalies($since) as $finding) {
                $terminals[$finding['terminal_id']] = true;
                $outcome = $this->raise($finding['terminal_id'], $finding['kind'], $finding['details'], $now);
                $opened += $outcome['opened'];
                $refreshed += $outcome['refreshed'];
                $mailsQueued += $outcome['mails'];
            }

            $pruned = $this->sightings->pruneOlderThan(
                date('Y-m-d H:i:s', $now - ($this->retentionDays * 86400))
            );
        } catch (\Throwable $e) {
            $this->logger->error('Terminal anomaly scan failed', ['error' => $e->getMessage()]);
        }

        return new AnomalyScanResultDto(
            terminalsExamined: count($terminals),
            opened: $opened,
            refreshed: $refreshed,
            mailsQueued: $mailsQueued,
            sightingsPruned: $pruned,
            durationSeconds: microtime(true) - $startedAt,
        );
    }

    /**
     * Terminals whose addresses were active at the same time.
     *
     * The intervals arrive already collapsed to one per (terminal, address),
     * but "address" is a raw string, and a single dual-stack client is not one
     * address: IPv6 privacy extensions (RFC 4941) and SLAAC rotate the low 64
     * bits every so often while the network stays put. Left as raw strings,
     * that rotation alone produces a second "distinct" address and — given
     * enough time — a same-network pair that clears the overlap threshold.
     * {@see collapseByNetwork} merges sightings that share an IPv6 /64 (an
     * IPv4 address is its own network; nothing there rotates the same way)
     * before the pairwise comparison runs, so the finding is about distinct
     * networks, not distinct strings.
     *
     * This does not, and must not, collapse an IPv4 address with an IPv6
     * address just because a legitimate dual-stack client could produce that
     * exact pair: an attacker reachable only over IPv6 riding alongside a
     * legitimate IPv4 session produces the identical shape. That case still
     * alerts — {@see describeNetwork} only changes how a *rotating* address is
     * labelled, never whether a genuine second network is reported.
     *
     * For a terminal with more than one network, the widest pairwise overlap
     * is the finding — reporting every pair would turn one evening of a
     * cloned till into a list nobody reads.
     *
     * @return list<array{terminal_id: string, details: array<string, mixed>}>
     */
    private function scanConcurrentIps(string $since): array
    {
        $byTerminal = [];
        foreach ($this->sightings->activeIntervalsSince($since) as $row) {
            $byTerminal[$row['terminal_id']][] = $row;
        }

        $threshold = $this->minOverlapMinutes * 60;
        $findings = [];

        foreach ($byTerminal as $terminalId => $intervals) {
            $networks = self::collapseByNetwork($intervals);

            if (count($networks) < 2) {
                continue;
            }

            $worst = null;

            for ($i = 0; $i < count($networks); $i++) {
                for ($j = $i + 1; $j < count($networks); $j++) {
                    $overlap = self::overlapSeconds($networks[$i], $networks[$j]);

                    if ($overlap >= $threshold && ($worst === null || $overlap > $worst['overlap_seconds'])) {
                        $worst = [
                            'overlap_seconds' => $overlap,
                            'a' => $networks[$i],
                            'b' => $networks[$j],
                        ];
                    }
                }
            }

            if ($worst === null) {
                continue;
            }

            $findings[] = [
                'terminal_id' => (string) $terminalId,
                'details' => [
                    'overlap_seconds' => $worst['overlap_seconds'],
                    'distinct_ips' => count($networks),
                    'ips' => [
                        self::describeNetwork($worst['a']),
                        self::describeNetwork($worst['b']),
                    ],
                ],
            ];
        }

        return $findings;
    }

    /**
     * Merge sightings that share an IPv6 /64 into one network.
     *
     * @param list<array{ip_address: string, first_seen_at: string, last_seen_at: string, request_count: int}> $intervals
     * @return list<array{ip_address: string, addresses: list<string>, first_seen_at: string, last_seen_at: string, request_count: int}>
     */
    private static function collapseByNetwork(array $intervals): array
    {
        $groups = [];

        foreach ($intervals as $row) {
            $key = self::networkKey($row['ip_address']);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'ip_address' => $row['ip_address'],
                    'addresses' => [$row['ip_address']],
                    'first_seen_at' => $row['first_seen_at'],
                    'last_seen_at' => $row['last_seen_at'],
                    'request_count' => $row['request_count'],
                ];

                continue;
            }

            $groups[$key]['addresses'][] = $row['ip_address'];
            // Lexical min/max is correct here because both columns are the
            // fixed-width 'Y-m-d H:i:s' the repository writes.
            $groups[$key]['first_seen_at'] = min($groups[$key]['first_seen_at'], $row['first_seen_at']);
            $groups[$key]['last_seen_at'] = max($groups[$key]['last_seen_at'], $row['last_seen_at']);
            $groups[$key]['request_count'] += $row['request_count'];
        }

        return array_values($groups);
    }

    /**
     * The network a sighting belongs to, for grouping.
     *
     * An IPv4 address is its own network — nothing rotates it the way IPv6
     * privacy addresses rotate. An unparseable value (should not happen; the
     * column only ever holds `REMOTE_ADDR`) falls back to the raw string, so a
     * malformed row degrades to today's behaviour instead of being silently
     * merged with something it may not belong with.
     */
    private static function networkKey(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        return 'v6:' . bin2hex(substr($packed, 0, 8));
    }

    /**
     * @param array{ip_address: string, addresses: list<string>, first_seen_at: string, last_seen_at: string, request_count: int} $network
     * @return array{ip_address: string, first_seen_at: string, last_seen_at: string, request_count: int}
     */
    private static function describeNetwork(array $network): array
    {
        $addresses = array_values(array_unique($network['addresses']));

        $label = count($addresses) > 1
            ? sprintf('%s (%d rotating addresses)', self::prefixLabel($addresses[0]), count($addresses))
            : $addresses[0];

        return [
            'ip_address' => $label,
            'first_seen_at' => $network['first_seen_at'],
            'last_seen_at' => $network['last_seen_at'],
            'request_count' => $network['request_count'],
        ];
    }

    /** The `/64` an IPv6 address belongs to, for display; any other input is returned unchanged. */
    private static function prefixLabel(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        $prefix = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));

        return $prefix === false ? $ip : $prefix . '/64';
    }

    /**
     * Streams whose cursor went backwards.
     *
     * A presented value of zero is its own kind: it is what a caller holding
     * only the token looks like, having no cursor to present — and equally what
     * a freshly re-provisioned terminal looks like. The two are
     * indistinguishable from here, which is the reason this alerts rather than
     * acts.
     *
     * @return list<array{terminal_id: string, kind: TerminalAnomalyKind, details: array<string, mixed>}>
     */
    private function scanCursorAnomalies(string $since): array
    {
        $findings = [];

        foreach ($this->cursors->regressionsSince($since) as $row) {
            $kind = $row['last_regression_to'] === 0
                ? TerminalAnomalyKind::CURSOR_RESET
                : TerminalAnomalyKind::CURSOR_REGRESSION;

            $findings[] = [
                'terminal_id' => $row['terminal_id'],
                'kind' => $kind,
                'details' => [
                    'stream' => $row['stream'],
                    'high_water_cursor' => $row['last_regression_from'],
                    'presented_cursor' => $row['last_regression_to'],
                    'observed_at' => $row['last_regression_at'],
                    'regressions_total' => $row['regression_count'],
                ],
            ];
        }

        return $findings;
    }

    /**
     * Open an anomaly, or refresh the one already open.
     *
     * @return array{opened: int, refreshed: int, mails: int}
     */
    private function raise(string $terminalId, TerminalAnomalyKind $kind, array $details, int $now): array
    {
        $open = $this->anomalies->findOpen($terminalId, $kind);

        if ($open !== null) {
            $this->anomalies->touch($open['id'], $details, $now);

            return ['opened' => 0, 'refreshed' => 1, 'mails' => 0];
        }

        $anomalyId = Uuid::v4();
        $this->anomalies->open($anomalyId, $terminalId, $kind, $details, $now);

        $this->logger->warning('Terminal anomaly detected', [
            'terminal_id' => $terminalId,
            'anomaly_id' => $anomalyId,
            'kind' => $kind->value,
            'details' => $details,
        ]);

        // No admin acted here — the tick did. `admin_user_id` is nullable and
        // this path has precedent: PairingService writes terminal_repair the
        // same way, as does the token authenticator for activation and expiry.
        $this->auditService->log(
            action: AuditAction::TERMINAL_ANOMALY_DETECTED,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            newValues: ['anomaly_id' => $anomalyId, 'kind' => $kind->value] + $details,
            adminUserId: null,
        );

        return ['opened' => 1, 'refreshed' => 0, 'mails' => $this->mail($terminalId, $kind, $anomalyId)];
    }

    /**
     * Queue the warning to every active admin.
     *
     * The occasion carries only the first eight characters of the anomaly id,
     * and that is a hard constraint rather than a preference: `warnAdmins()`
     * builds the outbox dedup key as `occasion:adminUserId`, the column is
     * VARCHAR(64), and an admin id is 36 of those. The longest kind is
     * `cursor_regression` at 17, so 17 + 1 + 8 + 1 + 36 = 63 — the full id
     * would overflow and collapse two anomalies into one message.
     */
    private function mail(string $terminalId, TerminalAnomalyKind $kind, string $anomalyId): int
    {
        try {
            $result = $this->adminNotifier->warnAdmins(
                MailKind::TERMINAL_ANOMALY_WARNING,
                $terminalId,
                $kind->value . ':' . substr($anomalyId, 0, 8),
            );

            return $result->queued;
        } catch (\Throwable $e) {
            // The anomaly is already recorded and already on the dashboard. A
            // mail that could not be queued must not lose the detection.
            $this->logger->error('Could not queue terminal anomaly warning', [
                'terminal_id' => $terminalId,
                'anomaly_id' => $anomalyId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Seconds two activity intervals were both live.
     *
     * Zero or negative means they did not overlap — which is what a clean
     * handover between two networks looks like, and why this is the arithmetic
     * the rule is built on rather than "two addresses in the same window".
     *
     * @param array{first_seen_at: string, last_seen_at: string} $a
     * @param array{first_seen_at: string, last_seen_at: string} $b
     */
    public static function overlapSeconds(array $a, array $b): int
    {
        $start = max(strtotime($a['first_seen_at']), strtotime($b['first_seen_at']));
        $end = min(strtotime($a['last_seen_at']), strtotime($b['last_seen_at']));

        return max(0, $end - $start);
    }
}
