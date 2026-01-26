<?php

namespace App\Http\Modules\Terminals\Services;

use App\Http\Modules\Terminals\DTOs\TerminalDto;
use App\Http\Modules\Terminals\DTOs\TerminalWithTokenDto;
use App\Http\Modules\Terminals\Repositories\TerminalsRepository;
use App\Models\Terminal;
use App\Services\TokenService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * TerminalsService - Business Logic for Terminal Device Management
 *
 * Handles terminal operations:
 * - CRUD operations (create, read, update, delete, list)
 * - Token generation and rotation
 * - Terminal activation/deactivation
 * - Access revocation
 * - Audit logging for all operations
 *
 * Implements Pattern 004: Service Layer
 * Implements Pattern 010: Base Service
 * Implements Pattern 016: Audit Logging for all changes
 * Implements ADR-0015: Device-level token authentication
 */
class TerminalsService extends BaseService
{
    /**
     * Initialize service with dependencies.
     *
     * @param TerminalsRepository $terminalsRepository
     * @param AuditService $auditService
     */
    public function __construct(
        private readonly TerminalsRepository $terminalsRepository,
        private readonly AuditService $auditService,
    ) {
        parent::__construct($terminalsRepository);
    }

    /**
     * List terminals with filtering and pagination.
     *
     * Supports optional filtering by is_active status.
     *
     * @param int $page Page number (1-indexed)
     * @param int $perPage Results per page
     * @param bool|null $isActive Filter by active status (null = no filter)
     * @return array With keys: data, pagination (with total, per_page, current_page, last_page)
     */
    public function listTerminals(
        int $page = 1,
        int $perPage = 20,
        ?bool $isActive = null,
    ): array {
        // Get paginated results from repository
        $result = $this->terminalsRepository->findAllPaginated($page, $perPage, $isActive);

        // Transform terminals to DTOs
        $data = array_map(
            fn($terminal) => $this->transform($terminal),
            $result['items']->all()
        );

        return [
            'data' => $data,
            'pagination' => [
                'total' => $result['total'],
                'per_page' => $result['per_page'],
                'current_page' => $result['page'],
                'last_page' => $result['total_pages'],
            ],
        ];
    }

    /**
     * Get terminal by ID.
     *
     * @param string $terminalId Terminal UUID
     * @return TerminalDto|null
     * @throws \Exception If terminal not found
     */
    public function getTerminal(string $terminalId): TerminalDto
    {
        $terminal = $this->terminalsRepository->findById($terminalId);
        if (!$terminal) {
            throw new \Exception("Terminal not found with ID: {$terminalId}", 404);
        }

        return $this->transform($terminal);
    }

    /**
     * Create new terminal with generated API token.
     *
     * Validates that device_id is unique (duplicate prevention).
     * Generates a secure 64-character hex token and stores bcrypt hash.
     * Logs audit entry for creation.
     *
     * @param string $name Terminal display name
     * @param string $deviceId Unique device identifier (e.g., hardware serial)
     * @param string|null $adminUserId UUID of admin creating terminal
     * @return array Contains 'terminal' (TerminalWithTokenDto) and 'plaintext_token' (64-char hex, shown once)
     * @throws \Exception If device_id already exists
     */
    public function createTerminal(
        string $name,
        string $deviceId,
        ?string $adminUserId = null,
    ): array {
        // Validate unique device_id
        $existing = $this->terminalsRepository->findByDeviceId($deviceId);
        if ($existing) {
            throw new \Exception("Device ID '{$deviceId}' already registered", 422);
        }

        // Generate secure API token (64-char hex)
        $plainToken = TokenService::generateTerminalToken();
        $tokenHash = TokenService::hashToken($plainToken);

        // Create terminal with hashed token
        $terminal = $this->terminalsRepository->create([
            'name' => $name,
            'device_id' => $deviceId,
            'api_token_hash' => $tokenHash,
            'is_active' => true,
        ]);

        // Log audit entry
        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminal->id,
            oldValues: null,
            newValues: [
                'name' => $terminal->name,
                'device_id' => $terminal->device_id,
                'is_active' => $terminal->is_active,
                'api_token' => '[GENERATED]',
            ],
            adminUserId: $adminUserId,
        );

        // Return terminal DTO with plaintext token (one-time show)
        $dto = $this->transformWithToken($terminal, $plainToken);

