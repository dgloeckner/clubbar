<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * What one backup run did, in the shape the entrypoint prints and #693 reports.
 *
 * A run has more than two outcomes and flattening them to a boolean would lose
 * the distinctions that matter to a volunteer reading crontab output at 03:05:
 * "skipped because another run holds the lock" is not "skipped because it ran
 * an hour ago", and a run that produced an archive *and* a finding is still a
 * run that produced an archive.
 *
 * There is no run id, because there is no run row to identify: the archive's
 * filename names the artifact and its header describes it (ADR-0049 decision 8).
 */
final class BackupOutcome
{
    /**
     * @param list<string> $findings things that need somebody's attention
     */
    private function __construct(
        public readonly string $status,
        public readonly string $summary,
        public readonly ?string $filename = null,
        public readonly int $bytes = 0,
        public readonly array $findings = [],
        public readonly int $prunedArchives = 0,
    ) {
    }

    /**
     * @param list<string> $findings
     */
    public static function written(
        string $filename,
        int $bytes,
        array $findings,
        int $prunedArchives,
        ?string $remoteSummary = null,
    ): self {
        return new self(
            'written',
            sprintf('Wrote %s (%s bytes).', $filename, number_format($bytes)),
            $filename,
            $bytes,
            $findings,
            $prunedArchives,
            $remoteSummary,
        );
    }

    public static function skipped(string $why): self
    {
        return new self('skipped', $why);
    }

    /** @param list<string> $findings */
    public static function failed(string $why, array $findings = []): self
    {
        return new self('failed', $why, null, 0, $findings);
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
