<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Modules\Members\Contracts\ExtractionServiceInterface;
use App\Modules\Members\Contracts\LlmClientInterface;
use App\Modules\Members\ValueObjects\ExtractionResult;

class DirectExtractionService implements ExtractionServiceInterface
{
    /** Maps LLM camelCase field names to internal snake_case keys (IBAN handled separately) */
    private const FIELD_MAP = [
        'firstName'         => 'first_name',
        'lastName'          => 'last_name',
        'email'             => 'email',
        'street'            => 'street',
        'zipCode'           => 'zip_code',
        'city'              => 'city',
        'accountHolderName' => 'account_holder_name',
        'cardUid'           => 'card_uid',
        'mandateDate'       => 'mandate_signed_at',
    ];

    public function __construct(private LlmClientInterface $llm) {}

    /**
     * Extract SEPA mandate fields by sending the image directly to the LLM's vision API.
     *
     * Pipeline:
     *  1. LLM vision — reads form image directly, returns per-character IBAN confidence
     *  2. IBAN assembly — reconstructs IBAN from characters array
     *  3. MOD-97 validation — validates checksum; repairs if exactly one alternative fixes it
     *  4. Post-validation — date format, email, zip code hard checks
     *  5. needsReview flag — true when any field has confidence "low"
     *
     * @throws \RuntimeException when mimeType is PDF, or LLM returns unparseable JSON
     */
    public function extract(string $bytes, string $mimeType): ExtractionResult
    {
        if ($mimeType === 'application/pdf') {
            throw new \RuntimeException(
                'PDF extraction is not supported. Direct vision extraction requires a JPEG or PNG image.'
            );
        }

        $rawJson = $this->llm->extractFromImage(base64_encode($bytes), $mimeType, $this->buildSystemPrompt());
        return $this->buildResult($rawJson);
    }

    private function buildResult(string $rawJson): ExtractionResult
    {
        // Strip markdown code fences (models sometimes wrap output in ```json ... ```)
        $json = preg_replace('/^```json?\s*/m', '', $rawJson) ?? $rawJson;
        $json = preg_replace('/^```\s*$/m', '', $json) ?? $json;
        $json = trim($json);

        if (!str_starts_with($json, '{')) {
            $start = strpos($json, '{');
            $end   = strrpos($json, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $json = substr($json, $start, $end - $start + 1);
            }
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('LLM returned invalid JSON: ' . substr($rawJson, 0, 300));
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

        // IBAN uses the characters-based schema — parse and repair separately
        $fields['iban'] = $this->parseIban($data['iban'] ?? null);

        $fields = $this->postValidate($fields);

        return new ExtractionResult($fields, $this->computeNeedsReview($fields));
    }

    private function parseIban(mixed $ibanEntry): array
    {
        if (!is_array($ibanEntry) || !isset($ibanEntry['characters']) || !is_array($ibanEntry['characters'])) {
            return ['value' => null, 'confidence' => null, 'checksumValid' => false];
        }

        $characters = $ibanEntry['characters'];

        // Sort by position to ensure correct assembly order
        usort($characters, fn($a, $b) => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));

        $base = implode('', array_map(fn($c) => (string) ($c['value'] ?? ''), $characters));

        if (strlen($base) !== 22 || !str_starts_with($base, 'DE')) {
            return ['value' => ($base !== '' ? $base : null), 'confidence' => 'low', 'checksumValid' => false];
        }

        // Base passes MOD-97 — use minimum character confidence as overall confidence
        if ($this->verifyIbanMod97($base)) {
            return [
                'value'        => $base,
                'confidence'   => $this->computeMinConfidence($characters),
                'checksumValid' => true,
            ];
        }

        // Repair: try low-confidence character alternatives first, then medium
        $repaired = $this->attemptRepair($base, $characters, ['low']);
        if ($repaired === null) {
            $repaired = $this->attemptRepair($base, $characters, ['low', 'medium']);
        }

        if ($repaired !== null) {
            return ['value' => $repaired, 'confidence' => 'medium', 'checksumValid' => true];
        }

        return ['value' => $base, 'confidence' => 'low', 'checksumValid' => false];
    }

