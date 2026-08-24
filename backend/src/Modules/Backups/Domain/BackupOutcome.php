<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * What one backup run did, in the shape the entrypoint prints and #693 reports.
 *
 * A run has more than two outcomes and flattening them to a boolean would lose
 * the distinctions that matter to a volunteer reading crontab output at 03:05:
 * "disabled" is not "failed", "skipped because another run holds the lock" is
 * not "skipped because it ran an hour ago", and a run that produced an archive
 * *and* a finding is still a run that produced an archive.
 */
final class BackupOutcome
{
    /**
     * @param list<string> $findings things that need somebody's attention
     * @param list<string> $manifestDrift tables that appeared, vanished or changed class
     * @param list<string> $unclassifiedTables included on a guess (ADR-0049 decision 1)
     */
    private function __construct(
        public readonly string $status,
        public readonly string $summary,
        public readonly ?string $runId = null,
        public readonly ?string $filename = null,
        public readonly int $bytes = 0,
        public readonly array $findings = [],
        public readonly array $manifestDrift = [],
        public readonly array $unclassifiedTables = [],
        public readonly int $prunedArchives = 0,
    ) {
    }

    /**
     * @param list<string> $findings
     * @param list<string> $manifestDrift
     * @param list<string> $unclassifiedTables
     */
    public static function written(
        string $runId,
        string $filename,
        int $bytes,
        array $findings,
        array $manifestDrift,
        array $unclassifiedTables,
        int $prunedArchives,
    ): self {
        return new self(
            'written',
            sprintf('Wrote %s (%s bytes).', $filename, number_format($bytes)),
            $runId,
            $filename,
            $bytes,
            $findings,
            $manifestDrift,
            $unclassifiedTables,
            $prunedArchives,
        );
    }

    public static function skipped(string $why): self
    {
        return new self('skipped', $why);
    }

    /** @param list<string> $findings */
    public static function failed(string $why, ?string $runId = null, array $findings = []): self
    {
        return new self('failed', $why, $runId, null, 0, $findings);
    }

    public function producedAnArchive(): bool
    {
        return $this->status === 'written';
    }

    public function needsAttention(): bool
    {
        return $this->status === 'failed' || $this->findings !== [];
    }
}
