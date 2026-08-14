<?php

declare(strict_types=1);

namespace App\Modules\Members\Contracts;

use App\Modules\Members\ValueObjects\ExtractionResult;

interface ExtractionServiceInterface
{
    /**
     * Extract SEPA mandate fields from raw scan bytes (image or PDF).
     *
     * PDF support is implementation-dependent: {@see DirectExtractionService}
     * forwards it to the LLM when the provider accepts a document content
     * type; {@see ExtractionService}'s Google Vision OCR step does not.
     *
     * @throws \RuntimeException when this implementation/provider cannot read the mimeType, or extraction fails
     */
    public function extract(string $bytes, string $mimeType): ExtractionResult;
}
