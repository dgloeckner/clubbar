<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Services;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;

/**
 * ADR-0035: a terminal that detected a backend instance_id mismatch hard-blocks
 * sync until staff confirm it is safe to trust this backend again. This is
 * that confirmation — it never clears anything server-side (there is nothing
 * to clear; the mismatch is entirely local terminal state), it only records
 * that the moment happened and hands back the value to re-pair against.
 */
class PairingService
{
    public function __construct(
        private InstanceConfigService $instanceConfigService,
        private AuditService $auditService,
    ) {}

    public function acknowledgeRepair(string $terminalId): ?string
    {
        $instanceId = $this->instanceConfigService->getInstanceId();

        $this->auditService->log(
            action: AuditAction::TERMINAL_REPAIR,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            oldValues: null,
            newValues: ['instance_id' => $instanceId],
            adminUserId: null,
        );

        return $instanceId;
    }
}
