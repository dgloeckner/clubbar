<?php

namespace App\Http\Modules\Dashboard\DTOs;

/**
 * AlertsDto
 *
 * Alerts for admin attention.
 * Each alert includes count, severity level, and message.
 *
 * Currently supports:
 * - sepa_issues: Members with incomplete SEPA data
 *
 * Severity levels: 'none', 'warning', 'error'
 *
 * Read-only immutable DTO.
 */
readonly class AlertsDto
{
    public function __construct(
        public SepaAlertDto $sepa_issues,
    ) {}

    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        return [
            'sepa_issues' => $this->sepa_issues->toArray(),
        ];
    }
}

/**
 * SepaAlertDto
 *
 * SEPA-specific alert data.
 */
readonly class SepaAlertDto
{
    public function __construct(
        public int $count,
        public string $severity,
        public string $message,
    ) {}

    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'severity' => $this->severity,
            'message' => $this->message,
        ];
    }
}
