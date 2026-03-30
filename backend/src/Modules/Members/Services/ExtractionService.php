<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\Contracts\VisionClientInterface;
use App\Modules\Members\ValueObjects\ExtractionResult;

class ExtractionService
{
    /** Maps LLM camelCase field names to internal snake_case keys */
    private const FIELD_MAP = [
        'firstName'         => 'first_name',
        'lastName'          => 'last_name',
        'email'             => 'email',
        'street'            => 'street',
        'zipCode'           => 'zip_code',
        'city'              => 'city',
        'accountHolderName' => 'account_holder_name',
        'cardUid'           => 'card_uid',
        'iban'              => 'iban',
        'mandateDate'       => 'mandate_signed_at',
    ];

    public function __construct(
        private VisionClientInterface $vision,
        private LlmClientInterface    $llm,
    ) {}

    /**
     * Extract SEPA mandate fields from raw image bytes.
     *
     * Pipeline:
     *  1. Google Cloud Vision — OCR with per-character confidence scores
     *  2. OcrPreprocessor — flatten ~1.7 MB response to ~3 KB compact text
     *  3. Haiku LLM — extract structured JSON fields with confidence labels
     *  4. Post-validation — IBAN MOD-97, date format, email, zip code hard checks
     *  5. needsReview flag — true when any field has confidence "low"
     *
     * @throws \RuntimeException when mimeType is PDF, Vision call fails, or LLM returns unparseable JSON
     */
    public function extract(string $bytes, string $mimeType): ExtractionResult
    {
        if ($mimeType === 'application/pdf') {
            throw new \RuntimeException(
                'PDF extraction is not supported. Vision-based extraction requires a JPEG or PNG image.'
            );
        }

        $visionResponse = $this->vision->recognize($bytes);
        $compactText    = (new OcrPreprocessor())->flatten($visionResponse);
        $rawJson        = $this->llm->extractFromText(
            'OCR text:' . "\n" . $compactText,
            $this->buildSystemPrompt()
        );

        return $this->buildResult($rawJson);
    }

    private function buildResult(string $rawJson): ExtractionResult
    {
        // Strip markdown code fences first (models sometimes wrap output in ```json ... ```)
        $json = preg_replace('/^```json?\s*/m', '', $rawJson) ?? $rawJson;
        $json = preg_replace('/^```\s*$/m', '', $json) ?? $json;
        $json = trim($json);

        // If the result still isn't pure JSON, find the outermost { ... } block.
        // This handles models that prepend reasoning/analysis text before the JSON object.
        if (!str_starts_with($json, '{')) {
            $start = strpos($json, '{');
            $end   = strrpos($json, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $json = substr($json, $start, $end - $start + 1);
            }
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException(
                'LLM returned invalid JSON: ' . substr($rawJson, 0, 300)
            );
        }

        $fields = [];

        foreach (self::FIELD_MAP as $llmKey => $snakeKey) {
            $entry      = $data[$llmKey] ?? null;
            $value      = is_array($entry) ? ($entry['value'] ?? null) : null;
            $confidence = is_array($entry) ? ($entry['confidence'] ?? null) : null;

            if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
                $confidence = null;
            }
            $value = ($value === '' || $value === null) ? null : (string) $value;

            $fields[$snakeKey] = ['value' => $value, 'confidence' => $confidence];
        }

        $fields      = $this->postValidate($fields);
        $needsReview = $this->computeNeedsReview($fields);

