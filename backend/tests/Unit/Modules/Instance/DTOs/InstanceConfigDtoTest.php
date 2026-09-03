<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Instance\DTOs;

use App\Modules\Instance\DTOs\InstanceConfigDto;
use PHPUnit\Framework\TestCase;

class InstanceConfigDtoTest extends TestCase
{
    public function test_fromRow_reads_the_instance_name(): void
    {
        $dto = InstanceConfigDto::fromRow(['instance_name' => 'FRGS Ruderbar']);

        $this->assertSame('FRGS Ruderbar', $dto->instanceName);
    }

    public function test_fromRow_falls_back_to_the_stock_name_when_missing(): void
    {
        $dto = InstanceConfigDto::fromRow([]);

        $this->assertSame('Club Bar', $dto->instanceName);
    }

    public function test_toArray_round_trips(): void
    {
        $dto = new InstanceConfigDto(instanceName: 'FRGS Ruderbar', timeZone: 'Europe/Berlin', timeZoneSource: 'configured');

        $this->assertSame(
            [
                'instance_name' => 'FRGS Ruderbar',
                'time_zone' => 'Europe/Berlin',
                'time_zone_source' => 'configured',
            ],
            $dto->toArray(),
        );
    }
}
