<?php

namespace App\Http\Modules\Terminals\DTOs;

/**
 * TerminalDto - Data Transfer Object for Terminal Responses
 *
 * Standard terminal response (list, detail, update).
 * Never includes plaintext API token or token hash.
 *
 * Implements Pattern 003: Data Transfer Objects
 */
readonly class TerminalDto
{
    public function __construct(
        public string $id,
        public string $name,
        public string $device_id,
        public bool $is_active,
        public ?string $last_sync_at = null,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}

    /**
     * Convert to array for JSON serialization.
     *
     * Response format per OpenAPI spec.
     * Note: api_token_hash is never included in responses.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'device_id' => $this->device_id,
            'is_active' => $this->is_active,
            'last_sync_at' => $this->last_sync_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
