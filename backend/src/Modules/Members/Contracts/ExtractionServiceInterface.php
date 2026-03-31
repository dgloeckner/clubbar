<?php

declare(strict_types=1);

namespace App\Modules\Members\Contracts;

use App\Modules\Members\ValueObjects\ExtractionResult;

interface ExtractionServiceInterface
{
    /**
     * Extract SEPA mandate fields from raw image bytes.
     *
     * @throws \RuntimeException when mimeType is PDF or extraction fails
     */
    public function extract(string $bytes, string $mimeType): ExtractionResult;
}
