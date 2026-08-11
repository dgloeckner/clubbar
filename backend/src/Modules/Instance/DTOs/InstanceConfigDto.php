<?php

declare(strict_types=1);

namespace App\Modules\Instance\DTOs;

final readonly class InstanceConfigDto
{
    public function __construct(
        public string $instanceName,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            instanceName: $row['instance_name'] ?? 'Club Bar',
        );
    }

    public function toArray(): array
    {
        return [
            'instance_name' => $this->instanceName,
        ];
    }
}
