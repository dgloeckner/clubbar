<?php

declare(strict_types=1);

namespace App\Modules\Terminals\DTOs;

/**
 * What one anomaly scan did (ADR-0041 §2).
 *
 * `opened` and `refreshed` are counted apart on purpose. An ongoing anomaly is
 * refreshed on every tick and is not news; an opened one is the moment somebody
 * needs to be told, and is the only count that maps to a mail going out.
 */
final readonly class AnomalyScanResultDto
{
    public function __construct(
        public int $terminalsExamined = 0,
        public int $opened = 0,
        public int $refreshed = 0,
        public int $mailsQueued = 0,
        public int $sightingsPruned = 0,
        public float $durationSeconds = 0.0,
    ) {}

    public function summary(): string
    {
        return sprintf(
            'terminals=%d opened=%d refreshed=%d mails=%d pruned=%d duration=%.2fs',
            $this->terminalsExamined,
            $this->opened,
            $this->refreshed,
            $this->mailsQueued,
            $this->sightingsPruned,
            $this->durationSeconds,
        );
    }

    public function toArray(): array
    {
        return [
            'terminals_examined' => $this->terminalsExamined,
            'opened' => $this->opened,
            'refreshed' => $this->refreshed,
            'mails_queued' => $this->mailsQueued,
            'sightings_pruned' => $this->sightingsPruned,
            'duration_seconds' => round($this->durationSeconds, 3),
        ];
    }
}
