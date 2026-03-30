<?php

declare(strict_types=1);

namespace App\Modules\Members\Contracts;

interface VisionClientInterface
{
    /**
     * Send raw image bytes to the Vision API.
     * Returns the decoded JSON response as a PHP array.
     *
     * @throws \RuntimeException on API or network failure
     */
    public function recognize(string $imageBytes): array;
}
