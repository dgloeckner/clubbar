<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\ValueObjects\ExtractionResult;

class ExtractionService
{
    private const EXTRACTABLE_FIELDS = [
        'first_name',
        'last_name',
        'email',
        'iban',
        'account_holder_name',
        'mandate_signed_at',
    ];

    public function __construct(
        private LlmClientInterface $client,
    ) {}

    /**
     * Extract SEPA mandate fields from raw image/PDF bytes.
     *
     * @throws \RuntimeException when the LLM call fails or returns unparseable JSON
     */
    public function extract(string $bytes, string $mimeType): ExtractionResult
    {
        $base64  = base64_encode($bytes);
        $prompt  = $this->buildPrompt();
        $rawJson = $this->client->extractFromImage($base64, $mimeType, $prompt);
        return $this->parseResponse($rawJson);
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
You are extracting data from a scanned SEPA direct debit mandate form.
Extract the following fields and return ONLY valid JSON in this exact format — no markdown, no explanation, no additional text:
{
  "fields": {
    "first_name":          {"value": "...", "confidence": "high"},
    "last_name":           {"value": "...", "confidence": "high"},
    "email":               {"value": "...", "confidence": "high"},
    "iban":                {"value": "...", "confidence": "high"},
    "account_holder_name": {"value": "...", "confidence": "high"},
    "mandate_signed_at":   {"value": "YYYY-MM-DD", "confidence": "high"}
  }
}
Rules:
- confidence must be "high", "medium", or "low"
- Use null for value AND confidence when a field is absent, blank, or illegible
- For iban: extract the member's own bank account IBAN — this is typically handwritten by the member in the "IBAN" field. Do NOT extract the Creditor Identifier (CI / Gläubiger-Identifikationsnummer), which is a separate pre-printed field filled in by the organisation; it can be recognised by embedded letter codes such as "BZZ" or "ZZZ" within the number. The member's IBAN contains only digits after the 2-letter country code and 2 check digits. Return your best reading and remove all spaces.
- For mandate_signed_at: extract the date from the "Mandatsdatum" field in the SEPA mandate section (not the signature date in section 3). The date may be written in various formats such as 1.4., 01.04., 1.4.26, 01.04.26, 1.4.2026, or 01.04.2026 — always convert to YYYY-MM-DD. For 2-digit years assume 2000+. If only day and month are present without a year, set value to null.
PROMPT;
    }

    private function parseResponse(string $rawJson): ExtractionResult
    {
        // Strip markdown code fences if the model wrapped its response
        $json = preg_replace('/^```json?\s*/m', '', $rawJson) ?? $rawJson;
        $json = preg_replace('/^```\s*$/m', '', $json) ?? $json;
        $json = trim($json);

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['fields'])) {
            throw new \RuntimeException(
                'LLM returned invalid JSON (no "fields" key): ' . substr($rawJson, 0, 300)
            );
        }

        $fields = [];
        foreach (self::EXTRACTABLE_FIELDS as $field) {
            $fieldData  = $data['fields'][$field] ?? null;
            $value      = is_array($fieldData) ? ($fieldData['value'] ?? null) : null;
            $confidence = is_array($fieldData) ? ($fieldData['confidence'] ?? null) : null;

            // Normalise: only accept known confidence levels
            if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
                $confidence = null;
            }

            // Normalise empty string to null
            $value = ($value === '' || $value === null) ? null : (string) $value;

            $fields[$field] = ['value' => $value, 'confidence' => $confidence];
        }

        return new ExtractionResult($fields);
    }
}
