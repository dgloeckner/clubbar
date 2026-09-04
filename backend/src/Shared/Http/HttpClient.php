<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * One outbound request whose answer the caller actually needs.
 *
 * ## Why this is a second interface and not a wider first one
 *
 * {@see OutboundHttpClient} returns `bool` on purpose, and that narrowness is
 * a safety property rather than an oversight: everything reached through it is
 * a *report about* the application — a heartbeat ping — and ADR-0038's rule is
 * that a monitor must never be able to kill the job it watches. Widening it to
 * hand back a status and a body would invite a caller to branch on them, and
 * the first such branch is the first way a dead monitor host takes the mail
 * drain with it.
 *
 * An upload is the opposite kind of work. It *is* the job, and the caller
 * cannot behave correctly without seeing what came back: a `201` carries the
 * upload-session URL in `Location`, a `429` carries the interval in
 * `Retry-After`, a `308` carries the byte ranges the server already holds.
 * Those are decisions, not diagnostics.
 *
 * So: two interfaces, one implementation ({@see CurlHttpClient}), and the
 * choice of interface at the call site says which kind of work it is.
 *
 * ## Still no exceptions, and still no test opens a socket
 *
 * A transport failure is an ordinary outcome here — the network on a shared
 * host is not reliable and the backup must survive its being down — so a
 * failed request comes back as a {@see HttpResponse} with status `0` rather
 * than as a throw. The caller already has to handle "the server said no"; it
 * would gain nothing from handling "there was no server" in a second place.
 *
 * Tests drive a fake. ADR-0038 is explicit that no test opens a socket, and
 * that rule is why this is an interface at all.
 *
 * Part of #691, epic #686.
 */
interface HttpClient
{
    /**
     * Perform one request and report what came back.
     *
     * Never throws.
     *
     * @param string $method uppercase HTTP verb
     * @param array<string, string> $headers field name => value
     * @param int $timeoutSeconds bounds the whole exchange, connection included
     */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $timeoutSeconds = 30,
    ): HttpResponse;

    /**
     * The same, with the body streamed from a file rather than held in memory.
     *
     * {@see send()} takes the body as a `string`, which is right for a JSON
     * request and wrong for an archive: a WebDAV `PUT` of a 40 MB backup would
     * need the whole file resident, plus whatever cURL copies, on a shared host
     * whose `memory_limit` we do not control and cannot raise. The failure that
     * would produce is a fatal error partway through a nightly job — the
     * silent-stall class ADR-0038 exists to prevent — and it would arrive only
     * once the club's database had grown past a threshold nobody was watching.
     *
     * So this exists to make peak memory constant in the archive's size. It is
     * a separate method rather than a nullable parameter on {@see send()}
     * because the two have genuinely different contracts: this one can fail
     * before any request is made, when the file cannot be opened.
     *
     * Never throws. An unreadable file comes back as status `0`, like any other
     * request that never reached a server, because the caller's response is the
     * same and it already handles it.
     *
     * @param string $filePath the body; its size becomes `Content-Length`
     * @param array<string, string> $headers field name => value
     */
    public function sendFile(
        string $method,
        string $url,
        string $filePath,
        array $headers = [],
        int $timeoutSeconds = 30,
    ): HttpResponse;
}