    /**
     * Try substituting character alternatives at positions with the given confidences.
     * Returns the corrected IBAN if exactly one substitution yields a valid MOD-97 checksum,
     * or null when the result is ambiguous (0 or 2+ passing candidates).
     */
    private function attemptRepair(string $base, array $characters, array $targetConfidences): ?string
    {
        $candidates = [];

        foreach ($characters as $char) {
            if (!in_array($char['confidence'] ?? null, $targetConfidences, true)) {
                continue;
            }
            $pos  = (int) ($char['position'] ?? -1);
            $alts = is_array($char['alternatives'] ?? null) ? $char['alternatives'] : [];

            foreach ($alts as $alt) {
                $alt = (string) $alt;
                if (strlen($alt) !== 1) {
                    continue;
                }
                $candidate = substr($base, 0, $pos) . $alt . substr($base, $pos + 1);
                if (strlen($candidate) === 22 && $this->verifyIbanMod97($candidate)) {
                    $candidates[] = $candidate;
                }
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Compute the minimum confidence level across all IBAN characters.
     * Returns 'low' if any character is 'low', 'medium' if any is 'medium', 'high' if all are 'high'.
     */
    private function computeMinConfidence(array $characters): string
    {
        $levels = ['low' => 1, 'medium' => 2, 'high' => 3];
        $min    = 3;
        foreach ($characters as $char) {
            $min = min($min, $levels[$char['confidence'] ?? 'low'] ?? 1);
        }
        return match ($min) {
            3       => 'high',
            2       => 'medium',
            default => 'low',
        };
    }

    private function postValidate(array $fields): array
    {
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
You are a data extraction assistant for SEPA direct debit mandate forms.

You receive an image of a completed SEPA mandate form. Extract the following fields.

For all fields except the IBAN, return:
  { "value": "...", "confidence": "high|medium|low" }

Confidence levels:
  "high":   you are confident (>85% certain)
  "medium": you have reasonable confidence (65–85%)
  "low":    you are uncertain (<65%)

Use { "value": null, "confidence": null } when a field is absent or illegible.

Fields to extract:
  - firstName
  - lastName
  - email
  - street (full address with house number)
  - zipCode
  - city
  - accountHolderName (the "Kontoinhaber" field — may differ from the member name)
  - cardUid (uppercase hex from "Chip-ID" / "Karten-ID" field; null if absent)
  - mandateDate (the signature date; normalize to DD.MM.YYYY)

For the IBAN (German format: "DE" + 20 digits = exactly 22 characters):
  The IBAN is typically written in individual cells (Kästchen) on the form.
  Return a "characters" array with all 22 positions:
  {
    "iban": {
      "characters": [
        { "position": 0, "value": "D", "confidence": "high", "alternatives": [] },
        { "position": 1, "value": "E", "confidence": "high", "alternatives": [] },
        { "position": 4, "value": "7", "confidence": "low", "alternatives": ["1", "4"] },
        ...all 22 positions...
      ]
    }
  }

For each IBAN character:
  - "position": 0-indexed position in the IBAN (0 = "D", 1 = "E", 2–3 = check digits, 4–21 = account digits)
  - "value": your best-read character
  - "confidence": as defined above
  - "alternatives": list of plausible alternative readings, most likely first
    (leave empty [] when confidence is "high")
  Common visual confusions for IBAN digits: 1↔7, 0↔9, 6↔8.

Ignore form labels, printed instructions, boilerplate text, and the Creditor Identifier
line (which contains "ZZZ"). Focus only on the handwritten or filled-in values.

Respond with ONLY a valid JSON object — no explanation, no markdown fences:
{
  "firstName":         { "value": "...", "confidence": "high|medium|low" },
  "lastName":          { "value": "...", "confidence": "high|medium|low" },
  "email":             { "value": "...", "confidence": "high|medium|low" },
  "street":            { "value": "...", "confidence": "high|medium|low" },
  "zipCode":           { "value": "...", "confidence": "high|medium|low" },
  "city":              { "value": "...", "confidence": "high|medium|low" },
  "accountHolderName": { "value": "...", "confidence": "high|medium|low" },
  "cardUid":           { "value": "...", "confidence": "high|medium|low" },
  "iban": {
    "characters": [
      { "position": 0,  "value": "D", "confidence": "high", "alternatives": [] },
      { "position": 1,  "value": "E", "confidence": "high", "alternatives": [] },
      ...all 22 positions...
    ]
  },
  "mandateDate": { "value": "DD.MM.YYYY", "confidence": "high|medium|low" }
}
PROMPT;
    }
}
