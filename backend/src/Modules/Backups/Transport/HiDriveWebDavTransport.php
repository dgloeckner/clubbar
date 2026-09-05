<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

use App\Modules\Backups\Domain\HiDriveDsn;
use App\Shared\Http\HttpClient;
use App\Shared\Http\HttpResponse;
use App\Shared\Logging\Logger;

/**
 * Archives to a HiDrive folder over WebDAV.
 *
 *     PUT      <folder>/<archive>              the upload
 *     PROPFIND <folder>/<archive>  Depth: 0    the verification
 *     PROPFIND <folder>            Depth: 1    the listing retention reads
 *     DELETE   <folder>/<archive>              the retention delete
 *
 * ## Why this exists beside a working Graph transport
 *
 * Because the club that motivated the Graph transport cannot reach Microsoft.
 * Entra's edge answers `404 NotFound` to **every** request from the reference
 * host's egress address — including an anonymous fetch of the public OpenID
 * metadata document, which returns `200` from anywhere else — so no credential,
 * no tenant and no retry policy can help ([#825](https://github.com/dgloeckner/clubbar/issues/825)).
 * ADR-0049's roadmap put `hidrive://` last on the grounds that it is the same
 * vendor as the hosting; that reasoning still holds and is recorded in the
 * amendment, alongside the fact that an unreachable off-site copy protects
 * nothing at all.
 *
 * ## Four HTTP verbs and no sign-in
 *
 * The whole conversation is Basic auth over TLS, sent pre-emptively. There is
 * no token endpoint, no expiring client secret, no discovery — which removes,
 * by construction, the entire failure class this transport exists because of,
 * and the one {@see MsGraphTransport} has to special-case (`AADSTS7000222`, an
 * expired secret Entra never warns about).
 *
 * ## What it deliberately does not do
 *
 * **It does not resume.** A WebDAV `PUT` is one shot: there is no upload
 * session and no `Content-Range` to continue from, so `budgetSeconds` bounds
 * the attempt rather than slicing it, and {@see TransportResult::partial()} is
 * unreachable here. The archive is streamed from a file handle
 * ({@see HttpClient::sendFile()}) so a large one costs time rather than memory.
 * What keeps a failed night from being a *lost* night is the sidecar: it is
 * written before the upload as a bare marker and cleared only on success, so
 * `UploadState::pendingIn()` brings the next run back to it.
 *
 * **It does not create the folder.** A `PUT` into a collection that does not
 * exist answers `409`, and the honest response to that is to say which folder
 * is missing. Creating it would turn a mistyped DSN into a *successful* upload
 * into a folder nobody watches — the club then holds exactly the belief
 * ADR-0049 exists to destroy, and every check downstream agrees with it,
 * because the archives really are there.
 *
 * **It does not believe a `201`.** `PUT` answers with no body, so the only
 * evidence that the bytes arrived intact is to ask for them back: one
 * `PROPFIND` compares the stored length against the local file. ADR-0049's
 * spine is that an untested backup is a belief, not a backup, and one request a
 * night is the cheapest possible instalment on that.
 *
 * Part of #825, epic #686.
 */
final class HiDriveWebDavTransport implements BackupTransport
{
    /** Seconds one request may take on the nightly path. */
    public const DEFAULT_TIMEOUT_SECONDS = 120;

    /** WebDAV's "here is a property listing" — a success, and not a 2xx. */
    private const MULTI_STATUS = 207;

