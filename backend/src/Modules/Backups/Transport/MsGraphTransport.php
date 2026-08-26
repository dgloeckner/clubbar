<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

use App\Modules\Backups\Domain\BackupDsn;
use App\Shared\Http\HttpClient;
use App\Shared\Http\HttpResponse;
use App\Shared\Logging\Logger;
use Closure;

/**
 * Pushes sealed archives into the club's own Microsoft 365 tenant.
 *
 * Chosen because it is the storage a Verein of this size already pays for and
 * already administers — the alternative that needs no new vendor, no new
 * invoice and no new password in the safe. What it costs is stated in the open
 * (ADR-0049, #691): **there is no add-only app role on M365.** `Sites.Selected`
 * restricts *which* site, but the per-site role is a fixed `read`/`write` enum
 * and `write` includes delete, so the credential on the webspace can delete
 * what it wrote. The mitigations are library retention — which makes a delete
 * *recoverable*, not impossible, and only if the tenant allows it — and the
 * quarterly manual copy. `s3://` with object lock is what closes this properly,
 * and it is next.
 *
 * ## The conversation
 *
 * ```
 * POST  login.microsoftonline.com/{tenant}/oauth2/v2.0/token   client credentials
 * GET   graph/sites/{site}                                     → site id
 * GET   graph/sites/{site}/drives                              → the library's drive id
 * POST  graph/drives/{drive}/root:/{path}:/createUploadSession → an upload URL
 * PUT   {upload URL}  Content-Range: bytes a-b/total           → repeat until 201
 * ```
 *
 * The upload URL is pre-authorised and deliberately gets **no bearer token**:
 * it is a capability in its own right, it outlives the token that created it,
 * and attaching an expiring credential to it would break exactly the resume
 * this class exists to make possible.
 *
 * ## An upload session even for a small archive
 *
 * Graph would take a club-sized archive in a single `PUT`, and that path is not
 * used. One code path means the resumable one is the one that gets exercised
 * every night, rather than the one that runs for the first time on the night a
 * club's database finally crosses 4 MiB — which is the night it must not be
 * discovering a bug.
 *
 * Part of #691, epic #686.
 */
final class MsGraphTransport implements BackupTransport
{
    /**
     * 3.125 MiB. Graph requires a multiple of 320 KiB for every chunk but the
     * last, and rejects the whole session otherwise.
     */
    public const CHUNK_BYTES = 3276800;

    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    private const LOGIN = 'https://login.microsoftonline.com';

    /** Bounds one request. A whole run is bounded separately, by its budget. */
    private const TIMEOUT_SECONDS = 120;

    private const MAX_RETRIES = 3;
    private const FALLBACK_BACKOFF_SECONDS = 5;

    private readonly Closure $sleeper;

    private ?string $token = null;
    private ?string $driveId = null;

    /** Wall clock this run may still spend; `PHP_INT_MAX` outside a transfer. */
    private int $deadline = PHP_INT_MAX;

    /**
     * @param string $clientSecret never logged, never journalled, never in a summary
     * @param (callable(int): void)|null $sleeper the backoff seam — ADR-0038's rule that no
     *        test opens a socket would be worth little if every retry test took seven seconds
     */
    public function __construct(
        private readonly BackupDsn $dsn,
        private readonly string $clientSecret,
        private readonly HttpClient $http,
        private readonly Logger $logger,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper === null
            ? static function (int $seconds): void {
                sleep($seconds);
            }
            : Closure::fromCallable($sleeper);
    }

    public function describe(): string
    {
        return $this->dsn->describe();
    }

    public function upload(string $localPath, int $budgetSeconds): TransportResult
    {
        $this->deadline = time() + max(0, $budgetSeconds);
        $remotePath = $this->dsn->remotePathFor(basename($localPath));

        try {
            return $this->transfer($localPath, $remotePath);
        } catch (BackupTransportException $e) {
            $this->logger->error('Backup upload failed', [
                'remote' => $this->describe(),
                'archive' => basename($localPath),
                'message' => $e->getMessage(),
            ]);

            return TransportResult::failed($e->getMessage());
        } finally {
            $this->deadline = PHP_INT_MAX;
        }
    }

