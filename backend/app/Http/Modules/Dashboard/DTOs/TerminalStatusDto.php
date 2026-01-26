<?php

namespace App\Http\Modules\Dashboard\DTOs;

/**
 * TerminalStatusDto
 *
 * Terminal device status with connectivity information.
 * Status is computed from last_sync_at:
 * - online: synced within 24 hours
 * - offline: no sync or synced > 24 hours ago
 * - disabled: terminal inactive
 *
 * Read-only immutable DTO.
 */
readonly class TerminalStatusDto
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $is_active,
        public ?string $last_sync_at,
        public string $status,
    ) {}

    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'last_sync_at' => $this->last_sync_at,
            'status' => $this->status,
        ];
    }
}