    public function __construct(
        private readonly HiDriveDsn $dsn,
        /** @param string $password never logged, never journalled, never in a summary */
        private readonly string $password,
        private readonly HttpClient $http,
        private readonly Logger $logger,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    public function describe(): string
    {
        return $this->dsn->describe();
    }

    public function upload(string $localPath, int $budgetSeconds): TransportResult
    {
        $startedAt = time();
        $name = basename($localPath);
        $size = (int) @filesize($localPath);

        if ($size <= 0) {
            return $this->failed($localPath, $startedAt, $budgetSeconds,
                'Refusing to upload ' . $name . ': it is empty or unreadable.');
        }

        // Before the attempt, not after it. A run killed by the host's
        // execution limit mid-`PUT` leaves no result to act on, and the marker
        // is the only thing that would bring the next run back here.
        $state = new UploadState($localPath);
        $state->markPending($size);

        $response = $this->http->sendFile(
            'PUT',
            $this->dsn->urlFor($name),
            $localPath,
            $this->headers(['Content-Type' => 'application/octet-stream']),
            $this->requestTimeout($startedAt, $budgetSeconds),
        );

        if (!$response->isSuccess()) {
            return $this->failed($localPath, $startedAt, $budgetSeconds,
                $this->explainUpload($name, $response));
        }

        $stored = $this->storedLength($name, $startedAt, $budgetSeconds);
        if ($stored !== $size) {
            // The marker stays. `PUT` is idempotent, so the next run overwrites
            // whatever is there — deliberately *not* deleting it here, because
            // a delete triggered by a size mismatch is a delete that fires on a
            // bug in our own arithmetic.
            return $this->failed($localPath, $startedAt, $budgetSeconds, sprintf(
                'Uploaded %s but the remote reports %s bytes where the archive has %s. Nothing '
                . 'has been deleted; the next run overwrites it. If this repeats, the folder in '
                . 'backup.dsn may be full or write-protected for the backup user.',
                $name,
                $stored < 0 ? 'an unreadable length' : number_format($stored),
                number_format($size),
            ));
        }

        $state->clear();

        $remotePath = $this->dsn->remotePathFor($name);
        $this->logger->info('Backup upload finished', [
            'remote' => $this->describe(),
            'archive' => $name,
            'status' => 'uploaded',
            'bytes_sent' => $size,
            'took_seconds' => time() - $startedAt,
            'verified' => true,
        ]);

        return TransportResult::uploaded($remotePath, $size);
    }

    public function list(): array
    {
        $response = $this->propfind($this->dsn->collectionUrl(), '1', $this->timeoutSeconds);

        if ($response->status !== self::MULTI_STATUS) {
            throw new BackupTransportException($this->explainListing($response));
        }

        $archives = [];

        foreach (self::parseMultiStatus($response->body) as $href => $length) {
            $name = rawurldecode(basename(rtrim($href, '/')));

            // Only this job's own archives. A club keeps other things in that
            // folder — a note to a successor, a copy of the key envelope — and
            // nothing else may ever become a candidate for the retention delete.
            if (!str_starts_with($name, 'clubbar-') || !str_ends_with($name, '.cbb')) {
                continue;
            }

            $archives[] = new RemoteArchive(
                $this->absoluteUrl($href),
                $name,
                max(0, $length),
                $this->dsn->collectionUrl(),
            );
        }

        usort($archives, static fn (RemoteArchive $a, RemoteArchive $b): int => $a->name <=> $b->name);

        return $archives;
    }

    public function delete(RemoteArchive $archive): bool
    {
        $response = $this->http->send(
            'DELETE',
            $archive->id,
            $this->headers(),
            '',
            $this->timeoutSeconds,
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
     * The stored length of one archive, or -1 when the server will not say.
     *
     * -1 rather than null or an exception because the caller compares it to a
     * size: every "not what we uploaded" answer belongs in one branch, and a
     * server that answers a `PROPFIND` without a length has told us exactly as
     * much as one that answers with the wrong one.
     */
    private function storedLength(string $name, int $startedAt, int $budgetSeconds): int
    {
        $response = $this->propfind(
            $this->dsn->urlFor($name),
            '0',
            $this->requestTimeout($startedAt, $budgetSeconds),
        );

        if ($response->status !== self::MULTI_STATUS) {
            return -1;
        }

        foreach (self::parseMultiStatus($response->body) as $length) {
            return $length;
        }

        return -1;
    }

    private function propfind(string $url, string $depth, int $timeoutSeconds): HttpResponse
    {
        return $this->http->send(
            'PROPFIND',
            $url,
            $this->headers([
                'Depth' => $depth,
                'Content-Type' => 'application/xml; charset=utf-8',
            ]),
            // Asking for the two properties actually read, rather than sending
            // an empty body: an empty PROPFIND means `allprop`, and on a folder
            // of several hundred archives that is a large XML document built
            // and parsed for two numbers.
            '<?xml version="1.0" encoding="utf-8"?>'
            . '<D:propfind xmlns:D="DAV:"><D:prop>'
            . '<D:getcontentlength/><D:resourcetype/>'
            . '</D:prop></D:propfind>',
            $timeoutSeconds,
        );
    }

    /**
     * `href` => content length, for every non-collection in a multistatus.
     *
     * Parsed with DOM and an explicit `DAV:` namespace rather than by string
     * matching, because the prefix is the server's to choose: mod_dav writes
     * `<D:response>`, others write `<d:response>` or default the namespace and
     * write `<response>`. All three are the same document, and a parser that
     * matches on the prefix works against one server and silently returns
     * nothing for the next — which here would read as "the remote holds no
     * archives" and put retention to work on that belief.
     *
     * @return array<string, int>
     */
    private static function parseMultiStatus(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');

        $found = [];

        foreach ($xpath->query('//d:response') ?: [] as $node) {
            $href = $xpath->evaluate('string(d:href)', $node);
            if (!is_string($href) || trim($href) === '') {
                continue;
            }

            // A `PROPFIND` with `Depth: 1` returns the collection itself as its
            // first entry. Skipping it by resource type rather than by
            // comparing hrefs, because servers disagree about the trailing
            // slash and about whether the href is absolute.
            if ($xpath->query('d:propstat/d:prop/d:resourcetype/d:collection', $node)?->length) {
                continue;
            }

            $length = $xpath->evaluate('string(d:propstat/d:prop/d:getcontentlength)', $node);
            $found[trim($href)] = is_string($length) && $length !== '' ? (int) $length : -1;
        }

        return $found;
    }

    /**
     * An `href` from a multistatus, as something `DELETE` can be sent to.
     *
     * A server may answer with an absolute URL or a rooted path, and both are
     * legal. Rebuilding the second onto the DSN's host keeps every archive this
     * class hands out addressable without the caller knowing which it got.
     */
    private function absoluteUrl(string $href): string
    {
        $href = trim($href);

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        return 'https://' . $this->dsn->host . '/' . ltrim($href, '/');
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function headers(array $extra = []): array
    {
        // Pre-emptive Basic rather than waiting for the 401 challenge: it halves
        // the request count on every call, and the challenge round trip buys
        // nothing when there is exactly one scheme this server accepts.
        return $extra + [
            'Authorization' => 'Basic ' . base64_encode($this->dsn->username . ':' . $this->password),
        ];
    }

    /**
     * What is left of the run's budget, as this request's timeout.
     *
     * The budget is the only bound this transport has — there is no resume, so
     * nothing carries a half-finished upload forward — and letting a request
     * run past it is how a cron job gets killed by the host's execution limit
     * mid-write instead of reporting a clean failure.
     */
    private function requestTimeout(int $startedAt, int $budgetSeconds): int
    {
        $spent = time() - $startedAt;

        return max(1, min($this->timeoutSeconds, $budgetSeconds - $spent));
    }

    private function failed(string $localPath, int $startedAt, int $budgetSeconds, string $why): TransportResult
    {
        $this->logger->error('Backup upload failed', [
            'remote' => $this->describe(),
            'archive' => basename($localPath),
            'bytes' => (int) @filesize($localPath),
            'took_seconds' => time() - $startedAt,
            'budget_seconds' => $budgetSeconds,
            'message' => $why,
        ]);

        return TransportResult::failed($why);
    }

    /**
     * The sentence an operator reads at 08:00, naming the thing to go and look at.
     *
     * Each status gets its own, because the actions could not be more
     * different: a 401 is a password, a 409 is a folder that does not exist, a
     * 507 is a full tariff, and a 0 is the network. A single "upload failed
     * (HTTP %d)" would make all four the same morning's work.
     */
    private function explainUpload(string $name, HttpResponse $response): string
    {
        $where = $this->describe();

        return match (true) {
            $response->status === 0 => sprintf(
                'Could not reach %s: %s. The archive is on the webspace; the next run tries again.',
                $this->dsn->host,
                self::firstLine($response->body)
            ),
            $response->status === 401 || $response->status === 403 => sprintf(
                'The backup user was refused by %s (HTTP %d). Check backup.remote_secret, and that '
                . 'WebDAV is still enabled for that user in the HiDrive web app under Settings → '
                . 'Access rights and protocols — it is a per-user switch, and turning it off is '
                . 'indistinguishable from a wrong password from here.',
                $this->dsn->host,
                $response->status
            ),
            $response->status === 404 || $response->status === 409 => sprintf(
                'The folder %s does not exist on the remote, so %s could not be written. This '
                . 'transport never creates it: a folder conjured up from a typo would take '
                . 'uploads and be watched by nobody. Create it as the backup user, or correct '
                . 'backup.dsn.',
                $where,
                $name
            ),
            $response->status === 507 => sprintf(
                'No space left at %s. Nothing has been uploaded since; the archives are still on '
                . 'the webspace. Free space in the HiDrive tariff, or lower '
                . 'backup.remote_retention_days.',
                $where
            ),
            default => sprintf(
                'Uploading %s to %s failed with HTTP %d: %s',
                $name,
                $where,
                $response->status,
                self::firstLine($response->body)
            ),
        };
    }

    private function explainListing(HttpResponse $response): string
    {
        if ($response->status === 0) {
            return sprintf('Could not reach %s: %s', $this->dsn->host, self::firstLine($response->body));
        }

        return sprintf(
            'Could not list %s (HTTP %d): %s Remote retention needs this listing, so nothing has '
            . 'been deleted from the remote this run.',
            $this->describe(),
            $response->status,
            self::firstLine($response->body)
        );
    }

    private static function firstLine(string $body): string
    {
        $body = trim(strip_tags($body));
        $end = strpos($body, "\n");
        $line = $end === false ? $body : substr($body, 0, $end);
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

        return $line === '' ? '(no message)' : (strlen($line) > 200 ? substr($line, 0, 200) . '…' : $line);
    }
}