    public function list(): array
    {
        $folder = $this->dsn->path;
        $url = $folder === ''
            ? self::GRAPH . '/drives/' . $this->driveId() . '/root/children'
            : self::GRAPH . '/drives/' . $this->driveId() . '/root:/' . self::encodePath($folder) . ':/children';

        $archives = [];

        // Graph pages large folders, and a club that has been running for years
        // has more archives than one page. Following the link rather than
        // assuming one page is what keeps retention from silently considering
        // only the first fifty.
        while ($url !== null) {
            $response = $this->send('GET', $url);
            if (!$response->isSuccess()) {
                throw new BackupTransportException($this->explain(
                    'list ' . $this->describe(),
                    $response
                ));
            }

            $body = $response->json();
            foreach ($body['value'] ?? [] as $item) {
                $name = (string) ($item['name'] ?? '');

                // Only this job's own archives. A club keeps other things in
                // that folder — a note to a successor, a copy of the key
                // envelope — and nothing else may ever become a candidate for
                // the retention delete.
                if (!str_starts_with($name, 'clubbar-') || !str_ends_with($name, '.cbb')) {
                    continue;
                }

                $archives[] = new RemoteArchive(
                    (string) ($item['id'] ?? ''),
                    $name,
                    (int) ($item['size'] ?? 0),
                    $this->driveId(),
                );
            }

            $next = $body['@odata.nextLink'] ?? null;
            $url = is_string($next) && $next !== '' ? $next : null;
        }

        usort($archives, static fn (RemoteArchive $a, RemoteArchive $b): int => $a->name <=> $b->name);

        return $archives;
    }

    public function delete(RemoteArchive $archive): bool
    {
        $response = $this->send(
            'DELETE',
            self::GRAPH . '/drives/' . $archive->driveId . '/items/' . $archive->id
        );

        // 404 counts as gone: somebody deleting an archive by hand between the
        // listing and the delete has done what was about to be done anyway.
        if ($response->isSuccess() || $response->status === 404) {
            return true;
        }

        $this->logger->warning('Could not delete a remote backup archive', [
            'archive' => $archive->name,
            'status' => $response->status,
        ]);

        return false;
    }

    /**
     * @throws BackupTransportException
     */
    private function transfer(string $localPath, string $remotePath): TransportResult
    {
        $size = (int) @filesize($localPath);
        if ($size <= 0) {
            throw new BackupTransportException(
                'Refusing to upload ' . basename($localPath) . ': it is empty or unreadable.'
            );
        }

        $state = new UploadState($localPath);
        $session = $this->resumeOrCreate($state, $remotePath, $size);
        $offset = $session['offset'];
        $sent = 0;

        $handle = @fopen($localPath, 'rb');
        if ($handle === false) {
            throw new BackupTransportException('Cannot read ' . $localPath . '.');
        }

        try {
            while ($offset < $size) {
                $length = min(self::CHUNK_BYTES, $size - $offset);
                fseek($handle, $offset);
                $chunk = (string) fread($handle, $length);

                $response = $this->send(
                    'PUT',
                    $session['url'],
                    ['Content-Range' => sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $size)],
                    // The last fragment is simply the remainder, and must NOT
                    // be padded up to a 320 KiB multiple. Padding it with bytes
                    // from the head of the file — an answer that circulates on
                    // Microsoft Q&A — corrupts the archive, silently, in a way
                    // only a restore would discover.
                    $chunk,
                    // No bearer token, ever. The upload URL is pre-authorised
                    // and the request *fails* with an Authorization header
                    // attached; an HTTP client with default-header middleware
                    // does this to you silently.
                    authenticated: false,
                );

                if ($response->status === 200 || $response->status === 201) {
                    $state->clear();

                    return TransportResult::uploaded($remotePath, $sent + $length);
                }

                if ($response->status !== 202) {
                    throw new BackupTransportException($this->explain(
                        sprintf('upload bytes %d-%d of %s', $offset, $offset + $length - 1, $remotePath),
                        $response
                    ));
                }

                $sent += $length;
                // The server's own view wins over ours. They disagree exactly
                // when a chunk landed and its response was lost, and re-sending
                // a range Graph already holds earns a 416 rather than a shrug.
                $offset = self::nextExpectedFrom($response, $offset + $length);
                $state->write($session['url'], $session['expires'], $offset, $size);

                if ($offset < $size && time() >= $this->deadline) {
                    return TransportResult::partial($remotePath, $sent, $offset, $size);
                }
            }
        } catch (UploadBudgetExhaustedException) {
            return TransportResult::partial($remotePath, $sent, $offset, $size);
        } finally {
            fclose($handle);
        }

