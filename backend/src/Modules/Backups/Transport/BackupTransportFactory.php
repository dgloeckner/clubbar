<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

use App\Modules\Backups\Domain\BackupDsn;
use App\Modules\Backups\Domain\BackupDsnException;
use App\Shared\Http\HttpClient;
use App\Shared\Logging\Logger;

/**
 * `backup.dsn` in, a transport out — or null, meaning local-only.
 *
 * Three outcomes, and keeping them three is the whole job:
 *
 * | `backup.dsn` | Result | What the club is told |
 * |---|---|---|
 * | empty | `null` | Nothing. Local-only is a legitimate configuration |
 * | valid `msgraph://` | {@see MsGraphTransport} | Nothing, until something fails |
 * | anything else | {@see MisconfiguredTransport} | A failure every run, naming the word to edit |
 *
 * The third row is the one that matters. Folding a malformed DSN into the
 * first would hand a club the belief that its archives are off-site while they
 * sit on the same webspace as the database — which is precisely the belief
 * ADR-0049 exists to destroy, arrived at by a typo.
 *
 * Part of #691, epic #686.
 */
final class BackupTransportFactory
{
    public static function fromConfig(
        ?string $dsn,
        ?string $clientSecret,
        HttpClient $http,
        Logger $logger,
    ): ?BackupTransport {
        if ($dsn === null || trim($dsn) === '') {
            return null;
        }

        try {
            $parsed = BackupDsn::parse($dsn);
        } catch (BackupDsnException $e) {
            $logger->error('backup.dsn cannot be used', ['message' => $e->getMessage()]);

            return new MisconfiguredTransport($e->getMessage());
        }

        if (trim((string) $clientSecret) === '') {
            // Deliberately not silent either: a DSN with no secret is a club
            // half-way through onboarding, and the half it has not done is the
            // one that makes uploads work.
            return new MisconfiguredTransport(
                'backup.dsn names a remote but backup.client_secret is empty, so the backup app '
                . 'cannot sign in. The secret is shown exactly once when it is minted and cannot be '
                . 'retrieved afterwards by anyone, including Microsoft — mint a new one with '
                . 'scripts/setup-msgraph-backup.ps1 -RotateSecretOnly.'
            );
        }

        return new MsGraphTransport($parsed, (string) $clientSecret, $http, $logger);
    }
}