        return [
            'terminal' => $dto,
            'plaintext_token' => $plainToken,
        ];
    }

    /**
     * Update terminal (name and/or is_active status).
     *
     * Only updates explicitly provided fields.
     * Logs audit entry for changes.
     *
     * @param string $terminalId Terminal UUID
     * @param string|null $name New display name (null = no change)
     * @param bool|null $isActive New active status (null = no change)
     * @param string|null $adminUserId UUID of admin making this change
     * @return TerminalDto Updated terminal
     * @throws \Exception If terminal not found or no changes provided
     */
    public function updateTerminal(
        string $terminalId,
        ?string $name = null,
        ?bool $isActive = null,
        ?string $adminUserId = null,
    ): TerminalDto {
        // Fetch current terminal
        $terminal = $this->terminalsRepository->findById($terminalId);
        if (!$terminal) {
            throw new \Exception("Terminal not found with ID: {$terminalId}", 404);
        }

        // Prepare update attributes and audit values
        $updateAttrs = [];
        $oldValues = [];
        $newValues = [];

        if ($name !== null && $name !== $terminal->name) {
            $updateAttrs['name'] = $name;
            $oldValues['name'] = $terminal->name;
            $newValues['name'] = $name;
        }

        if ($isActive !== null && $isActive !== $terminal->is_active) {
            $updateAttrs['is_active'] = $isActive;
            $oldValues['is_active'] = $terminal->is_active;
            $newValues['is_active'] = $isActive;
        }

        // Require at least one change
        if (empty($updateAttrs)) {
            throw new \Exception('At least one field must be updated', 422);
        }

        // Perform update
        $updated = $this->terminalsRepository->updateById($terminalId, $updateAttrs);

        // Log audit entry
        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            oldValues: $oldValues,
            newValues: $newValues,
            adminUserId: $adminUserId,
        );

        return $this->transform($updated);
    }

    /**
     * Delete (soft-delete) terminal.
     *
     * Sets is_active = false to prevent future authentication.
     * Does not remove record from database (preserves audit trail).
     * Logs audit entry.
     *
     * @param string $terminalId Terminal UUID
     * @param string|null $adminUserId UUID of admin performing delete
     * @throws \Exception If terminal not found
     */
    public function deleteTerminal(
        string $terminalId,
        ?string $adminUserId = null,
    ): void {
        // Fetch current terminal
        $terminal = $this->terminalsRepository->findById($terminalId);
        if (!$terminal) {
            throw new \Exception("Terminal not found with ID: {$terminalId}", 404);
        }

        // Soft-delete by deactivating
        $this->terminalsRepository->updateById($terminalId, ['is_active' => false]);

        // Log audit entry
        $this->auditService->log(
            action: AuditAction::DEACTIVATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            oldValues: ['is_active' => $terminal->is_active],
            newValues: ['is_active' => false],
            adminUserId: $adminUserId,
        );
    }

    /**
     * Rotate terminal API token.
     *
     * Generates a new token, invalidates old token immediately,
     * and clears last_sync_at to force re-sync with new token.
     * Logs audit entry for token rotation.
     *
     * @param string $terminalId Terminal UUID
     * @param string|null $adminUserId UUID of admin rotating token
     * @return array Contains 'terminal' (TerminalWithTokenDto) and 'plaintext_token'
     * @throws \Exception If terminal not found
     */
    public function rotateToken(
        string $terminalId,
        ?string $adminUserId = null,
    ): array {
        // Fetch current terminal
        $terminal = $this->terminalsRepository->findById($terminalId);
        if (!$terminal) {
            throw new \Exception("Terminal not found with ID: {$terminalId}", 404);
        }

        // Generate new token
        $plainToken = TokenService::generateTerminalToken();
        $newTokenHash = TokenService::hashToken($plainToken);

        // Update terminal with new token hash and clear last_sync_at
        $updated = $this->terminalsRepository->updateById($terminalId, [
            'api_token_hash' => $newTokenHash,
            'last_sync_at' => null,  // Force re-sync with new token
        ]);

        // Log audit entry
        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            oldValues: ['api_token' => '[ROTATED]'],
            newValues: ['api_token' => '[GENERATED]'],
            adminUserId: $adminUserId,
        );

        // Return terminal DTO with new plaintext token (one-time show)
        $dto = $this->transformWithToken($updated, $plainToken);

        return [
            'terminal' => $dto,
            'plaintext_token' => $plainToken,
        ];
    }

    /**
     * Revoke terminal access.
     *
     * Removes API token (sets to NULL) and deactivates terminal.
     * Prevents any future authentication attempts.
     * Logs audit entry for access revocation.
     *
     * @param string $terminalId Terminal UUID
     * @param string|null $adminUserId UUID of admin revoking access
     * @throws \Exception If terminal not found
     */
    public function revokeAccess(
        string $terminalId,
        ?string $adminUserId = null,
    ): void {
        // Fetch current terminal
        $terminal = $this->terminalsRepository->findById($terminalId);
        if (!$terminal) {
            throw new \Exception("Terminal not found with ID: {$terminalId}", 404);
        }

        // Remove token and deactivate
        $this->terminalsRepository->updateById($terminalId, [
            'api_token_hash' => null,
            'is_active' => false,
        ]);

        // Log audit entry
        $this->auditService->log(
            action: AuditAction::DEACTIVATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            oldValues: [
                'api_token' => '[REVOKED]',
                'is_active' => $terminal->is_active,
            ],
            newValues: [
                'api_token' => null,
                'is_active' => false,
            ],
            adminUserId: $adminUserId,
        );
    }

    /**
     * Transform Terminal model to DTO (without token).
     *
     * @param \Illuminate\Database\Eloquent\Model $model Terminal model
     * @return TerminalDto
     */
    protected function transform(\Illuminate\Database\Eloquent\Model $model): mixed
    {
        /** @var Terminal $model */
        return new TerminalDto(
            id: $model->id,
            name: $model->name,
            device_id: $model->device_id,
            is_active: $model->is_active,
            last_sync_at: $model->last_sync_at?->toIso8601String(),
            created_at: $model->created_at->toIso8601String(),
            updated_at: $model->updated_at->toIso8601String(),
        );
    }

    /**
     * Transform Terminal model to DTO with plaintext token.
     *
     * Used only for create/rotate operations where token is shown once.
     * Never used in subsequent list/detail responses.
     *
     * @param Terminal $model
     * @param string $plaintextToken The 64-char hex token (shown only once)
     * @return TerminalWithTokenDto
     */
    private function transformWithToken(Terminal $model, string $plaintextToken): TerminalWithTokenDto
    {
        return new TerminalWithTokenDto(
            id: $model->id,
            name: $model->name,
            device_id: $model->device_id,
            is_active: $model->is_active,
            last_sync_at: $model->last_sync_at?->toIso8601String(),
            created_at: $model->created_at->toIso8601String(),
            updated_at: $model->updated_at->toIso8601String(),
            api_token: $plaintextToken,
        );
    }
}
