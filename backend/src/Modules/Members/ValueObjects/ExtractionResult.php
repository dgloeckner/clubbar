<?php

declare(strict_types=1);

namespace App\Modules\Members\ValueObjects;

/**
 * Holds per-field extraction results from an LLM vision call.
 *
 * $fields shape:
 *   ['first_name' => ['value' => 'Max', 'confidence' => 'high'], ...]
 *   value and confidence are null when the field was not found or illegible.
 */
final class ExtractionResult
{
    /**
     * @param array<string, array{value: string|null, confidence: string|null}> $fields
     */
    public function __construct(
        public readonly array $fields,
    ) {}

    public function toArray(): array
    {
        return ['fields' => $this->fields];
    }
}
