<?php

declare(strict_types=1);

namespace App\Modules\Members\ValueObjects;

/**
 * Holds per-field extraction results from the Vision OCR + LLM pipeline.
 *
 * $fields shape:
 *   [
 *     'first_name'        => ['value' => 'Max',  'confidence' => 'high'],
 *     'iban'              => ['value' => 'DE89…', 'confidence' => 'high', 'checksumValid' => true],
 *     'mandate_signed_at' => ['value' => '2026-01-15', 'confidence' => 'high'],
 *     ...
 *   ]
 *   value and confidence are null when the field was not found or illegible.
 *   iban additionally has a 'checksumValid' boolean key.
 *
 * $needsReview is true when any field has confidence "low".
 */
final class ExtractionResult
{
    /**
     * @param array<string, array{value: string|null, confidence: string|null}> $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly bool  $needsReview = false,
    ) {}

    public function toArray(): array
    {
        return [
            'fields'      => $this->fields,
            'needsReview' => $this->needsReview,
        ];
    }
}
