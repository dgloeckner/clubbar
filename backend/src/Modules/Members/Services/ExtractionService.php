<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\Contracts\ExtractionServiceInterface;
use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\Contracts\VisionClientInterface;
use App\Modules\Members\ValueObjects\ExtractionResult;

class ExtractionService implements ExtractionServiceInterface
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

        $bytes          = ImageOrientationFixer::fix($bytes);
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

        $fields         = $this->postValidate($fields);
        $ibanCandidates = $fields['iban']['candidates'] ?? [];
        unset($fields['iban']['candidates']);
        $needsReview = $this->computeNeedsReview($fields, $ibanCandidates);

        return new ExtractionResult($fields, $needsReview, $ibanCandidates);
    }

    private function postValidate(array $fields): array
    {
        // IBAN: MOD-97 checksum (ISO 13616) is authoritative
        $ibanValue = $fields['iban']['value'] ?? null;
        if ($ibanValue !== null && strlen($ibanValue) === 22 && str_starts_with($ibanValue, 'DE')) {
            if (IbanRepair::verifyMod97($ibanValue)) {
                $fields['iban']['checksumValid'] = true;
                $fields['iban']['confidence']    = 'high';
            } else {
                // No per-character confidence in this pipeline — try all positions at depth 1
                // only. Depth 2 with all positions open creates too many false positives.
                $candidates = IbanRepair::repair($ibanValue, null, 1);
                if (count($candidates) === 0) {
                    $fields['iban']['checksumValid'] = false;
                    $fields['iban']['confidence']    = 'low';
                } elseif (count($candidates) === 1) {
                    $fields['iban']['value']         = $candidates[0];
                    $fields['iban']['checksumValid'] = true;
                    $fields['iban']['confidence']    = 'medium';
                } else {
                    // Ambiguous repair — surface all candidates for user selection
                    $fields['iban']['value']         = $candidates[0];
                    $fields['iban']['checksumValid'] = true;
                    $fields['iban']['confidence']    = 'low';
                    $fields['iban']['candidates']    = $candidates;
                }
            }
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

    private function computeNeedsReview(array $fields, array $ibanCandidates = []): bool
    {
        if (!empty($ibanCandidates)) {
            return true;
        }
        foreach ($fields as $field) {
            if (($field['confidence'] ?? null) === 'low') {
                return true;
            }
        }
        return false;
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a data extraction service for SEPA direct debit mandate forms.

Each line is one OCR paragraph, prefixed with its absolute paragraph index [Pn].
Each word is annotated with its lowest character-level OCR confidence score in
parentheses, e.g. Susi(0.59).
Paragraphs with consecutive or near-consecutive indices are spatially close on
the page; a gap in indices means there were empty paragraphs between them.

Extract these fields from the form text:
- firstName
- lastName
- email
- street
- zipCode
- city
- accountHolderName (the "Kontoinhaber" field — may differ from member name)
- cardUid (uppercase hex string, digits 0-9 and letters A-F only, from "Chip-ID" / "Karten-ID" field, e.g. "A1B2C3D4"; null if absent or if any character is illegible — do NOT substitute placeholder characters like P, X, ?, etc. for unreadable digits)
- iban (German IBAN: always exactly 22 characters — "DE" + 20 digits.
  The IBAN is written in individual cells; OCR splits it across consecutive
  paragraphs. Collect all digit-only tokens from paragraphs immediately
  following the IBAN label paragraph and reconstruct exactly 22 characters.
  Digit-only paragraphs near the Mandatsdatum label belong to the date, not
  the IBAN — use paragraph proximity to the IBAN label to decide.)
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
