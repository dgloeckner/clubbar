<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Http\OutboundHttpClient;

/**
 * Records outbound pings instead of making them.
 *
 * Shared rather than redeclared per test file, because two scheduled jobs now
 * ping a monitor — the mail drain (#406) and the backup run (#712) — and the
 * property both suites assert is the same one: **no test opens a socket**
 * (ADR-0038). A second copy of this would be a second place for that property
 * to quietly stop holding.
 */
final class SpyHttpClient implements OutboundHttpClient
{
    /** @var list<array{url:string,body:string,timeout:int}> */
    public array $calls = [];

    public function __construct(private bool $accept = true) {}

    public function post(string $url, string $body = '', int $timeoutSeconds = 3): bool
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'timeout' => $timeoutSeconds];

        return $this->accept;
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_map(static fn (array $call): string => $call['url'], $this->calls);
    }

    /** The body of the one call made, for a test asserting what left the host. */
    public function lastBody(): string
    {
        return $this->calls === [] ? '' : (string) end($this->calls)['body'];
    }
}
