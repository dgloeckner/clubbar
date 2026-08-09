<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Ask this host, over HTTP, what it answers for one of its own URLs.
 *
 * The self-check needs this because whether `.htaccess` is honoured cannot be
 * read out of any configuration the application can see: it is a property of
 * the hosting account, it changes without notice, and the failure mode is
 * silent — the same files, the same install, suddenly public. The only honest
 * answer comes from the webserver itself.
 *
 * Both transports are best-effort and never throw: a host that permits neither
 * cURL nor `allow_url_fopen` yields a null status, which the engine reports as
 * "could not be measured" rather than as a pass.
 *
 * Dependency-free on purpose: `install.php` runs before Composer's autoloader
 * exists and loads this file by path.
 */
final class HttpProbe
{
    private const TIMEOUT_SECONDS = 4;

    /**
     * Status code and response headers for $url, or a null status when this
     * host cannot fetch its own URLs.
     *
     * @return array{status:int|null,headers:array<string,string>}
     */
    public static function fetch(string $url): array
    {
        if (function_exists('curl_init')) {
            return self::fetchWithCurl($url);
        }

        return self::fetchWithStreams($url);
    }

    /**
     * Public only so a test can reach it: which transport `fetch()` picks is
     * decided by the host, and the fallback is the one a host that disabled
     * cURL will be using — exactly the kind of host this check exists for.
     *
     * @internal
     * @return array{status:int|null,headers:array<string,string>}
     */
    public static function fetchWithCurl(string $url): array
    {
        $headers = [];

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
            // A shared host's certificate is the host's business; this request
            // asks two questions — the status code and the headers — and never
            // reads the body.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADERFUNCTION => static function ($_curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return ['status' => $status > 0 ? $status : null, 'headers' => $headers];
    }

    /**
     * @internal see {@see fetchWithCurl()}
     * @return array{status:int|null,headers:array<string,string>}
     */
    public static function fetchWithStreams(string $url): array
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return ['status' => null, 'headers' => []];
        }

        $context = stream_context_create([
            'http' => [
                'method'         => 'HEAD',
                'timeout'        => self::TIMEOUT_SECONDS,
                'ignore_errors'  => true,
                'follow_location' => 0,
            ],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        @file_get_contents($url, false, $context);

        $status  = null;
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                // A redirect chain would restart the header block; the last
                // status line is the one that describes the response we got.
                $status = (int) $matches[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return ['status' => $status, 'headers' => $headers];
    }
}
