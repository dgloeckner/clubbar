<?php

namespace App\Http\Modules\Terminals\DTOs;

/**
 * TerminalWithTokenDto - Terminal Response with Plaintext API Token
 *
 * Used only for terminal creation and token rotation operations.
 * Includes plaintext API token which is shown only once.
 * Never used for list/detail responses after initial creation.
 *
 * Security Note:
 * - Plaintext token is displayed to user one time during creation/rotation
 * - User must save this value; it is never shown again
 * - Token hash (never plaintext) is stored in database
 * - Subsequent API responses use TerminalDto (no token field)
 *
 * Implements Pattern 003: Data Transfer Objects
 */
final readonly class TerminalWithTokenDto extends TerminalDto
{
    public function __construct(
        string $id,
        string $name,
        string $device_id,
        bool $is_active,
        public string $api_token,  // 64-character hex token (shown once)
        ?string $last_sync_at = null,
        ?string $created_at = null,
        ?string $updated_at = null,
    ) {
        parent::__construct(
            id: $id,
            name: $name,
            device_id: $device_id,
            is_active: $is_active,
            last_sync_at: $last_sync_at,
            created_at: $created_at,
            updated_at: $updated_at,
        );
    }

    /**
     * Convert to array for JSON serialization.
     *
     * Includes plaintext API token for one-time display.
     *
     * @return array
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'api_token' => $this->api_token,
        ]);
    }
}
