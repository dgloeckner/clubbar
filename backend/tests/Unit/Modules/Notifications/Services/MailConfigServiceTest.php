<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Modules\Notifications\Services\CronSecret;
use App\Modules\Notifications\Services\MailConfigService;
use App\Shared\Config\AppConfig;
use App\Shared\Mail\MailTransportFactory;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * The URL-trigger secret's precedence between a panel rotation and
 * `config.php`'s `cron.secret` (#473) — the part of ADR-0038 rule 3 this class
 * now owns instead of {@see \App\Modules\Notifications\Controllers\CronController}
 * re-deriving it.
 */
class MailConfigServiceTest extends TestCase
{
    private ?string $originalCronSecret = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalCronSecret = $_ENV['CRON_SECRET'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalCronSecret === null) {
            unset($_ENV['CRON_SECRET']);
        } else {
            $_ENV['CRON_SECRET'] = $this->originalCronSecret;
        }
        parent::tearDown();
    }

    private function service(?string $cronSecretHash, ?string $fileCronSecret): MailConfigService
    {
        // Matches SchedulerStatusServiceTest's approach: the empty string
        // rather than unset(), so a container-provided secret cannot leak in.
        $_ENV['CRON_SECRET'] = $fileCronSecret ?? '';

        $repository = $this->createMock(MailConfigRepository::class);
        $repository->method('getConfig')->willReturn([
            'sender_address' => 'bar@example.org',
            'cron_secret_hash' => $cronSecretHash,
        ]);

        return new MailConfigService(
            $repository,
            $this->createMock(InstanceConfigService::class),
            $this->createMock(MailTransportFactory::class),
            $this->createMock(AuditService::class),
            new AppConfig(),
        );
    }

    public function test_not_configured_when_neither_source_has_a_secret(): void
    {
        $this->assertFalse($this->service(null, null)->cronSecretConfigured());
    }

    public function test_configured_via_the_file_fallback_alone(): void
    {
        $this->assertTrue($this->service(null, 'file-secret')->cronSecretConfigured());
    }

    public function test_configured_via_a_rotated_hash_alone(): void
    {
        $this->assertTrue($this->service(CronSecret::hash('rotated'), null)->cronSecretConfigured());
    }

    public function test_verifies_against_the_file_secret_when_nothing_has_been_rotated(): void
    {
        $service = $this->service(null, 'file-secret');

        $this->assertTrue($service->verifyCronSecret('file-secret'));
        $this->assertFalse($service->verifyCronSecret('wrong'));
    }

    /**
     * The file value stops being a live credential the moment a hash exists —
     * one accepted secret at a time, the same rule a rotated terminal token
     * follows once the replacement is in use.
     */
    public function test_a_rotated_hash_supersedes_the_file_secret_entirely(): void
    {
        $service = $this->service(CronSecret::hash('rotated-secret'), 'file-secret');

        $this->assertTrue($service->verifyCronSecret('rotated-secret'));
        $this->assertFalse(
            $service->verifyCronSecret('file-secret'),
            'the file value must stop working once a hash is rotated',
        );
    }

    public function test_an_empty_presented_secret_never_verifies(): void
    {
        $this->assertFalse($this->service(null, 'file-secret')->verifyCronSecret(''));
        $this->assertFalse($this->service(CronSecret::hash('x'), null)->verifyCronSecret(''));
    }

    public function test_rotate_returns_a_plaintext_and_persists_only_its_hash(): void
    {
        $repository = $this->createMock(MailConfigRepository::class);
        $repository->method('getConfig')->willReturn(['sender_address' => 'bar@example.org']);
        $repository->expects($this->once())
            ->method('rotateCronSecret')
            ->with($this->matchesRegularExpression('/^[0-9a-f]{64}$/'), 'admin-1')
            ->willReturn([]);

        $auditService = $this->createMock(AuditService::class);
        $auditService->expects($this->once())->method('log');

        $service = new MailConfigService(
            $repository,
            $this->createMock(InstanceConfigService::class),
            $this->createMock(MailTransportFactory::class),
            $auditService,
            new AppConfig(),
        );

        $plaintext = $service->rotateCronSecret('admin-1');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plaintext);
    }

    public function test_two_rotations_produce_different_secrets(): void
    {
        $repository = $this->createMock(MailConfigRepository::class);
        $repository->method('getConfig')->willReturn(['sender_address' => 'bar@example.org']);
        $repository->method('rotateCronSecret')->willReturn([]);

        $service = new MailConfigService(
            $repository,
            $this->createMock(InstanceConfigService::class),
            $this->createMock(MailTransportFactory::class),
            $this->createMock(AuditService::class),
            new AppConfig(),
        );

        $this->assertNotSame($service->rotateCronSecret('admin-1'), $service->rotateCronSecret('admin-1'));
    }
}
