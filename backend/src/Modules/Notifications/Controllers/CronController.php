<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Controllers;

use App\Modules\Notifications\Enums\DrainSource;
use App\Modules\Notifications\Services\DrainService;
use App\Shared\Config\AppConfig;
use App\Shared\Logging\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The URL trigger for the drain — the fallback for tariffs with no CLI cron
 * (ADR-0038 rule 3).
 *
 * `bin/cron.php` is the preferred trigger and this is not a second sending
 * path: both call the same `DrainService`, and this one exists because some
 * panels can only schedule "fetch a URL", and because an external scheduler
 * calling it is what keeps the supported hosting set from narrowing.
 *
 * ### The secret
 *
 * A dedicated one — `cron.secret` in `config.php`, never the install key, never
 * an admin session. Rotating it is one edit to that file and takes effect on
 * the next request; nothing derives from it and nothing caches it. Absent, the
 * route is not mounted at all, so an installation on a CLI cron does not carry
 * an extra unauthenticated entrance it never asked for.
 *
 * **The header is the supported form.** `X-Cron-Secret: …` keeps the credential
 * out of the request line, which is the part every webserver writes to its
 * access log verbatim, keeps it out of `Referer` headers, and keeps it out of
 * proxy logs on the way.
 *
 * The query-string form is supported because some panels genuinely cannot send
 * a header, and it is **degraded**, in exactly the way `?key=` in `install.php`
 * was degraded before it was removed (`plans/2026-03-17-install-php-security-hardening.md`,
 * problem A). What this class can do about that, it does: the secret is never
 * written to the application log, and `$_SERVER['QUERY_STRING']` /
 * `REQUEST_URI` are overwritten before the drain runs, which covers loggers
 * that read the request environment at the end of a request (PHP-FPM's
 * `access.log` among them). What it cannot do is reach a webserver that already
 * wrote the original request line to disk — Apache's `%r` is taken from its own
 * memory. That is the honest boundary, and it is why the header form is the one
 * the installer prints.
 *
 * ### The response
 *
 * 204, always, with no body. The caller is a scheduler: it cannot act on
 * counts, and a body would put the state of the club's announcement queue
 * behind a single static credential. What the run did goes to `cron_heartbeat`
 * and the application log, both of which are read by an authenticated admin.
 */
class CronController
{
    /** The supported way to present the secret. */
    public const HEADER = 'X-Cron-Secret';

    /** The degraded way. Same name in the query string. */
    public const QUERY_PARAM = 'secret';

    public function __construct(
        private DrainService $drainService,
        private AppConfig $config,
        private Logger $logger,
    ) {}

    public function drain(Request $request, Response $response): Response
    {
        $configured = $this->config->cronSecret;

        if ($configured === null) {
            // Not "forbidden" — on this installation there is no such endpoint.
            // Saying so plainly is also the answer the admin needs: the URL
            // trigger is off until `cron.secret` is set.
            return $response->withStatus(404);
        }

        [$provided, $fromQuery] = $this->extractSecret($request);

        if ($fromQuery) {
            $this->scrubQueryString();
        }

        if ($provided === '' || !hash_equals($configured, $provided)) {
            // The secret itself is never logged, right or wrong: a mistyped one
            // is usually the real one with a character missing.
            $this->logger->warning('Cron drain rejected', [
                'reason' => $provided === '' ? 'missing secret' : 'wrong secret',
                'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
                'presented_in' => $fromQuery ? 'query' : 'header',
            ]);

            return $response->withStatus(401);
        }

        if ($fromQuery) {
            $this->logger->warning(
                'Cron drain authorised by query string. The secret is in this host\'s access log; '
                . 'use the ' . self::HEADER . ' header where the panel allows it.'
            );
        }

        $lock = DrainService::lockIn($this->config->storageDir);

        try {
            if (!$lock->acquire()) {
                // A run is already in progress — the previous tick, or the CLI
                // cron. Not an error: the queue is being drained by somebody.
                $this->logger->info('Cron drain skipped: another run holds the lock');

                return $response->withStatus(204);
            }

            $this->drainService->run(DrainSource::URL);
        } catch (\Throwable $e) {
            // The drain does not throw; the lock can, on a data directory the
            // web user cannot write. Either way the scheduler gets a 204 — it
            // has nothing to do with the answer, and a 500 in a panel's cron
            // report is a mail to somebody who cannot fix it.
            $this->logger->error('Cron drain failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }

        return $response->withStatus(204);
    }

    /**
     * @return array{0: string, 1: bool} The presented secret, and whether it came from the query string.
     */
    private function extractSecret(Request $request): array
    {
        $header = trim($request->getHeaderLine(self::HEADER));
        if ($header !== '') {
            return [$header, false];
        }

        $query = $request->getQueryParams()[self::QUERY_PARAM] ?? null;
        if (is_string($query) && trim($query) !== '') {
            return [trim($query), true];
        }

        // Nothing was presented at all. Reported as a header attempt so the log
        // line for "missing secret" does not claim a query string that was
        // never there.
        return ['', false];
    }

    /**
     * Replace the secret in the request environment this process still exposes.
     *
     * Partial by nature — see the class docblock. It covers what reads
     * `$_SERVER` after the handler (FPM's access log, `error_log` entries built
     * from the request, a crash dump) and nothing that was written before PHP
     * was reached.
     */
    private function scrubQueryString(): void
    {
        $replacement = self::QUERY_PARAM . '=***';
        // `QUERY_STRING` starts with the parameter; in `REQUEST_URI` it follows
        // a `?` or an `&`. Both shapes, and only the whole parameter name — a
        // `secretkey=` belonging to something else must survive intact.
        $pattern = '/(^|[?&])' . preg_quote(self::QUERY_PARAM, '/') . '=[^&]*/';

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
