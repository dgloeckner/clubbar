<?php

declare(strict_types=1);

namespace App\Modules\Instance\Services;

use App\Modules\Instance\DTOs\InstanceConfigDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\Instance\Repositories\InstanceConfigRepository;
use App\Shared\Services\AuditService;

class InstanceConfigService
{
    private const DEFAULT_NAME = 'Club Bar';

    public function __construct(
        private InstanceConfigRepository $instanceConfigRepository,
        private AuditService $auditService,
    ) {}

    public function getConfig(): InstanceConfigDto
    {
        $config = $this->instanceConfigRepository->getConfig();
        return InstanceConfigDto::fromRow($config ?? []);
    }

    /**
     * The instance name as used by callers that only need the string, such as
     * TotpService and the public /health check — no need to go through the
     * DTO for a single field.
     *
     * /health is unauthenticated and, per its own contract, must answer
     * before the database is necessarily migrated (CI — and any fresh
     * deployment — polls it to detect backend readiness *before* running
     * migrations). A missing instance_config table is therefore expected,
     * not exceptional, and falls back to the stock name rather than turning
     * an otherwise-healthy backend into a 500.
     */
    public function getInstanceName(): string
    {
        try {
            $config = $this->instanceConfigRepository->getConfig();
        } catch (\PDOException) {
            return self::DEFAULT_NAME;
        }

        $name = $config['instance_name'] ?? null;
        return $name !== null && $name !== '' ? $name : self::DEFAULT_NAME;
    }

    public function updateConfig(array $attributes, string $adminUserId): ?InstanceConfigDto
    {
        $old = $this->instanceConfigRepository->getConfig();
        $config = $this->instanceConfigRepository->updateConfig($attributes, $adminUserId);

        if (!$config) {
            return null;
        }

        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::INSTANCE_CONFIG,
            entityId: '1',
            oldValues: $old ? ['instance_name' => $old['instance_name'] ?? null] : null,
            newValues: ['instance_name' => $config['instance_name'] ?? null],
            adminUserId: $adminUserId,
        );

        return InstanceConfigDto::fromRow($config);
    }
}
