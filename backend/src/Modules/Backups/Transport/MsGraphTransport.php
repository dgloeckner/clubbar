<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

use App\Modules\Backups\Domain\MsGraphDsn;
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

    /**
     * Bounds one request on the nightly path. A whole run is bounded separately,
     * by its budget.
     *
     * Sized for a 3.2 MiB chunk on a slow line, which is the right shape for a
     * job nobody is waiting on. It is the **default** rather than a constant
     * since #693, because a page-triggered listing wants the opposite trade —
     * see {@see self::browsing()}.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 120;

    /** Retries honour `Retry-After`, so a throttled tenant sets the real cost. */
    public const DEFAULT_MAX_RETRIES = 3;

    private const FALLBACK_BACKOFF_SECONDS = 5;

    private readonly Closure $sleeper;

    private ?string $token = null;
    private ?string $driveId = null;

    /**
     * Requests made by this instance, retries included.
     *
     * Logged with the outcome of an upload because it is the cheapest possible
     * answer to "was the night slow, or was it retrying?" — a run that made
     * four requests and one that made forty look identical in every other
     * field.
     */
    private int $requestsMade = 0;

    /** Wall clock this run may still spend; `PHP_INT_MAX` outside a transfer. */
    private int $deadline = PHP_INT_MAX;

    /**
     * @param string $clientSecret never logged, never journalled, never in a summary
     * @param (callable(int): void)|null $sleeper the backoff seam — ADR-0038's rule that no
     *        test opens a socket would be worth little if every retry test took seven seconds
     */
    public function __construct(
        private readonly MsGraphDsn $dsn,
        private readonly string $clientSecret,
        private readonly HttpClient $http,
        private readonly Logger $logger,
        ?callable $sleeper = null,
        /**
         * Seconds one request may take. Defaults to the nightly job's budget;
         * {@see self::browsing()} is the short-budgeted alternative a page uses.
         */
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        /** Retries on a throttle. Zero means answer or fail, never wait. */
        private readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
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

        $startedAt = time();

        try {
            $result = $this->transfer($localPath, $remotePath);

            $this->logger->info('Backup upload finished', [
                'remote' => $this->describe(),
                'archive' => basename($localPath),
                'status' => $result->status,
                'bytes_sent' => $result->bytesSent,
                'took_seconds' => time() - $startedAt,
                'requests' => $this->requestsMade,
            ]);

            return $result;
        } catch (BackupTransportException $e) {
            // The human-readable half. The machine-readable half — which
            // request, which status, which correlation id — is already in the
            // warnings {@see self::logRequestFailure()} wrote for each attempt,
            // so this line stays a sentence rather than growing a second copy
            // of it.
            $this->logger->error('Backup upload failed', [
                'remote' => $this->describe(),
                'archive' => basename($localPath),
                'bytes' => (int) @filesize($localPath),
                'took_seconds' => time() - $startedAt,
                'requests' => $this->requestsMade,
                'budget_seconds' => $budgetSeconds,
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
            self::GRAPH . '/drives/' . $archive->container . '/items/' . $archive->id
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

    /**
     * Sign in, once per run.
     *
     * Goes through {@see self::send()} like every other request — with
     * `authenticated: false`, which is also what keeps it from recursing into
     * itself. It did not until this was fixed, and that was the gap this method's own
     * failure exposed: the one request the whole night depends on was the one
     * request with no retry, while the three that follow it each had three.
     *
     * @throws BackupTransportException
     */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $response = $this->send(
            'POST',
            self::LOGIN . '/' . rawurlencode($this->dsn->tenantId) . '/oauth2/v2.0/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query([
                'client_id' => $this->dsn->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]),
            // Never a bearer token on the request that fetches one, and never
            // the body in a log line: it carries the client secret.
            authenticated: false,
        );

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
                    . 'backup.remote_secret and update backup.client_secret_expires_at. Nothing has '
                    . 'been uploaded since it expired; the archives are still on the webspace.'
                );
            }

            $diagnosis = $this->classifySignInFailure($response);

            // The one line worth grepping for the next morning. It names the
            // *configuration value* to look at rather than describing the
            // symptom, and it carries the tenant and client ids so they can be
            // compared against the tenant by eye — neither is a secret, and
            // between them they are what a wrong DSN gets wrong. The secret
            // itself is never logged, in any form.
            $this->logger->error('Microsoft refused the backup app\'s sign-in', [
                'cause' => $diagnosis['cause'],
                'check' => $diagnosis['check'],
                'status' => $response->status,
                'error_code' => self::errorCodeIn($response) ?? '(none)',
                'tenant_id' => $this->dsn->tenantId,
                'client_id' => $this->dsn->clientId,
                'from_microsoft' => self::looksLikeMicrosoft($response),
                'body' => self::firstLine($response->body),
            ]);

            throw new BackupTransportException(sprintf(
                'Microsoft refused the backup app\'s sign-in (HTTP %d): %s.%s',
                $response->status,
                self::firstLine($response->body),
                $diagnosis['hint']
            ));
        }

        $token = (string) ($response->json()['access_token'] ?? '');
        if ($token === '') {
            throw new BackupTransportException('Microsoft signed the backup app in but sent no access token.');
        }

        return $this->token = $token;
    }

    /**
     * Which of the four things that can be wrong is wrong.
     *
     * The point of this method is that a nightly job fails while nobody is
     * watching, and the log is all anybody has the next morning. "Microsoft
     * said no" is not enough to act on — it leaves an operator guessing
     * between a tenant that has moved, an app registration that was deleted, a
     * secret that expired and a network that is being intercepted, and the
     * first three are edits to different lines of `config.php`.
     *
     * Entra says which, in an `AADSTS` code, and those codes are stable and
     * documented. Translating the handful that can actually occur for a
     * client-credentials grant turns the guess into a field.
     *
     * | Code | What is wrong | Where the fix is |
     * |---|---|---|
     * | `AADSTS90002`, `AADSTS900023` | The tenant does not exist, or is not spelled the way it is here | `backup.dsn`, tenant id |
     * | `AADSTS700016` | The tenant exists; no app registration in it has this client id. Usually a deleted registration, or a DSN pointing at the wrong tenant | `backup.dsn`, client id |
     * | `AADSTS7000215` | The registration exists and the secret is not its secret | `backup.remote_secret` |
     * | `AADSTS7000222` | The secret was right and has expired — named on its own above, before this runs | `backup.remote_secret` |
     * | `AADSTS7000112`, `AADSTS700027` | The registration is disabled or its credential was revoked in the tenant | the tenant, not this host |
     * | *no code at all* | Nothing that speaks Entra answered | the network between here and Microsoft |
     *
     * @return array{cause: string, check: string, hint: string}
     */
    private function classifySignInFailure(HttpResponse $response): array
    {
        if (!self::looksLikeMicrosoft($response)) {
            return [
                'cause' => 'not-an-answer-from-microsoft',
                'check' => 'network reachability of login.microsoftonline.com',
                'hint' => sprintf(
                    ' This does not look like an answer from Microsoft: the body carries no AADSTS '
                    . 'code (Content-Type: %s), and Entra refuses a credential with a 400 or a 401 '
                    . 'that has one. Something between this server and login.microsoftonline.com — '
                    . 'an outbound proxy, a captive portal, a DNS answer — is likely answering '
                    . 'instead. Check that this host can reach login.microsoftonline.com before '
                    . 'touching the configuration.%s',
                    $response->header('Content-Type') ?? 'none',
                    // The tenant id is a path segment of the URL that was
                    // requested, so a malformed one is the other way to get a
                    // bare 404 here — and it is the one an operator can check
                    // in ten seconds against the id in the log line.
                    $response->status === 404
                        ? ' A malformed tenant id would also produce a 404, because it is part of '
                            . 'that URL\'s path — compare the tenant_id in this log entry against '
                            . 'the tenant before looking further.'
                        : ''
                ),
            ];
        }

        $code = self::errorCodeIn($response) ?? '';

        return match ($code) {
            'AADSTS90002', 'AADSTS900023' => [
                'cause' => 'tenant-not-found',
                'check' => 'backup.dsn (tenant id)',
                'hint' => ' Microsoft has no tenant with the id in backup.dsn. The client secret is '
                    . 'not the problem — it was never read. Check the tenant id, which is the '
                    . 'first path segment of the DSN.',
            ],
            'AADSTS700016' => [
                'cause' => 'client-id-not-in-tenant',
                'check' => 'backup.dsn (client id)',
                'hint' => ' The tenant exists but has no app registration with the client id in '
                    . 'backup.dsn — the registration was deleted, or the DSN names the wrong '
                    . 'tenant. Re-run scripts/setup-msgraph-backup.ps1 to see what is actually '
                    . 'registered.',
            ],
            'AADSTS7000215' => [
                'cause' => 'client-secret-wrong',
                'check' => 'backup.remote_secret',
                'hint' => ' The app registration exists and the value in backup.remote_secret is '
                    . 'not its secret. Mint a new one (scripts/setup-msgraph-backup.ps1 '
                    . '-RotateSecretOnly) — the value is shown exactly once and cannot be read '
                    . 'back afterwards, so a secret that was copied short is copied short forever.',
            ],
            'AADSTS7000112', 'AADSTS700027' => [
                'cause' => 'registration-disabled-or-revoked',
                'check' => 'the app registration in the tenant',
                'hint' => ' The app registration is disabled or its credential was revoked in the '
                    . 'tenant. This is fixed in Entra, not in config.php.',
            ],
            default => [
                'cause' => $code === '' ? 'refused' : 'refused:' . $code,
                'check' => 'backup.remote_secret',
                'hint' => ' Check backup.remote_secret — a client secret expires (24 months at '
                    . 'most), and an expired one looks exactly like a wrong one from here.',
            ],
        };
    }

    /**
     * One request, with the retries that are worth having.
     *
     * ## What is retried, and what must never be
     *
     * Only the failures that are *about the moment rather than the request*:
     * a throttle, a gateway that was briefly out, and a connection that never
     * completed at all. Everything else is deterministic — a 401 is a bad
     * secret, a 403 is a missing grant, a 404 is a name that does not resolve
     * — and retrying a deterministic failure buys nothing but three more
     * identical lines in the log and three more chances to be killed by a
     * host's execution limit.
     *
     * | | |
     * |---|---|
     * | `429` | Graph throttles per tenant, so this is an ordinary event a club shares with everything else in its tenant. `Retry-After` is the server saying exactly how to stop making it worse |
     * | `500`, `502`, `503`, `504` | The tenant is fine and something in front of it was not. Every request this class makes is safe to repeat — a range `PUT` is idempotent by construction, and `createUploadSession` carries `conflictBehavior: replace` |
     * | status `0` | No response at all: DNS, a refused connection, a timeout. **Retried** — the nightly job runs on shared hosting, where a name that does not resolve for one second is a normal night, and losing a whole night's off-site copy to it is not |
     *
     * Status `0` was previously not retried, on the reasoning that a network
     * which is down now is down in five seconds too. That is true of an outage
     * and false of the failure that actually happens, and the budget it was
     * protecting is already protected: every wait is checked against the run's
     * own deadline below, so a genuine outage still stops rather than sleeping
     * into the host's kill.
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

            $startedAt = microtime(true);
            $this->requestsMade++;
            $response = $this->http->send($method, $url, $all, $body, $this->timeoutSeconds);
            $tookMs = (int) round((microtime(true) - $startedAt) * 1000);

            if (!self::isWorthRetrying($response) || $attempt >= $this->maxRetries) {
                if (!$response->isSuccess()) {
                    $this->logRequestFailure($method, $url, $response, $attempt, $tookMs, null);
                }

                if ($response->status === 0) {
                    throw new BackupTransportException(sprintf(
                        'Could not reach %s%s: %s',
                        parse_url($url, PHP_URL_HOST),
                        $attempt === 0 ? '' : ' after ' . ($attempt + 1) . ' attempts',
                        $response->body
                    ));
                }

                return $response;
            }

            $wait = self::backoffFor($response, $attempt);
            if (time() + $wait > $this->deadline) {
                // Sleeping past the run's own budget is how a cron job gets
                // killed mid-write by a host's execution limit. Stop cleanly
                // instead; the sidecar already knows where we got to.
                throw new UploadBudgetExhaustedException(sprintf(
                    'Gave up after %s: the %d second wait before the next attempt is longer than '
                    . 'this run has left.',
                    $response->status === 0 ? 'a connection that never completed' : 'HTTP ' . $response->status,
                    $wait
                ));
            }

            $this->logRequestFailure($method, $url, $response, $attempt, $tookMs, $wait);
            ($this->sleeper)($wait);
        }
    }

    /**
     * One line per failed attempt, with the fields that make a failure
     * diagnosable without a second night.
     *
     * The message an operator reads is built by {@see self::explain()} and
     * logged once, by the caller. This is the other half: the machine-readable
     * record of *which* request failed and what the wire actually carried —
     * which is what separates "Microsoft said no" from "something between this
     * server and Microsoft said no", a distinction no human-readable sentence
     * can make on its own.
     *
     * @param int|null $retryInSeconds null when this attempt was the last
     */
    private function logRequestFailure(
        string $method,
        string $url,
        HttpResponse $response,
        int $attempt,
        int $tookMs,
        ?int $retryInSeconds,
    ): void {
        $context = [
            'method' => strtoupper($method),
            'url' => self::loggableUrl($url),
            'status' => $response->status,
            'attempt' => $attempt + 1,
            'of' => $this->maxRetries + 1,
            'took_ms' => $tookMs,
        ];

        if ($retryInSeconds !== null) {
            $context['retry_in_seconds'] = $retryInSeconds;
        }

        // For status 0 the body is cURL's own explanation — "Could not resolve
        // host", "Connection timed out" — which is the only thing there is to
        // go on, so it is logged in both cases.
        $context['body'] = self::firstLine($response->body);

        if ($response->status !== 0) {
            $context['content_type'] = $response->header('Content-Type') ?? '(none)';
            // Entra and Graph both stamp every response with a correlation id,
            // and it is the first thing Microsoft support asks for. Logging it
            // costs one field and turns "our backup fails" into a ticket.
            $context['request_id'] = $response->header('request-id')
                ?? $response->header('x-ms-request-id')
                ?? $response->header('client-request-id')
                ?? '(none)';
            $context['error_code'] = self::errorCodeIn($response) ?? '(none)';
            $context['from_microsoft'] = self::looksLikeMicrosoft($response);
        }

        // Warning, not error: the caller decides whether a failed request is a
        // failed *run*. A retried 429 is neither.
        $this->logger->warning(
            $retryInSeconds === null
                ? 'A Microsoft Graph request failed'
                : 'A Microsoft Graph request failed and will be retried',
            $context
        );
    }

    private static function isWorthRetrying(HttpResponse $response): bool
    {
        return in_array($response->status, [0, 429, 500, 502, 503, 504], true);
    }

    /**
     * The URL with its query string removed.
     *
     * **Never log the query.** An upload-session URL is a bearer capability in
     * its own right — its `tempauth` parameter grants write access to the
     * archive path without any other credential — so a log line carrying one
     * is a credential in a file that gets mailed, shipped and pasted into
     * issues. The path alone is what identifies the request.
     */
    private static function loggableUrl(string $url): string
    {
        $end = strpos($url, '?');

        return $end === false ? $url : substr($url, 0, $end) . '?…';
    }

    /**
     * The error code Microsoft put in the body, in either of its two shapes.
     *
     * Entra answers `{"error":"invalid_client","error_description":"AADSTS7000222: …"}`
     * and Graph answers `{"error":{"code":"accessDenied"}}`. The AADSTS number
     * is the one worth pulling out of prose: it is the identifier Microsoft's
     * own documentation is indexed by.
     */
    private static function errorCodeIn(HttpResponse $response): ?string
    {
        $body = $response->json();
        $error = $body['error'] ?? null;

        if (is_array($error)) {
            $code = (string) ($error['code'] ?? '');

            return $code === '' ? null : $code;
        }

        if (preg_match('/AADSTS\d+/', (string) ($body['error_description'] ?? ''), $match) === 1) {
            return $match[0];
        }

        return is_string($error) && $error !== '' ? $error : null;
    }

    /**
     * Did this answer come from Microsoft at all?
     *
     * The question is not pedantry. A shared host with an outbound proxy, a
     * captive portal or a DNS answer that has been taken over returns a plain
     * `404 Not Found` for the token endpoint — and Entra never does that for a
     * bad credential, which is a `400` or `401` carrying an `AADSTS` code. The
     * two are indistinguishable from the status alone, and telling them apart
     * is the difference between rotating a secret that was never wrong and
     * looking at the network.
     */
    private static function looksLikeMicrosoft(HttpResponse $response): bool
    {
        // The *body* decides, not the `Content-Type` header. Both Entra and
        // Graph refuse in a documented JSON shape carrying a code, and nothing
        // that merely sits in the path — a proxy's error page, a captive
        // portal, a bare `Not Found` — produces one. Requiring the header as
        // well would be stricter and wrong in the expensive direction: it
        // would flag a real Microsoft refusal as suspect and send an operator
        // to look at the network instead of at the credential.
        return self::errorCodeIn($response) !== null;
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

        // The same distinction the sign-in makes: an answer that is not JSON
        // and carries no Graph error code did not come from Graph, and the
        // hints above would then be sending an operator to look at a
        // configuration that is fine.
        if (!self::looksLikeMicrosoft($response)) {
            $hint = sprintf(
                ' The answer came back as %s with no error code, so it may not be from Microsoft '
                . 'at all — check that this host can reach graph.microsoft.com before changing '
                . 'anything.',
                $response->header('Content-Type') ?? 'a response with no content type'
            );
        }

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