        // Every byte was acknowledged but Graph never sent the final 200/201.
        // The item may well be complete; saying so would be a guess, and a
        // guess here is a club believing an archive is off-site.
        throw new BackupTransportException(sprintf(
            'Sent all %s bytes of %s but the server never confirmed the finished file.',
            number_format($size),
            $remotePath
        ));
    }

    /**
     * @return array{url: string, expires: string, offset: int}
     * @throws BackupTransportException
     */
    private function resumeOrCreate(UploadState $state, string $remotePath, int $size): array
    {
        $pending = $state->read();

        if ($pending !== null && $pending->size === $size) {
            // No bearer token: the session URL is pre-authorised, and asking
            // it what it holds is both the resume point and the liveness check.
            $status = $this->send('GET', $pending->uploadUrl, authenticated: false);

            if ($status->isSuccess()) {
                return [
                    'url' => $pending->uploadUrl,
                    'expires' => gmdate('c', $pending->expiresAt),
                    'offset' => self::nextExpectedFrom($status, $pending->uploaded),
                ];
            }

            // Anything else means the session is gone — expired, cancelled, or
            // never as alive as the sidecar thought. Start again rather than
            // spend the run's budget proving it.
            $this->logger->info('The stored upload session is gone; starting a new one', [
                'archive' => basename($remotePath),
                'status' => $status->status,
            ]);
            $state->clear();
        }

        $session = $this->createUploadSession($remotePath);
        // Written before the first byte moves, so a run cut off during its very
        // first chunk still leaves a session to resume rather than orphaning one
        // on the tenant.
        $state->write($session['url'], $session['expires'], 0, $size);

        return $session + ['offset' => 0];
    }

    /**
     * @return array{url: string, expires: string}
     * @throws BackupTransportException
     */
    private function createUploadSession(string $remotePath): array
    {
        $response = $this->send(
            'POST',
            self::GRAPH . '/drives/' . $this->driveId() . '/root:/' . self::encodePath($remotePath)
                . ':/createUploadSession',
            ['Content-Type' => 'application/json'],
            // `replace` rather than `fail`: a re-upload after a night that died
            // between the last chunk and the confirming response must converge,
            // and the archive name already carries a random suffix, so the only
            // thing it can ever replace is a previous attempt at itself.
            (string) json_encode(['item' => ['@microsoft.graph.conflictBehavior' => 'replace']]),
        );

        if (!$response->isSuccess()) {
            throw new BackupTransportException($this->explain('create an upload session for ' . $remotePath, $response));
        }

        $body = $response->json();
        $url = (string) ($body['uploadUrl'] ?? '');
        if ($url === '') {
            throw new BackupTransportException(
                'The upload session for ' . $remotePath . ' came back without an upload URL.'
            );
        }

        return [
            'url' => $url,
            'expires' => (string) ($body['expirationDateTime'] ?? gmdate('c', time() + 3600)),
        ];
    }

    /**
     * The library to write into.
     *
     * A drive-addressed DSN answers this with no requests at all, which is why
     * the onboarding script prints that form: the two lookups below are two
     * more things that can 403 on a night when nothing is wrong with the
     * upload, and the library name they match on is localised per tenant.
     *
     * @throws BackupTransportException
     */
    private function driveId(): string
    {
        if ($this->driveId !== null) {
            return $this->driveId;
        }

        if ($this->dsn->addressesDriveDirectly()) {
            return $this->driveId = $this->dsn->driveId;
        }

        $site = $this->send('GET', self::GRAPH . '/sites/' . $this->dsn->graphSiteSelector());
        if (!$site->isSuccess()) {
            throw new BackupTransportException($this->explain(
                'find the site "' . $this->dsn->site . '"',
                $site
            ));
        }

        $siteId = (string) ($site->json()['id'] ?? '');
        if ($siteId === '') {
            throw new BackupTransportException('The site "' . $this->dsn->site . '" came back without an id.');
        }

        $drives = $this->send('GET', self::GRAPH . '/sites/' . $siteId . '/drives');
        if (!$drives->isSuccess()) {
            throw new BackupTransportException($this->explain('list the libraries of ' . $this->dsn->site, $drives));
        }

        $names = [];
        foreach ($drives->json()['value'] ?? [] as $drive) {
            $name = (string) ($drive['name'] ?? '');
            $names[] = $name;

            // Exact, case-insensitively. A substring match would put the
            // archives in "Freigegebene Dokumente" when the DSN said
            // "Dokumente", which nobody would notice until a restore.
            if (strcasecmp($name, $this->dsn->library) === 0) {
                return $this->driveId = (string) ($drive['id'] ?? '');
            }
        }

        throw new BackupTransportException(sprintf(
            'The site %s has no document library called "%s". It has: %s. Fix the library name in '
            . 'backup.dsn — the name is the one shown in SharePoint, in the tenant\'s own language.',
            $this->dsn->site,
            $this->dsn->library,
            $names === [] ? '(none this app may see)' : '"' . implode('", "', $names) . '"'
        ));
    }

    /** @throws BackupTransportException */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $response = $this->http->send(
            'POST',
            self::LOGIN . '/' . rawurlencode($this->dsn->tenantId) . '/oauth2/v2.0/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'client_id' => $this->dsn->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]),
            self::TIMEOUT_SECONDS,
        );

        if ($response->status === 0) {
            throw new BackupTransportException(
                'Could not reach Microsoft to sign in: ' . $response->body
                . '. The archive is on the webspace; nothing was uploaded.'
            );
        }

        if (!$response->isSuccess()) {
            // AADSTS7000222 is *the* expected failure of this whole feature.
            // Entra sends no warning when a client secret expires, and an
            // unattended nightly job can go months before anybody notices —
            // so the one case worth naming in its own sentence is the one an
            // operator can fix in ten minutes and would otherwise spend a week
            // diagnosing as "the backup is broken".
            if (str_contains($response->body, 'AADSTS7000222')) {
                throw new BackupTransportException(
                    'The backup app\'s client secret has expired. Mint a new one '
                    . '(scripts/setup-msgraph-backup.ps1 -RotateSecretOnly), put it in '
                    . 'backup.client_secret and update backup.client_secret_expires_at. Nothing has '
                    . 'been uploaded since it expired; the archives are still on the webspace.'
                );
            }

            throw new BackupTransportException(sprintf(
                'Microsoft refused the backup app\'s sign-in (HTTP %d): %s. Check backup.client_secret '
                . '— a client secret expires (24 months at most), and an expired one looks exactly '
                . 'like a wrong one from here.',
                $response->status,
                self::firstLine($response->body)
            ));
        }

        $token = (string) ($response->json()['access_token'] ?? '');
        if ($token === '') {
            throw new BackupTransportException('Microsoft signed the backup app in but sent no access token.');
        }

        return $this->token = $token;
    }

    /**
     * One request, with the two retries that are worth having.
     *
     * **429 and 503 only.** Graph throttles per tenant, so a 429 is an
     * ordinary event a club shares with everything else in its tenant, and
     * `Retry-After` is the server telling us exactly how to stop making it
     * worse. A refused connection is deliberately *not* retried: the run is
     * nightly, and a network that is down now is down in five seconds too —
     * retrying it spends the budget that the next chunk needed.
     *
     * @param array<string, string> $headers
     * @throws BackupTransportException
     */
    private function send(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        bool $authenticated = true,
    ): HttpResponse {
        for ($attempt = 0; ; $attempt++) {
            $all = $headers;
            if ($authenticated) {
                $all['Authorization'] = 'Bearer ' . $this->token();
            }

            $response = $this->http->send($method, $url, $all, $body, self::TIMEOUT_SECONDS);

            if ($response->status === 0) {
                throw new BackupTransportException(
                    'Could not reach ' . parse_url($url, PHP_URL_HOST) . ': ' . $response->body
                );
            }

            if (!self::isThrottled($response) || $attempt >= self::MAX_RETRIES) {
                return $response;
            }

            $wait = self::backoffFor($response, $attempt);
            if (time() + $wait > $this->deadline) {
                // Sleeping past the run's own budget is how a cron job gets
                // killed mid-write by a host's execution limit. Stop cleanly
                // instead; the sidecar already knows where we got to.
                throw new UploadBudgetExhaustedException(sprintf(
                    'Throttled, and the %d second wait the server asked for is longer than this run '
                    . 'has left.',
                    $wait
                ));
            }

            $this->logger->info('Throttled by Microsoft Graph; waiting as asked', [
                'seconds' => $wait,
                'attempt' => $attempt + 1,
            ]);
            ($this->sleeper)($wait);
        }
    }

    private static function isThrottled(HttpResponse $response): bool
    {
        return in_array($response->status, [429, 503, 504], true);
    }

    private static function backoffFor(HttpResponse $response, int $attempt): int
    {
        $retryAfter = $response->header('Retry-After');
        if ($retryAfter !== null && ctype_digit(trim($retryAfter))) {
            return max(1, (int) trim($retryAfter));
        }

        return self::FALLBACK_BACKOFF_SECONDS * (2 ** $attempt);
    }

    /** The first byte Graph still wants, or `$fallback` when it did not say. */
    private static function nextExpectedFrom(HttpResponse $response, int $fallback): int
    {
        $ranges = $response->json()['nextExpectedRanges'] ?? null;
        if (!is_array($ranges) || $ranges === []) {
            return $fallback;
        }

        $first = (string) reset($ranges);
        $start = strtok($first, '-');

        return $start === false || !ctype_digit($start) ? $fallback : (int) $start;
    }

    /**
     * Slashes stay slashes; everything else in a segment is escaped.
     *
     * Only *paths* go through this. Graph's own ids — drive, site, item — are
     * already URL-safe and are used verbatim: percent-encoding them works but
     * makes every logged URL unrecognisable next to the ones the onboarding
     * script and the docs print.
     */
    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }

    private function explain(string $whatWasAttempted, HttpResponse $response): string
    {
        $hint = match ($response->status) {
            401, 403 => ' Consenting Sites.Selected grants no site at all: access is granted one '
                . 'site at a time, and that per-site grant is a separate step. Re-run '
                . 'scripts/setup-msgraph-backup.ps1. Do not widen the permission to '
                . 'Sites.ReadWrite.All or Files.* — a 403 here is almost always the missing grant, '
                . 'and widening it turns a leaked secret from a lost backup into a tenant-wide '
                . 'breach.',
            404 => ' Check the site and library names in backup.dsn.',
            507 => ' The library is out of space.',
            default => '',
        };

        return sprintf(
            'Could not %s (HTTP %d): %s.%s',
            $whatWasAttempted,
            $response->status,
            self::firstLine($response->body),
            $hint
        );
    }

    /** Enough of a response body to act on; never a whole HTML error page in a cron mail. */
    private static function firstLine(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');

        return $body === '' ? '(no message)' : mb_substr($body, 0, 300);
    }
}
