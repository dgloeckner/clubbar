<?php

declare(strict_types=1);

namespace App\Modules\Security\DTOs;

/**
 * What one re-encryption batch did (ADR-0036 rotation).
 *
 * `skipped` is not a failure: it counts rows a concurrent member edit re-sealed
 * under the new key while this batch was reading them. They are already where
 * the rotation wants them, so they simply leave the backlog without this batch
 * touching them.
 */
final readonly class KeyRotationBatchDto
{
    public function __construct(
        public string $sourceKeyId,
        public string $targetKeyId,
        public int $processed,
        public int $skipped,
        public int $remaining,
    ) {}

    /** Nothing is left on the source key — the rotation can be completed. */
    public function isDrained(): bool
    {
        return $this->remaining === 0;
    }

    public function toArray(): array
    {
        return [
            'source_key_id' => $this->sourceKeyId,
            'target_key_id' => $this->targetKeyId,
            'processed' => $this->processed,
            'skipped' => $this->skipped,
            'remaining' => $this->remaining,
            'drained' => $this->isDrained(),
        ];
    }
}
