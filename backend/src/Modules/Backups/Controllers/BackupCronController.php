<?php

declare(strict_types=1);

namespace App\Modules\Backups\Controllers;

use App\Modules\Backups\Services\BackupService;
use App\Modules\Notifications\Controllers\CronController;
use App\Modules\Notifications\Services\MailConfigService;
use App\Shared\Config\AppConfig;
use App\Shared\Logging\Logger;
use App\Shared\Process\FileLock;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The URL trigger for the backup — the fallback for tariffs with no CLI cron.
 *
 * `bin/backup.php` is the preferred trigger and this is not a second backup
 * path: both call the same {@see BackupService}. This exists because some
 * panels can only schedule "fetch a URL", and on the hosting ADR-0031 targets
 * that is a large fraction of them.
 *
 * ### It reuses the drain's secret, and that is deliberate
 *
 * One credential, one rotation, one thing to paste. Two secrets would mean an
 * installation that rotated one and not the other, with the failure showing up
 * as a job that silently stopped — which is the class of failure this whole
 * epic exists to prevent. {@see MailConfigService} owns the precedence (a
 * panel-rotated hash if one exists, otherwise `cron.secret` from `config.php`),
 * and both routes answer **404 when neither is configured**: an installation on
 * a CLI cron gains no unauthenticated entrance it never asked for.
 *
 * ### Two ways to be unconfigured, and both are 404
 *
 * No cron secret, or no recipient key. The second is new with #703 and follows
 * from the on-switch: configuring `backup.recipient_public_keys` is what turns
 * backups on (ADR-0049 decision 2), so an installation that has not done it has
 * no backup endpoint either — rather than one that answers 204 and writes
 * nothing, which is indistinguishable from a working backup to the panel
 * calling it.
 *
 * ### Why this endpoint is treated as heavier than the drain
 *
 * Draining a queue sends what was already going to be sent. Hitting *this* one
 * produces and stores a **database dump** — it costs CPU on a shared tariff and
 * webspace that logging and mandate storage also need. So on top of the shared
 * secret:
 *
 *   - **204 with an empty body, always.** It triggers; it never serves an
 *     archive. Not even a count: putting the state of the club's backups behind
 *     one static credential is the wrong trade, and a scheduler cannot act on
 *     the answer anyway.
 *   - **A minimum interval**, enforced in the service
 *     ({@see BackupService::MINIMUM_INTERVAL_MINUTES}), so a caller in a loop
 *     cannot fill the quota with dumps. It is keyed on *attempts*, not
 *     successes — the quota is spent by an attempt.
 *   - **`--force` has no URL equivalent.** An operator who wants a run now runs
 *     `bin/backup.php --force`; a static credential must not be able to bypass
 *     the guard that protects the quota.
 *
 * Part of #690 and #703, epic #686.
 */
class BackupCronController
{
    public function __construct(
        private BackupService $backupService,
        private AppConfig $config,
        private Logger $logger,
        private MailConfigService $mailConfigService,
    ) {}

    public function trigger(Request $request, Response $response): Response
    {
        if (!$this->mailConfigService->cronSecretConfigured() || !$this->backupService->isConfigured()) {
            // Not "forbidden" — on this installation there is no such endpoint.
            // Checked before the secret is even read, so an unconfigured
            // installation cannot be probed for whether its secret was right.
            return $response->withStatus(404);
        }

        [$provided, $fromQuery] = $this->extractSecret($request);

        if ($fromQuery) {
            $this->scrubQueryString();
        }

        if (!$this->mailConfigService->verifyCronSecret($provided)) {
            $this->logger->warning('Cron backup rejected', [
                'reason' => $provided === '' ? 'missing secret' : 'wrong secret',
                'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
                'presented_in' => $fromQuery ? 'query' : 'header',
            ]);

            return $response->withStatus(401);
        }

        if ($fromQuery) {
            $this->logger->warning(
                'Cron backup authorised by query string. The secret is in this host\'s access log; '
                . 'use the ' . CronController::HEADER . ' header where the panel allows it.'
            );
        }

        // Its own lock, the same one bin/backup.php takes. Sharing the drain's
        // would let two unrelated jobs block each other, which is precisely
        // what separating them was for.
        $lock = new FileLock(rtrim($this->config->storageDir, '/') . '/backup.lock');

        try {
            if (!$lock->acquire()) {
                $this->logger->info('Cron backup skipped: another run holds the lock');

                return $response->withStatus(204);
            }

            $outcome = $this->backupService->run('url');

            // Logged here as well as journalled, because a run skipped for the
            // interval appends no journal line at all — and "nothing happened
            // and nothing was recorded" is indistinguishable from a request
            // that never arrived.
            $this->logger->info('Cron backup finished', [
                'status' => $outcome->status,
                'summary' => $outcome->summary,
                'findings' => count($outcome->findings),
            ]);
        } catch (\Throwable $e) {
            // BackupService does not throw; the lock can, on a data directory
            // the web user cannot write. Either way the scheduler gets a 204 —
            // a 500 in a panel's cron report is a mail to somebody who cannot
            // fix it.
            $this->logger->error('Cron backup failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }

        return $response->withStatus(204);
    }

    /**
     * @return array{0: string, 1: bool} the presented secret, and whether it came from the query string
     */
    private function extractSecret(Request $request): array
    {
        $header = trim($request->getHeaderLine(CronController::HEADER));
        if ($header !== '') {
            return [$header, false];
        }

        $query = $request->getQueryParams()[CronController::QUERY_PARAM] ?? null;
        if (is_string($query) && trim($query) !== '') {
            return [trim($query), true];
        }

        return ['', false];
    }

    /**
     * Replace the secret in the request environment this process still exposes.
     *
     * Partial by nature, exactly as in {@see CronController}: it covers what
     * reads `$_SERVER` after the handler and nothing a webserver already wrote
     * from its own memory. That is why the header form is the one the installer
     * prints.
     */
    private function scrubQueryString(): void
    {
        $replacement = CronController::QUERY_PARAM . '=***';
        $pattern = '/(^|[?&])' . preg_quote(CronController::QUERY_PARAM, '/') . '=[^&]*/';

        foreach (['QUERY_STRING', 'REQUEST_URI'] as $key) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $_SERVER[$key] = preg_replace_callback(
                    $pattern,
                    static fn (array $m): string => $m[1] . $replacement,
                    $_SERVER[$key],
                );
            }
        }
    }
}