        return new ExtractionResult($fields, $needsReview);
    }

    private function postValidate(array $fields): array
    {
        // IBAN: MOD-97 checksum (ISO 13616) is authoritative
        $ibanValue = $fields['iban']['value'] ?? null;
        if ($ibanValue !== null && strlen($ibanValue) === 22 && str_starts_with($ibanValue, 'DE')) {
            $valid                    = $this->verifyIbanMod97($ibanValue);
            $fields['iban']['checksumValid'] = $valid;
            $fields['iban']['confidence']    = $valid ? 'high' : 'low';
        } else {
            $fields['iban']['checksumValid'] = false;
            if ($ibanValue !== null) {
                $fields['iban']['confidence'] = 'low';
            }
        }

        // mandate_signed_at: LLM returns DD.MM.YYYY; normalize to YYYY-MM-DD
        $rawDate = $fields['mandate_signed_at']['value'] ?? null;
        if ($rawDate !== null) {
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $rawDate, $m)) {
                $fields['mandate_signed_at']['value'] = "{$m[3]}-{$m[2]}-{$m[1]}";
            } else {
                $fields['mandate_signed_at']['confidence'] = 'low';
            }
        }

        // email: must contain @
        $email = $fields['email']['value'] ?? null;
        if ($email !== null && !str_contains($email, '@')) {
            $fields['email']['confidence'] = 'low';
        }

        // zip_code: German = exactly 5 digits
        $zip = $fields['zip_code']['value'] ?? null;
        if ($zip !== null && !preg_match('/^\d{5}$/', $zip)) {
            $fields['zip_code']['confidence'] = 'low';
        }

        return $fields;
    }

    private function computeNeedsReview(array $fields): bool
    {
        foreach ($fields as $field) {
            if (($field['confidence'] ?? null) === 'low') {
                return true;
            }
        }
        return false;
    }

    /**
     * Verify an IBAN using the mod-97 algorithm (ISO 7064).
     */
    private function verifyIbanMod97(string $iban): bool
    {
        if (strlen($iban) < 4) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric    = '';

        foreach (str_split($rearranged) as $char) {
            if (ctype_alpha($char)) {
                $numeric .= (string) (ord(strtoupper($char)) - 55);
            } elseif (ctype_digit($char)) {
                $numeric .= $char;
            } else {
                return false;
            }
        }

        $remainder = 0;
        foreach (str_split($numeric, 9) as $chunk) {
            $remainder = (int) (($remainder . $chunk) % 97);
        }

        return $remainder === 1;
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a data extraction service for SEPA direct debit mandate forms.

Each word is annotated with its lowest character-level OCR confidence score in
parentheses, e.g. Susi(0.59).

Extract these fields from the form text:
- firstName
- lastName
- email
- street
- zipCode
- city
- accountHolderName (the "Kontoinhaber" field — may differ from member name)
- cardUid (uppercase hex from "Chip-ID" / "Karten-ID" field, e.g. "A1B2C3D4"; null if absent)
- iban (German DE + 20 digits = 22 chars total; may be split — reconstruct it)
- mandateDate (normalize to DD.MM.YYYY)

For each field, assign a confidence level based on the minimum OCR confidence score
across all words that make up the field value:
- "high":   min >= 0.85
- "medium": min >= 0.65
- "low":    min < 0.65

Ignore the Creditor Identifier line (contains ZZZ).
Ignore all printed form labels, instructions, and boilerplate.
Use null for value AND confidence when a field is absent or illegible.

Respond with ONLY a JSON object, no explanation:
{
  "firstName":         { "value": "...", "confidence": "high|medium|low" },
  "lastName":          { "value": "...", "confidence": "high|medium|low" },
  "email":             { "value": "...", "confidence": "high|medium|low" },
  "street":            { "value": "...", "confidence": "high|medium|low" },
  "zipCode":           { "value": "...", "confidence": "high|medium|low" },
  "city":              { "value": "...", "confidence": "high|medium|low" },
  "accountHolderName": { "value": "...", "confidence": "high|medium|low" },
  "cardUid":           { "value": "...", "confidence": "high|medium|low" },
  "iban":              { "value": "...", "confidence": "high|medium|low" },
  "mandateDate":       { "value": "DD.MM.YYYY", "confidence": "high|medium|low" }
}
PROMPT;
    }
}
