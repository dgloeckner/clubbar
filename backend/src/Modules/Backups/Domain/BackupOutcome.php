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
        /** What the push to the remote store did, or null when there is no remote. */
        public readonly ?string $remoteSummary = null,
        /**
         * Does the push need somebody to look at it? `null` when there is no
         * remote configured — a legitimate local-only setup, not a failure.
         *
         * The machine-readable companion to {@see $remoteSummary}, which is
         * prose for a human. The external monitor (#712) has to tell "no
         * archive at all" from "an archive that is still only on this webspace"
         * without reading English, because the two have different urgency: the
         * second still restores an accidental deletion, it just does not
         * survive losing the hosting account.
         *
         * Deliberately `needsAttention()` and not `reachedTheRemote()`: a
         * **partial** upload has not reached the store either, but it resumes
         * on the next run, and alarming nightly for a large database on a slow
         * line is how a monitor gets switched off. This mirrors the question
         * the panel and the failure mail already ask, so the alarm can never be
         * noisier than they are.
         */
        public readonly ?bool $remoteNeedsAttention = null,
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
        ?bool $remoteNeedsAttention = null,
    ): self {
        return new self(
            'written',
            sprintf('Wrote %s (%s bytes).', $filename, number_format($bytes)),
            $filename,
            $bytes,
            $findings,
            $prunedArchives,
            $remoteSummary,
            $remoteNeedsAttention,
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
