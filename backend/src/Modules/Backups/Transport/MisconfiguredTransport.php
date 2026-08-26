<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

/**
 * A `backup.dsn` that was filled in and cannot be parsed.
 *
 * **A malformed DSN must never read as "no remote configured".** That is the
 * failure this class exists to prevent, and it is the worst one available
 * here: a club that typed a DSN believes its archives are leaving the host, so
 * a silent fallback to local-only would leave it holding the exact belief
 * ADR-0049 was written to destroy — *"we have off-site backups"* — with
 * nothing behind it.
 *
 * So the refusal is a transport that fails, loudly, once per run, saying which
 * word to edit. Not an exception in a constructor: that would take down every
 * request through the container, including the admin panel a volunteer needs
 * in order to read the complaint.
 *
 * Part of #691, epic #686.
 */
final class MisconfiguredTransport implements BackupTransport
{
    public function __construct(private readonly string $why)
    {
    }

    public function describe(): string
    {
        return 'misconfigured remote';
    }

    public function upload(string $localPath, int $budgetSeconds): TransportResult
    {
        return TransportResult::failed($this->why);
    }

    public function list(): array
    {
        throw new BackupTransportException($this->why);
    }

    public function delete(RemoteArchive $archive): bool
    {
        return false;
    }
}
