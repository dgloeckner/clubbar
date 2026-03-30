<?php

declare(strict_types=1);

namespace App\Modules\Members\Factories;

use App\Modules\Members\Contracts\VisionClientInterface;
use App\Modules\Members\VisionClients\GoogleVisionClient;
use App\Shared\Config\AppConfig;

class VisionClientFactory
{
    public function __construct(private AppConfig $config) {}

    /**
     * Returns null when GCLOUD_VISION_API is absent.
     * Extraction is disabled when this returns null.
     */
    public function create(): ?VisionClientInterface
    {
        if ($this->config->googleVisionKey === null) {
            return null;
        }

        return new GoogleVisionClient($this->config->googleVisionKey);
    }
}
