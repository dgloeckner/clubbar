<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * {@see OutboundHttpClient} and {@see HttpClient} over cURL.
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
 *
 * It implements both outbound interfaces because there is one right way to
 * make a request on this host and no reason to write it twice — but the three
 * methods keep their contracts apart: {@see post()} swallows everything into a
 * bool for the heartbeat, {@see send()} reports what came back for the
 * transport that has to act on it (#691), and {@see sendFile()} does the same
 * for a body too large to hold in memory (#825).
 */
final class CurlHttpClient implements OutboundHttpClient, HttpClient
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

    /**
     * The answering half (#691).
     *
     * Response headers are collected through `CURLOPT_HEADERFUNCTION` rather
     * than by setting `CURLOPT_HEADER` and slicing the body at the first blank
     * line: that slicing is wrong the moment a proxy or a 100-continue puts
     * two header blocks on the wire, and Graph's upload sessions do exactly
     * that. The callback sees each block and the later one wins, which is the
     * one that belongs to the response being returned.
     *
     * A request that never completed comes back as status 0, never as a throw
     * — see the interface docblock.
     */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $timeoutSeconds = 30,
    ): HttpResponse {
        if (!function_exists('curl_init')) {
            return new HttpResponse(0, 'This host has no cURL, so it cannot make outbound requests.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return new HttpResponse(0, 'Could not initialise a request to ' . $url . '.');
        }

        $received = [];

        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => max(1, $timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => max(1, min($timeoutSeconds, 10)),
            CURLOPT_HTTPHEADER     => $formatted,
            CURLOPT_HEADERFUNCTION => static function ($_curl, string $line) use (&$received): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $received[trim($parts[0])] = trim($parts[1]);
                } elseif (trim($line) !== '' && str_starts_with($line, 'HTTP/')) {
                    // A new status line: a redirect chain or a 100-continue.
                    // Everything before it belonged to a different response.
                    $received = [];
                }

                return $length;
            },
        ]);

        if ($body !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($responseBody === false) {
            return new HttpResponse(0, $error !== '' ? $error : 'The request failed before any response.');
        }

        return new HttpResponse($status, (string) $responseBody, $received);
    }

    /**
     * The streaming half (#825).
     *
     * `CURLOPT_UPLOAD` plus `CURLOPT_INFILE` hands cURL the file handle and
     * lets it read the body in its own buffer-sized bites, so peak memory is
     * constant no matter how large the archive has grown. `CURLOPT_INFILESIZE`
     * is what makes that possible over HTTP/1.1: without a `Content-Length`
     * cURL would have to fall back to chunked transfer-encoding, which mod_dav
     * accepts and some intermediaries in front of it do not.
     *
     * **`Expect:` is sent empty on purpose**, which suppresses cURL's automatic
     * `Expect: 100-continue` on bodies over 1 KB. The handshake it asks for is
     * worth having when a server might reject the request before the body — but
     * this transport authenticates pre-emptively, so there is nothing to reject
     * — and a server that ignores the header costs a fixed one-second stall on
     * every single upload while cURL waits for a continuation that never comes.
     */
    public function sendFile(
        string $method,
        string $url,
        string $filePath,
        array $headers = [],
        int $timeoutSeconds = 30,
    ): HttpResponse {
        if (!function_exists('curl_init')) {
            return new HttpResponse(0, 'This host has no cURL, so it cannot make outbound requests.');
        }

        $size = @filesize($filePath);
        $handle = @fopen($filePath, 'rb');

        if ($size === false || $handle === false) {
            if ($handle !== false) {
                fclose($handle);
            }

            // Status 0, not a throw: the caller already handles "no answer",
            // and a file that cannot be read is the same outcome as a server
            // that could not be reached — nothing was uploaded.
            return new HttpResponse(0, 'Could not open ' . basename($filePath) . ' to upload it.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);

            return new HttpResponse(0, 'Could not initialise a request to ' . $url . '.');
        }

        $received = [];

        $formatted = ['Expect:'];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $handle,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => max(1, $timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => max(1, min($timeoutSeconds, 10)),
            CURLOPT_HTTPHEADER     => $formatted,
            CURLOPT_HEADERFUNCTION => static function ($_curl, string $line) use (&$received): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $received[trim($parts[0])] = trim($parts[1]);
                } elseif (trim($line) !== '' && str_starts_with($line, 'HTTP/')) {
                    $received = [];
                }

                return $length;
            },
        ]);

        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($responseBody === false) {
            return new HttpResponse(0, $error !== '' ? $error : 'The request failed before any response.');
        }

        return new HttpResponse($status, (string) $responseBody, $received);
    }
}
