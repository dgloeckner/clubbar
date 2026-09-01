<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Documents;

use App\Shared\Logging\Logger;

/**
 * The real fetcher: one GET, bounded, following redirects (#780).
 *
 * Redirects are followed because a club's CMS moves files and leaves one
 * behind — Kirby, which the reference installation runs, does exactly that. The
 * cap is low: a redirect chain longer than three is a misconfiguration, and
 * following it costs an applicant's request.
 *
 * The size cap is the part worth stating. This runs inside the applicant's own
 * submission request, against a URL a club administrator typed, so a mistyped
 * host serving something enormous would otherwise hold that request open until
 * the timeout while filling memory. A mandate template is a few hundred
 * kilobytes; ten megabytes is generous and still bounded.
 */
class CurlTemplateFetcher implements TemplateFetcher
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;

    public function __construct(
        private Logger $logger,
    ) {}

    public function fetch(string $url, int $timeoutSeconds = 10): ?string
    {
        if (!function_exists('curl_init')) {
            $this->logger->warning('Cannot fetch the club document: ext-curl is not available');

            return null;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            // Stop reading the moment the response is implausible, rather than
            // downloading it all and then rejecting it.
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn($h, $expected, $received): int
                => $received > self::MAX_BYTES ? 1 : 0,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            // The URL, the status and nothing else. This is club configuration,
            // not personal data — and the applicant whose request this was is
            // deliberately not named here (ADR-0052 decision 9).
            $this->logger->warning('Could not fetch the club document', [
                'url' => $url,
                'status' => $status,
                'error' => $error,
            ]);

            return null;
        }

        return $body;
    }
}
