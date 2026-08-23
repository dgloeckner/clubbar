<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\CreditLimits\Services;

use App\Modules\CreditLimits\Domain\CreditLimitPolicy;
use App\Modules\CreditLimits\Repositories\CreditLimitConfigRepository;
use App\Modules\CreditLimits\Services\CreditLimitConfigService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * The club's half of ADR-0046, and the one thing about it that is not obvious:
 * **reading it must never be able to break a caller**.
 *
 * The dashboard and the nightly Deckelauszug run both ask for a limit, and both
 * run on installations where `composer install` has landed and the migration
 * has not. A missing table there is expected rather than exceptional, so it
 * degrades to the shipped defaults — the same fail-soft contract
 * `InstanceConfigService` already gives `/health`.
 */
class CreditLimitConfigServiceTest extends TestCase
{
    public function test_the_stored_configuration_becomes_the_policy(): void
    {
        $service = $this->service(['default_limit_cents' => 15_000, 'warn_threshold_percent' => 90]);

        $policy = $service->policy();

        $this->assertSame(15_000, $policy->defaultLimitCents);
        $this->assertSame(90, $policy->warnThresholdPercent);
    }

    public function test_a_missing_table_degrades_to_the_shipped_defaults(): void
    {
        $repository = $this->createMock(CreditLimitConfigRepository::class);
        $repository->method('getConfig')->willThrowException(new PDOException('no such table'));

        $policy = (new CreditLimitConfigService($repository, $this->createMock(AuditService::class)))->policy();

        $this->assertSame(CreditLimitPolicy::DEFAULT_LIMIT_CENTS, $policy->defaultLimitCents);
        $this->assertSame(CreditLimitPolicy::DEFAULT_WARN_THRESHOLD_PERCENT, $policy->warnThresholdPercent);
    }

    public function test_a_missing_row_degrades_to_the_shipped_defaults(): void
    {
        $policy = $this->service(null)->policy();

        $this->assertSame(CreditLimitPolicy::DEFAULT_LIMIT_CENTS, $policy->defaultLimitCents);
        $this->assertSame(CreditLimitPolicy::DEFAULT_WARN_THRESHOLD_PERCENT, $policy->warnThresholdPercent);
    }

    public function test_reading_the_configuration_answers_both_numbers(): void
    {
        $config = $this->service(['default_limit_cents' => 20_000, 'warn_threshold_percent' => 75])->getConfig();

        $this->assertSame(
            ['default_limit_cents' => 20_000, 'warn_threshold_percent' => 75],
            $config->toArray(),
        );
    }

    /**
     * Both numbers, before and after — a change to the club's ceiling is a
     * money decision, and the audit row is what says who made it.
     */
    public function test_an_update_audits_both_numbers_before_and_after(): void
    {
        $repository = $this->createMock(CreditLimitConfigRepository::class);
        $repository->method('getConfig')->willReturn(
            ['default_limit_cents' => 10_000, 'warn_threshold_percent' => 80],
        );
        $repository->method('updateConfig')->willReturn(
            ['default_limit_cents' => 25_000, 'warn_threshold_percent' => 60],
        );

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::UPDATE,
                EntityType::CREDIT_LIMIT_CONFIG,
                '1',
                ['default_limit_cents' => 10_000, 'warn_threshold_percent' => 80],
                ['default_limit_cents' => 25_000, 'warn_threshold_percent' => 60],
                'admin-1',
            );

        $service = new CreditLimitConfigService($repository, $audit);
        $result = $service->updateConfig(
            ['default_limit_cents' => 25_000, 'warn_threshold_percent' => 60],
            'admin-1',
        );

        $this->assertNotNull($result);
        $this->assertSame(25_000, $result->defaultLimitCents);
    }

    public function test_an_update_that_writes_nothing_is_not_audited(): void
    {
        $repository = $this->createMock(CreditLimitConfigRepository::class);
        $repository->method('getConfig')->willReturn(null);
        $repository->method('updateConfig')->willReturn(null);

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->never())->method('log');

        $service = new CreditLimitConfigService($repository, $audit);

        $this->assertNull($service->updateConfig(['default_limit_cents' => 1], 'admin-1'));
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function service(?array $row): CreditLimitConfigService
    {
        $repository = $this->createMock(CreditLimitConfigRepository::class);
        $repository->method('getConfig')->willReturn($row);

        return new CreditLimitConfigService($repository, $this->createMock(AuditService::class));
    }
}
