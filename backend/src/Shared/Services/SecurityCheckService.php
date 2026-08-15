<?php

declare(strict_types=1);

namespace App\Shared\Services;

use App\Modules\Notifications\Services\MailDeliveryCheck;
use App\Shared\Config\AppConfig;
use App\Shared\Config\DataDirectory;
use App\Shared\DTOs\SecurityReportDto;
use App\Shared\Security\SecurityCheckContext;
use App\Shared\Security\SecuritySelfCheck;

/**
 * The admin-facing surface of the security self-check (#247, ADR-0031 decision 3).
 *
 * The installer answers "is this host safe to install on?" once. This answers
 * the question that outlives it: *did a host change break something since?* A
 * tariff migration, a webserver swap or an `AllowOverride None` can turn the
 * mandates into public URLs months after a green installation, with no
 * application error and nothing in the log to notice.
 *
 * It runs the same engine over the process that is serving this request, which
 * is the only process whose effective configuration is the one that matters.
 */
class SecurityCheckService
{
    /**
     * @param MailDeliveryCheck|null $mailDeliveryCheck Appends the delivery rows
     *        (#406). Optional because the engine has callers with no database:
     *        `install.php` runs before there is one, and the package smoke test
     *        checks a deployment rather than an installation.
     */
    public function __construct(
        private readonly AppConfig $config,
        private readonly ?MailDeliveryCheck $mailDeliveryCheck = null,
    ) {}

    /**
     * @param array<string,mixed> $serverParams The request's server parameters.
     */
    public function check(array $serverParams): SecurityReportDto
    {
        $findings = [
            ...SecuritySelfCheck::run($this->context($serverParams)),
            ...$this->deliveryFindings(),
        ];

        return new SecurityReportDto(
            generatedAt: (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
            findings: $findings,
            summary: SecuritySelfCheck::summarize($findings),
        );
    }

    /**
     * The mail-delivery rows, or nothing when they cannot be produced.
     *
     * Wrapped because this endpoint's job is to *report* on a possibly broken
     * installation: a database that has gone away must not turn the security
     * report into a 500, which is the one moment somebody needs to read it.
     *
     * @return list<\App\Shared\Security\SecurityFinding>
     */
    private function deliveryFindings(): array
    {
        if ($this->mailDeliveryCheck === null) {
            return [];
        }

        try {
            return $this->mailDeliveryCheck->findings();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $serverParams
     */
    private function context(array $serverParams): SecurityCheckContext
    {
        $documentRoot = $this->documentRoot($serverParams);
        $https        = SecurityCheckContext::requestIsHttps($serverParams);
        $configFile   = DataDirectory::configPath($documentRoot);

        return new SecurityCheckContext(
            documentRoot: $documentRoot,
            dataDirectory: $this->config->dataDir,
            // A development checkout has no config.php at all — it is
            // configured through the environment — and a row about a file that
            // does not exist would be noise, not a finding.
            configFile: is_file($configFile) ? $configFile : null,
            expectedSessionSavePath: $this->config->sessionSavePath,
            https: $https,
            debug: $this->config->debug,
            baseUrlCandidates: SecurityCheckContext::baseUrlCandidatesFrom($serverParams, $https),
            // `/api/health` proves the base URL reaches *this* application and
            // not some other vhost that happens to answer on the same name;
            // `README.txt` is the shared-hosting package's own file, and covers
            // an installation whose database is down.
            controlPaths: ['/api/health', '/README.txt'],
        );
    }

    /**
     * The directory the webserver serves from.
     *
     * `DOCUMENT_ROOT` is what every deployment target sets, but a CLI or an
     * oddly configured SAPI may leave it empty — and the front controller's own
     * directory *is* the document root in the shared-hosting package, which is
     * the deployment this check exists for.
     *
     * @param array<string,mixed> $serverParams
     */
    private function documentRoot(array $serverParams): string
    {
        $root = (string) ($serverParams['DOCUMENT_ROOT'] ?? '');
        if ($root !== '' && is_dir($root)) {
            return rtrim($root, '/');
        }

        $script = (string) ($serverParams['SCRIPT_FILENAME'] ?? '');

        return $script !== '' ? rtrim(dirname($script), '/') : rtrim($this->config->dataDir, '/');
    }
}
