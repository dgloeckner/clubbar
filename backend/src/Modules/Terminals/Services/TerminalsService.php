<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Services;

use App\Modules\Terminals\DTOs\TerminalDto;
use App\Modules\Terminals\DTOs\TerminalWithTokenDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Services\AuditService;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Modules\Auth\Services\TokenService;
use App\Shared\Config\AppConfig;

class TerminalsService
{
    public function __construct(
        private TerminalsRepository $terminalsRepository,
        private AuditService $auditService,
        private AppConfig $config,
    ) {}

    public function listTerminals(int $limit, int $offset, ?bool $isActive = null): PaginatedResultDto
    {
        $result = $this->terminalsRepository->listPaginated($limit, $offset, $isActive);
        $items = array_map(fn($row) => TerminalDto::fromRow($row)->toArray(), $result['items']);

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    public function getTerminal(string $terminalId): TerminalDto
    {
        $terminal = $this->terminalsRepository->findById($terminalId);
        if (!$terminal) throw NotFoundException::forResource('Terminal', $terminalId);
        return TerminalDto::fromRow($terminal);
    }

    public function createTerminal(string $name, string $deviceId, ?string $adminUserId = null): array
    {
        $existing = $this->terminalsRepository->findByDeviceId($deviceId);
        if ($existing) throw new DuplicateResourceException('Device ID already exists');

        $plainToken = TokenService::generateTerminalToken();
        $hash = TokenService::hashToken($plainToken);

        $terminal = $this->terminalsRepository->create([
            'name' => $name,
            'device_id' => $deviceId,
            'api_token_hash' => $hash,
            'token_ttl_days' => $this->config->tokenTtlDays,
            'is_active' => true,
        ]);

        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminal['id'],
            newValues: [
                'name' => $name,
                'device_id' => $deviceId,
                'token_expires_at' => $terminal['token_expires_at'],
            ],
            adminUserId: $adminUserId,
        );

        return [
            'terminal' => TerminalWithTokenDto::fromRowWithToken($terminal, $plainToken),
            'plaintext_token' => $plainToken,
        ];
    }

    public function updateTerminal(string $terminalId, ?string $name = null, ?bool $isActive = null, ?string $adminUserId = null): TerminalDto
    {
        $data = [];
        if ($name !== null) $data['name'] = $name;
        if ($isActive !== null) $data['is_active'] = $isActive ? 1 : 0;

        $terminal = $this->terminalsRepository->updateById($terminalId, $data);
        if (!$terminal) throw NotFoundException::forResource('Terminal', $terminalId);

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            newValues: $data,
            adminUserId: $adminUserId,
        );

        return TerminalDto::fromRow($terminal);
    }

    public function deleteTerminal(string $terminalId, ?string $adminUserId = null): void
    {
        $this->terminalsRepository->updateById($terminalId, ['is_active' => 0]);

        $this->auditService->log(
            action: AuditAction::DEACTIVATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            newValues: ['is_active' => false],
            adminUserId: $adminUserId,
        );
    }

    public function rotateToken(string $terminalId, ?string $adminUserId = null): array
    {
        $plainToken = TokenService::generateTerminalToken();
        $hash = TokenService::hashToken($plainToken);

        $terminal = $this->terminalsRepository->rotateToken($terminalId, $hash, $this->config->tokenTtlDays);

        if (!$terminal) throw NotFoundException::forResource('Terminal', $terminalId);

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            newValues: [
                'api_token' => '[ROTATED]',
                'token_expires_at' => $terminal['token_expires_at'],
            ],
            adminUserId: $adminUserId,
        );

        return [
            'terminal' => TerminalWithTokenDto::fromRowWithToken($terminal, $plainToken),
            'plaintext_token' => $plainToken,
        ];
    }

    public function revokeAccess(string $terminalId, ?string $adminUserId = null): void
    {
        $this->terminalsRepository->updateById($terminalId, [
            'api_token_hash' => null,
            'token_issued_at' => null,
            'token_expires_at' => null,
            'is_active' => 0,
        ]);

        $this->auditService->log(
            action: AuditAction::DEACTIVATE,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            newValues: ['api_token_hash' => null, 'is_active' => false],
            adminUserId: $adminUserId,
        );
    }
}
