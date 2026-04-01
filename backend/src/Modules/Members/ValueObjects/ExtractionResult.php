<?php

declare(strict_types=1);

namespace App\Modules\Members\ValueObjects;

/**
 * Holds per-field extraction results from the Vision OCR + LLM pipeline.
 *
 * $fields shape:
 *   [
 *     'first_name'         => ['value' => 'Max',    'confidence' => 'high'],
 *     'last_name'          => ['value' => 'Müller', 'confidence' => 'high'],
 *     'email'              => ['value' => '…',      'confidence' => 'medium'],
 *     'street'             => ['value' => '…',      'confidence' => 'high'],
 *     'zip_code'           => ['value' => '…',      'confidence' => 'high'],
 *     'city'               => ['value' => '…',      'confidence' => 'high'],
 *     'account_holder_name'=> ['value' => null,     'confidence' => null],
 *     'card_uid'           => ['value' => '…',      'confidence' => 'high'],
 *     'iban'               => ['value' => 'DE89…',  'confidence' => 'high', 'checksumValid' => true],
 *     'mandate_signed_at'  => ['value' => '2026-01-15', 'confidence' => 'high'],
 *   ]
 *   value and confidence are null when the field was not found or illegible.
 *   iban additionally has a 'checksumValid' boolean key.
 *
 * $needsReview is true when any field has confidence "low" OR when $ibanCandidates
 * is non-empty (ambiguous repair — human must select the correct IBAN).
 *
 * $ibanCandidates holds 2+ valid IBAN alternatives when MOD-97 repair is ambiguous.
 * The first candidate is written to $fields['iban']['value'] as a default selection.
 * When this array is empty, the IBAN is either unambiguously repaired or unrepairable.
 */
final class ExtractionResult
{
    /**
     * @param array<string, array{value: string|null, confidence: string|null}> $fields
     * @param string[] $ibanCandidates
     */
    public function __construct(
        public readonly array $fields,
        public readonly bool  $needsReview = false,
        public readonly array $ibanCandidates = [],
    ) {}

    public function toArray(): array
    {
        $result = [
            'fields'      => $this->fields,
            'needsReview' => $this->needsReview,
        ];

        if (!empty($this->ibanCandidates)) {
            $result['ibanCandidates'] = $this->ibanCandidates;
        }

        return $result;
    }
}
