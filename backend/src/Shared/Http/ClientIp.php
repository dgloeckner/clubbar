<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * The address a request actually came from — trusting `X-Forwarded-For` only
 * from configured reverse proxies (#886).
 *
 * Every IP-keyed decision in this application — the login rate limiter, the
 * audit log, terminal anomaly detection (ADR-0041) — used to read
 * `REMOTE_ADDR` alone. That is correct on the shared hosting ADR-0031 names as
 * the reference target, where PHP sees the client directly. It is wrong behind
 * any reverse proxy: every request then arrives from the proxy's own address,
 * collapsing the per-IP rate limiter into one shared budget for the whole
 * admin team and writing the proxy's address into ten years of audit rows.
 *
 * Blindly trusting `X-Forwarded-For` would be worse — it is a plain request
 * header, and anyone can send one claiming to be `1.2.3.4`. So nothing here is
 * trusted by default: {@see resolve()} only reads the header at all once
 * `REMOTE_ADDR` itself is inside a configured, trusted CIDR, and it walks the
 * header from the right, past every hop that is itself trusted, stopping at
 * the first one that is not. Rightmost-untrusted rather than leftmost is what
 * makes this unforgeable — a client can prepend anything it likes to the
 * header, but it cannot make the proxy's own hop lie about who connected to
 * it.
 *
 * An empty trusted-proxies configuration reproduces today's behaviour exactly:
 * `REMOTE_ADDR` and nothing else, for every installation that has not opted
 * in.
 */
final class ClientIp
{
    /**
     * The resolved client address, or `REMOTE_ADDR` unchanged when it does not
     * originate from a trusted proxy — including when no trusted proxies are
     * configured at all.
     *
     * @param array<string,mixed> $serverParams The request's server parameters
     *        (`$request->getServerParams()`, or `$_SERVER`).
     * @param string $trustedProxies Comma-separated IPs/CIDRs (`TRUSTED_PROXIES`).
     */
    public static function resolve(array $serverParams, string $trustedProxies): string
    {
        $remoteAddr = (string) ($serverParams['REMOTE_ADDR'] ?? '');

        $trustedCidrs = self::parseCidrs($trustedProxies);
        if ($trustedCidrs === [] || $remoteAddr === '' || !self::isTrusted($remoteAddr, $trustedCidrs)) {
            return $remoteAddr;
        }

        $forwardedFor = trim((string) ($serverParams['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor === '') {
            return $remoteAddr;
        }

        return self::rightmostUntrusted($forwardedFor, $trustedCidrs) ?? $remoteAddr;
    }

    /**
     * Walk `X-Forwarded-For` from the right, skipping every hop that is itself
     * a trusted proxy, and return the first one that is not — the address the
     * outermost trusted hop says it received the connection from.
     *
     * Null when the chain runs out (every hop trusted) or a hop cannot be
     * parsed as an IP: a malformed entry is not evidence of anything, and
     * guessing past it would be exactly the forgery this class exists to
     * refuse.
     *
     * @param list<array{0:string,1:int}> $trustedCidrs
     */
    private static function rightmostUntrusted(string $forwardedFor, array $trustedCidrs): ?string
    {
        $hops = array_map('trim', explode(',', $forwardedFor));

        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $candidate = $hops[$i];
            if ($candidate === '' || @inet_pton($candidate) === false) {
                return null;
            }

            if (!self::isTrusted($candidate, $trustedCidrs)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<array{0:string,1:int}> $trustedCidrs
     */
    private static function isTrusted(string $ip, array $trustedCidrs): bool
    {
        foreach ($trustedCidrs as [$network, $prefixLen]) {
            if (self::inCidr($ip, $network, $prefixLen)) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $ip, string $networkPacked, int $prefixLen): bool
    {
        $ipPacked = @inet_pton($ip);
        if ($ipPacked === false || strlen($ipPacked) !== strlen($networkPacked)) {
            return false;
        }

        $wholeBytes = intdiv($prefixLen, 8);
        if ($wholeBytes > 0 && strncmp($ipPacked, $networkPacked, $wholeBytes) !== 0) {
            return false;
        }

        $remainderBits = $prefixLen % 8;
        if ($remainderBits === 0) {
            return true;
        }

        $mask = (~(0xFF >> $remainderBits)) & 0xFF;

        return (ord($ipPacked[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
    }

    /**
     * `TRUSTED_PROXIES` as a list of `[packed network, prefix length]` pairs.
     *
     * A bare IP (no `/prefix`) is its own single-address network — `/32` for
     * IPv4, `/128` for IPv6. An entry that cannot be parsed as an address is
     * dropped rather than throwing: a typo in this setting must never turn
     * into a 500 on every request, only into that one entry never matching.
     *
     * @return list<array{0:string,1:int}>
     */
    private static function parseCidrs(string $trustedProxies): array
    {
        $cidrs = [];

        foreach (explode(',', $trustedProxies) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
            $packed = @inet_pton((string) $address);
            if ($packed === false) {
                continue;
            }

            $maxPrefix = strlen($packed) * 8;
            $prefixLen = $prefix === null ? $maxPrefix : (int) $prefix;
            if ($prefixLen < 0 || $prefixLen > $maxPrefix) {
                continue;
            }

            $cidrs[] = [$packed, $prefixLen];
        }

        return $cidrs;
    }
}
