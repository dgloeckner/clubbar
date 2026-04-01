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
     *  1. EXIF auto-rotation   — corrects phone images that are physically sideways
     *  2. LLM vision           — reads form image, returns per-character IBAN confidence
     *  3. IBAN assembly        — reconstructs IBAN from characters array
     *  4. MOD-97 validation    — validates checksum; repairs via lookalike substitutions
     *                            (depth ≤ 3, low/medium-confidence positions only)
     *  5. Enhance fallback     — if IBAN still invalid: grayscale + contrast boost + sharpen,
     *                            retry LLM once (helps faint-ink scans; skipped on GD failure)
     *  6. Post-validation      — date format, email, zip code hard checks
     *  7. needsReview flag     — true when any field is "low" or IBAN repair is ambiguous
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

        $bytes  = ImageOrientationFixer::fix($bytes);
        $result = $this->attemptExtraction($bytes, $mimeType);

        if (!($result->fields['iban']['checksumValid'] ?? false)) {
            $enhanced = $this->enhanceForOcr($bytes);
            if ($enhanced !== null) {
                $result2 = $this->attemptExtraction($enhanced, 'image/jpeg');
                if ($result2->fields['iban']['checksumValid'] ?? false) {
                    return $result2;
                }
            }
        }

        return $result;
    }

    private function attemptExtraction(string $bytes, string $mimeType): ExtractionResult
    {
        $rawJson = $this->llm->extractFromImage(base64_encode($bytes), $mimeType, $this->buildSystemPrompt());
        return $this->buildResult($rawJson);
    }

    /**
     * Apply grayscale + contrast boost + sharpen to improve legibility of faint-ink scans.
     * Returns null when GD cannot decode the image (unsupported format or corrupt bytes).
     */
    private function enhanceForOcr(string $bytes): ?string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            return null;
        }

        imagefilter($img, IMG_FILTER_GRAYSCALE);
        imagefilter($img, IMG_FILTER_CONTRAST, -30);
        $sharpen = [[0, -1, 0], [-1, 5, -1], [0, -1, 0]];
        imageconvolution($img, $sharpen, 1, 0);

        ob_start();
        imagejpeg($img, null, 95);
        $result = ob_get_clean();

        return ($result !== false && $result !== '') ? $result : null;
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
        $ibanResult     = $this->parseIban($data['iban'] ?? null);
        $ibanCandidates = $ibanResult['candidates'] ?? [];
        unset($ibanResult['candidates']);
        $fields['iban'] = $ibanResult;

        $fields = $this->postValidate($fields);

        return new ExtractionResult($fields, $this->computeNeedsReview($fields, $ibanCandidates), $ibanCandidates);
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

        // Perfect read — confidence = minimum across all character confidences
        if (IbanRepair::verifyMod97($base)) {
            return [
                'value'        => $base,
                'confidence'   => $this->computeMinConfidence($characters),
                'checksumValid' => true,
            ];
        }

        // Repair using low- and medium-confidence positions, depth ≤ 3
        $candidates = IbanRepair::repair($base, $characters);

        return match (count($candidates)) {
            0       => ['value' => $base, 'confidence' => 'low', 'checksumValid' => false],
            1       => ['value' => $candidates[0], 'confidence' => 'medium', 'checksumValid' => true],
            default => [
                'value'        => $candidates[0],
                'confidence'   => 'low',
                'checksumValid' => true,
                'candidates'   => $candidates,
            ],
        };
    }

    /**
     * Compute the minimum confidence level across all IBAN characters.
     * Returns 'low' if any is 'low', 'medium' if any is 'medium', 'high' if all are 'high'.
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

        $email = $fields['email']['value'] ?? null;
        if ($email !== null && !str_contains($email, '@')) {
            $fields['email']['confidence'] = 'low';
        }

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
        { "position": 0, "value": "D", "confidence": "high" },
        { "position": 1, "value": "E", "confidence": "high" },
        { "position": 4, "value": "7", "confidence": "low" },
        ...all 22 positions...
      ]
    }
  }

For each IBAN character:
  - "position": 0-indexed position in the IBAN (0 = "D", 1 = "E", 2–3 = check digits, 4–21 = account digits)
  - "value": your best-read character
  - "confidence": as defined above — for visually ambiguous pairs (1 vs 7, 0 vs 6, 0 vs 9, 6 vs 8),
    only use "high" when you are certain the character cannot be the lookalike; otherwise use "medium"

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
      { "position": 0,  "value": "D", "confidence": "high" },
      { "position": 1,  "value": "E", "confidence": "high" },
      ...all 22 positions...
    ]
  },
  "mandateDate": { "value": "DD.MM.YYYY", "confidence": "high|medium|low" }
}
PROMPT;
    }
}
