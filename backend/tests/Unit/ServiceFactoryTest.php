<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Terminals\Services\TerminalsService;
use App\ServiceFactory;
use App\Shared\Config\AppConfig;
use App\Shared\Logging\Logger;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Wiring checks for the container.
 *
 * TerminalsService gained a third constructor argument with #106 — the config
 * that carries the token TTL. A miswired factory would not fail until a
 * terminal was created at runtime, so the wiring is asserted here.
 */
class ServiceFactoryTest extends TestCase
{
    private function factory(): ServiceFactory
    {
        return new ServiceFactory(
            $this->createMock(PDO::class),
            new AppConfig(),
            $this->createMock(Logger::class),
        );
    }

    public function test_getTerminalsService_builds_a_service_carrying_the_app_config(): void
    {
        $service = $this->factory()->getTerminalsService();

        $this->assertInstanceOf(TerminalsService::class, $service);
    }

    public function test_getTerminalsService_returns_the_same_instance_each_time(): void
    {
        $factory = $this->factory();

        $this->assertSame($factory->getTerminalsService(), $factory->getTerminalsService());
    }
}
