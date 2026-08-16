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
     * The intervals arrive already collapsed to one per (terminal, address).
     * For a terminal with more than one, the widest pairwise overlap is the
     * finding — reporting every pair would turn one evening of a cloned till
     * into a list nobody reads.
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
            if (count($intervals) < 2) {
                continue;
            }

            $worst = null;

            for ($i = 0; $i < count($intervals); $i++) {
                for ($j = $i + 1; $j < count($intervals); $j++) {
                    $overlap = self::overlapSeconds($intervals[$i], $intervals[$j]);

                    if ($overlap >= $threshold && ($worst === null || $overlap > $worst['overlap_seconds'])) {
                        $worst = [
                            'overlap_seconds' => $overlap,
                            'a' => $intervals[$i],
                            'b' => $intervals[$j],
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
                    'distinct_ips' => count($intervals),
                    'ips' => [
                        [
                            'ip_address' => $worst['a']['ip_address'],
                            'first_seen_at' => $worst['a']['first_seen_at'],
                            'last_seen_at' => $worst['a']['last_seen_at'],
                            'request_count' => $worst['a']['request_count'],
                        ],
                        [
                            'ip_address' => $worst['b']['ip_address'],
                            'first_seen_at' => $worst['b']['first_seen_at'],
                            'last_seen_at' => $worst['b']['last_seen_at'],
                            'request_count' => $worst['b']['request_count'],
                        ],
                    ],
                ],
            ];
        }

        return $findings;
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
