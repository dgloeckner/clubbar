<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * One outbound request, fire-and-mostly-forget.
 *
 * Extracted as an interface for exactly one reason: the heartbeat pinger has to
 * be testable without a socket, and ADR-0038 is explicit that **no test opens
 * one**. A fake implementation records what would have been sent; the real one
 * uses the cURL shape already used elsewhere in this codebase
 * ({@see \App\Shared\Security\HttpProbe}, `BankCodeService`).
 *
 * The contract is deliberately narrow and forgiving: no exceptions, no retry,
 * no response body. Everything reached through this interface is a *report
 * about* the application rather than part of its work, and a monitor that can
 * kill the job it watches is a net loss.
 */
interface OutboundHttpClient
{
    /**
     * POST $body to $url and say whether it was accepted.
     *
     * Never throws — a caller is entitled to ignore the result, and most do.
     *
     * @return bool True on a 2xx; false on anything else, including "this host
     *              cannot make outbound requests at all".
     */
    public function post(string $url, string $body = '', int $timeoutSeconds = 3): bool;
}
