<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * What a server said back, for the callers that have to act on it.
 *
 * Deliberately not a PSR-7 message. This codebase has no HTTP client library
 * and adding one for a single transport would be a dependency the reference
 * host has to carry forever (ADR-0031); what {@see HttpClient} needs is a
 * status, a body and two headers, and this is that and nothing more.
 *
 * Part of #691, epic #686.
 */
final class HttpResponse
{
    /** @var array<string, string> keyed by lowercased field name */
    private readonly array $normalisedHeaders;

    /**
     * @param int $status 0 when the request never completed at all — a refused
     *                    connection, a name that does not resolve, a timeout.
     *                    Not an HTTP status, and deliberately not an exception:
     *                    a transport distinguishes "the server said no" from
     *                    "there was no server" by looking, not by catching.
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        array $headers = [],
    ) {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }

        $this->normalisedHeaders = $normalised;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * A header by name, case-insensitively — or null if the server sent none.
     *
     * The case-insensitivity is not pedantry about RFC 9110. The two headers
     * this slice depends on are the two whose casing nobody agrees on:
     * `Retry-After` on a 429 and `Location` on an upload-session create. Miss
     * the first and the transport backs off for its own default instead of the
     * interval the server asked for, which is how an application gets
     * throttled harder; miss the second and a created session looks like a
     * failed one.
     *
     * Null rather than '' for absence, because a transport must be able to
     * tell "no such header" from "present and empty".
     */
    public function header(string $name): ?string
    {
        return $this->normalisedHeaders[strtolower($name)] ?? null;
    }

    /**
     * The body as a JSON object, or an empty array if it is not one.
     *
     * Forgiving on purpose: a proxy answering a 502 with an HTML error page is
     * an ordinary event on shared hosting, and the caller decides what to do
     * about the *status*. Making it guard a parse as well would put a
     * try/catch around every call for no added information.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
