<?php

declare(strict_types=1);

namespace App\Modules\Terminals\DTOs;

use App\Modules\Terminals\Enums\TerminalAnomalyKind;
use App\Shared\Utils\DateFormatter;

/**
 * One open anomaly row, as returned to an admin who wants to know *what* is
 * open before deciding whether to acknowledge it (ADR-0041 §4).
 *
 * `Terminal.open_anomaly_count` answers "is something open"; this is the
 * detail behind that count — the piece the marker in the terminals list and
 * the credentials board cannot carry on its own, because acknowledging
 * requires the anomaly's id, not just its existence.
 */
final readonly class TerminalAnomalyDto
{
    public function __construct(
        public string $id,
        public TerminalAnomalyKind $kind,
        public string $firstDetectedAt,
        public string $lastDetectedAt,
        public int $occurrenceCount,
        public array $details,
    ) {}

    public static function fromRow(array $row): self
    {
        $details = $row['details'] ?? null;
        if (is_string($details)) {
            $details = json_decode($details, true) ?? [];
        }

        return new self(
            id: (string) $row['id'],
            kind: TerminalAnomalyKind::from((string) $row['kind']),
            firstDetectedAt: (string) $row['first_detected_at'],
            lastDetectedAt: (string) $row['last_detected_at'],
            occurrenceCount: (int) $row['occurrence_count'],
            details: is_array($details) ? $details : [],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            // The severity a lone anomaly reads as, mirroring the rule
            // `DashboardService::terminalAnomalyAlert()` applies across a
            // whole set — concurrent use is the one kind with no routine
            // innocent cause.
            'severity' => $this->kind->severity(),
            'first_detected_at' => DateFormatter::toUtcIso($this->firstDetectedAt),
            'last_detected_at' => DateFormatter::toUtcIso($this->lastDetectedAt),
            'occurrence_count' => $this->occurrenceCount,
            'details' => $this->details,
        ];
    }
}
