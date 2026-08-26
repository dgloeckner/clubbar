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
}
