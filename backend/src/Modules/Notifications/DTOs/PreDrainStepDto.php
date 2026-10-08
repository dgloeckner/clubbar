<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/**
 * What one step of {@see \App\Modules\Notifications\Services\PreDrainTasks} did.
 *
 * Shaped for the two consumers, which want different things from it: the CLI
 * entrypoint prints `lines` to stdout and `alert`/`error` to stderr, the URL
 * trigger reads none of it — a scheduler cannot act on counts, and what went
 * wrong is already in the application log by the time this is returned.
 *
 * | Field | Meaning |
 * |---|---|
 * | `step` | The step's name, as the warning line and the log entry spell it |
 * | `lines` | What the step has to say on an ordinary run — empty when it has nothing |
 * | `alert` | Something worth stderr although the step succeeded (an anomaly opened, a warning queued) |
 * | `error` | The step threw; its message. The other steps still ran |
 */
final readonly class PreDrainStepDto
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public string $step,
        public array $lines = [],
        public ?string $alert = null,
        public ?string $error = null,
    ) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
