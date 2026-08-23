<?php

declare(strict_types=1);

namespace App\Modules\CreditLimits\Services;

use App\Modules\CreditLimits\Domain\CreditLimitPolicy;
use App\Modules\CreditLimits\DTOs\CreditLimitConfigDto;
use App\Modules\CreditLimits\Repositories\CreditLimitConfigRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use PDOException;

/**
 * The club's ceiling and warning band (ADR-0046).
 *
 * Reading it is deliberately unbreakable. Two callers ask for a limit on paths
 * that must not fail — the dashboard, and the nightly Deckelauszug run — and
 * both exist on installations where `composer install` has landed and this
 * module's migration has not. A missing table there is expected rather than
 * exceptional, so {@see policy()} degrades to the shipped defaults, which are
 * exactly the constants this epic replaced. Same fail-soft contract
 * `InstanceConfigService` gives `/health`, for the same reason.
 */
class CreditLimitConfigService
{
    public function __construct(
        private CreditLimitConfigRepository $creditLimitConfigRepository,
        private AuditService $auditService,
    ) {}

    public function getConfig(): CreditLimitConfigDto
    {
        return CreditLimitConfigDto::fromRow($this->readRow() ?? []);
    }

    /**
     * The club's settings as the rule that resolves a member's ceiling.
     *
     * Every backend answer about a limit comes through here, so that the
     * override-or-default rule stays in one place.
     */
    public function policy(): CreditLimitPolicy
    {
        return $this->getConfig()->toPolicy();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function updateConfig(array $attributes, string $adminUserId): ?CreditLimitConfigDto
    {
        $old = $this->creditLimitConfigRepository->getConfig();
        $config = $this->creditLimitConfigRepository->updateConfig($attributes, $adminUserId);

        if (!$config) {
            return null;
        }

        // Both numbers, before and after. What a club may run up on the Deckel
        // is a money decision, and the audit row is what says who moved it.
        $this->auditService->log(
            action: AuditAction::UPDATE,
            entityType: EntityType::CREDIT_LIMIT_CONFIG,
            entityId: '1',
            oldValues: $old === null ? null : self::auditable($old),
            newValues: self::auditable($config),
            adminUserId: $adminUserId,
        );

        return CreditLimitConfigDto::fromRow($config);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readRow(): ?array
    {
        try {
            return $this->creditLimitConfigRepository->getConfig();
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, int>
     */
    private static function auditable(array $row): array
    {
        return [
            'default_limit_cents' => (int) ($row['default_limit_cents'] ?? 0),
            'warn_threshold_percent' => (int) ($row['warn_threshold_percent'] ?? 0),
        ];
    }
}
