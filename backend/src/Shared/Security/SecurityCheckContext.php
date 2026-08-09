<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * What the security self-check needs to know about the deployment it is
 * measuring (#247, ADR-0031 decision 3).
 *
 * The engine measures a *process* — `ini_get()` reports what this PHP process
 * ended up with, and the HTTP probes ask this host about this document root.
 * Everything that differs between the three surfaces that run it lives here
 * instead of being read out of a superglobal deep inside a check:
 *
 * | | Installer | Admin endpoint | Package smoke test |
 * |---|---|---|---|
 * | Data directory | the one `DataDirectory::probe()` would choose | the one in use | the one in use |
 * | `config.php` | may not exist yet | exists | exists |
 * | Expected session save path | none — the installer's own session is not the application's | `storage/sessions` | `storage/sessions` |
 * | Base URL | derived from the wizard's own request | derived from the admin's request | passed in |
 *
 * Dependency-free on purpose: `install.php` runs before Composer's autoloader
 * exists and loads this file by path.
 */
final class SecurityCheckContext
{
    /**
     * @param string             $documentRoot        Directory the webserver serves from.
     * @param string             $dataDirectory       Where `config.php`, `storage/` and `logs/` live (or would).
     * @param string|null        $configFile          Absolute path to `config.php`, or null when there is none yet.
     * @param string|null        $dataDirectoryReason Why the data directory ended up where it is, when that is known.
     * @param string|null        $expectedSessionSavePath Path `session.save_path` must name, or null to skip the row.
     * @param bool               $https               Whether the request that triggered this check arrived over TLS.
     * @param bool               $debug               Whether this deployment runs with APP_DEBUG on.
     * @param list<string>       $baseUrlCandidates   URLs this installation may answer on, most likely first.
     * @param list<string>       $controlPaths        Paths that must return 200, used to validate a base URL.
     */
    public function __construct(
        public readonly string $documentRoot,
        public readonly string $dataDirectory,
        public readonly ?string $configFile = null,
        public readonly ?string $dataDirectoryReason = null,
        public readonly ?string $expectedSessionSavePath = null,
        public readonly bool $https = false,
        public readonly bool $debug = false,
        public readonly array $baseUrlCandidates = [],
        public readonly array $controlPaths = ['/README.txt'],
    ) {}

    /**
     * True when the data directory is somewhere the webserver could never
     * serve from, which is the claim ADR-0031 decision 2 is actually about.
     */
    public function dataIsOutsideDocumentRoot(): bool
    {
        return !str_starts_with(
            rtrim($this->dataDirectory, '/') . '/',
            rtrim($this->documentRoot, '/') . '/'
        );
    }

    /**
     * Base URLs this installation might answer on *from the server itself*.
     *
     * The host header is the honest first guess and the right one on real
     * hosting. It is not enough on its own: behind a port mapping or a reverse
     * proxy it names a port nothing local is listening on, so the loopback
     * candidate follows. Each is validated with a control fetch before anything
     * is concluded from it.
     *
     * @param array<string,mixed> $server
     * @return list<string>
     */
    public static function baseUrlCandidatesFrom(array $server, bool $https): array
    {
        $scheme = $https ? 'https' : 'http';
        $header = (string) ($server['HTTP_HOST'] ?? '');
        $host   = explode(':', $header)[0];

        $candidates = [];
        if ($header !== '') {
            $candidates[] = "{$scheme}://{$header}";
        }
        if ($host !== '' && $host !== $header) {
            $candidates[] = "{$scheme}://{$host}";
        }
        $candidates[] = "{$scheme}://127.0.0.1";

        return array_values(array_unique($candidates));
    }

    /**
     * True when the browser reached this process over TLS — directly, or
     * through a reverse proxy that terminated it (common on shared hosting).
     *
     * @param array<string,mixed> $server
     */
    public static function requestIsHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }

        $forwarded = $server['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ($forwarded !== '') {
            return strtolower(trim(explode(',', (string) $forwarded)[0])) === 'https';
        }

        return ((int) ($server['SERVER_PORT'] ?? 0)) === 443;
    }
}
