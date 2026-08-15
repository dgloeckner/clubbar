<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * {@see OutboundHttpClient} over cURL.
 *
 * Same shape as {@see \App\Shared\Security\HttpProbe} and `BankCodeService`:
 * short timeouts, no redirects followed, every failure swallowed into a return
 * value. What differs is the direction of trust — the probe asks *this* host
 * about itself, while this one talks to a third party that may be slow, down,
 * or a DNS name that no longer resolves.
 *
 * That is why both timeouts are set. `CURLOPT_TIMEOUT` alone bounds the whole
 * transfer but not the wait for a TCP connection on some builds, and the
 * failure that actually happens on shared hosting is a monitor host that
 * accepts a connection and never answers.
 */
final class CurlHttpClient implements OutboundHttpClient
{
    public function post(string $url, string $body = '', int $timeoutSeconds = 3): bool
    {
        if (!function_exists('curl_init')) {
            // A host with cURL disabled cannot ping, and must not fail because
            // of it. The absence shows up as a monitor that never hears from
            // this installation, which is the alarm working as intended.
            return false;
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return false;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => max(1, $timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => max(1, $timeoutSeconds),
            CURLOPT_HTTPHEADER     => ['Content-Type: text/plain; charset=utf-8'],
        ]);

        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return $status >= 200 && $status < 300;
    }
}
