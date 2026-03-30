<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Factories;

use App\Modules\Members\Factories\VisionClientFactory;
use App\Modules\Members\VisionClients\GoogleVisionClient;
use App\Shared\Config\AppConfig;
use PHPUnit\Framework\TestCase;

class VisionClientFactoryTest extends TestCase
{
    private function makeConfig(?string $key): AppConfig
    {
        $config = (new \ReflectionClass(AppConfig::class))->newInstanceWithoutConstructor();
        $prop   = (new \ReflectionClass(AppConfig::class))->getProperty('googleVisionKey');
        $prop->setValue($config, $key);
        return $config;
    }

    public function test_returns_null_when_key_not_configured(): void
    {
        $factory = new VisionClientFactory($this->makeConfig(null));
        $this->assertNull($factory->create());
    }

    public function test_returns_google_vision_client_when_key_configured(): void
    {
        $factory = new VisionClientFactory($this->makeConfig('AIzaTestKey'));
        $this->assertInstanceOf(GoogleVisionClient::class, $factory->create());
    }
}
