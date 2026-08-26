<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

/**
 * What one push attempt did, in the shape the journal records and #693 reports.
 *
 * Four outcomes, and the third is the one a boolean would destroy: **partial**
 * is a success in progress, not a failure. A club on a slow uplink whose
 * archive needs three nights to leave the building is working correctly, and
 * reporting that as a failure would train its operator to ignore the report by
 * the second week.
 *
 * Part of #691, epic #686.
 */
final readonly class TransportResult
{
    private function __construct(
        public string $status,
        public string $summary,
        public int $bytesSent = 0,
        public ?string $remotePath = null,
    ) {
    }

    public static function uploaded(string $remotePath, int $bytesSent): self
    {
        return new self(
            'uploaded',
            sprintf('Uploaded %s (%s bytes this run).', $remotePath, number_format($bytesSent)),
            $bytesSent,
            $remotePath,
        );
    }

    /** Sent as far as the run's budget allowed; the sidecar carries the rest. */
    public static function partial(string $remotePath, int $bytesSent, int $uploaded, int $size): self
    {
        return new self(
            'partial',
            sprintf(
                'Sent %s bytes of %s this run; %s of %s bytes are now on the remote. The next '
                . 'run continues where this one stopped.',
                number_format($bytesSent),
                $remotePath,
                number_format($uploaded),
                number_format($size),
            ),
            $bytesSent,
            $remotePath,
        );
    }

    /** No DSN: the archive is on the webspace and nowhere else, which is said out loud. */
    public static function notConfigured(): self
    {
        return new self(
            'skipped',
            'backup.dsn is not configured, so the archive stays on the webspace. A backup on the '
            . 'same hosting account as the database is not off-site: one suspended tariff takes '
            . 'both. Configure a remote, or copy the archives off by hand on a schedule.',
        );
    }

    public static function failed(string $why): self
    {
        return new self('failed', $why);
    }

    public function reachedTheRemote(): bool
    {
        return $this->status === 'uploaded';
    }

    public function needsAttention(): bool
    {
        return $this->status === 'failed';
    }
}
