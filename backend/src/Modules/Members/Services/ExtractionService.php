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
     * Two-pass IBAN refinement strategy:
     *  1. First pass extracts all fields.
     *  2. The extracted IBAN is validated with mod-97 (ISO 7064):
     *     - Passes mod-97 → confidence raised to "medium" (mathematically valid reading).
     *     - Fails mod-97  → second visual pass on the full image with a checksum-failure hint.
     *       If the new reading passes mod-97, it is used with "medium" confidence.
     *       Otherwise the original first-pass value stays at "low".
     *
     * @throws \RuntimeException when the LLM call fails or returns unparseable JSON
     */
    public function extract(string $bytes, string $mimeType): ExtractionResult
    {
        [$processedBytes, $processedMime] = $this->enhanceImage($bytes, $mimeType);
        $base64  = base64_encode($processedBytes);
        $prompt  = $this->buildPrompt();
        $rawJson = $this->client->extractFromImage($base64, $processedMime, $prompt);
        $result  = $this->parseResponse($rawJson);

        // Always validate the IBAN with mod-97, regardless of model-reported confidence.
        // The model may return "medium" or even "high" confidence on a wrong reading —
        // mod-97 is a hard mathematical check that cannot be fooled.
        // Refinement runs whenever the reading is absent, fails checksum, or is garbled.
        $fields    = $result->fields;
        $ibanValue = $fields['iban']['value'] ?? null;

        $needsRefinement = $ibanValue === null
            || !$this->verifyIbanMod97($ibanValue);

        if ($needsRefinement) {
            $isPlausible = $ibanValue !== null && (bool) preg_match('/^DE\d{20}$/', $ibanValue);
            // Garbled or wrong reading: pass it as hint only if it has the right format,
            // so the refinement prompt can tell the model "this specific reading is wrong".
            $prevForRefinement = $isPlausible ? $ibanValue : null;
            [$refinedValue, $refinedConf] = $this->refineIban($base64, $processedMime, $prevForRefinement);
            $fields['iban'] = ['value' => $refinedValue, 'confidence' => $refinedConf];
            $result = new ExtractionResult($fields);
        }

        return $result;
    }

    /**
     * Refine a low-confidence IBAN reading using a three-stage pipeline.
     *
     * Stage 1 — mod-97 fast path:
     *   If the first-pass value already passes mod-97 → ['value', 'medium'].
     *
     * Stage 2 — second visual pass (fresh read, no hint):
     *   Re-query the LLM with a clean prompt to avoid anchoring on the wrong reading.
     *   If the new reading passes mod-97 → ['newValue', 'medium'].
     *
     * Stage 3 — brute-force arbitration:
     *   Enumerate all ≤2-position confusion-pair substitutions of the stage-2
     *   reading.  Collect those that pass mod-97.
     *   - 0 candidates → stay low.
     *   - 1 candidate  → use it (medium).
     *   - 2–5 candidates → ask the LLM to identify which one matches the image.
     *                      If the chosen value passes mod-97 → medium, else low.
     *   - >5 candidates → too ambiguous, stay low.
     *
     * Silently falls back to [prevIban|null, 'low'] on any error.
     *
     * @param  string|null $prevIban  The first-pass reading, or null when the first
     *                                pass returned a garbled/format-invalid string.
     *                                When null, stages 1 and 2 use a fresh prompt
     *                                without a "previous reading" hint.
     * @return array{0: string|null, 1: string}
     */
    private function refineIban(string $base64, string $mimeType, ?string $prevIban): array
    {
        // Stage 1: first-pass value is already mathematically valid
        if ($prevIban !== null && $this->verifyIbanMod97($prevIban)) {
            return [$prevIban, 'medium'];
        }

        // Stage 2: always a completely fresh focused read — never anchor on the
        // wrong first-pass value, as that biases extended reasoning toward bad digits.
        $stage2Iban = $prevIban;
        try {
            $prompt  = $this->buildIbanFreshReadPrompt();
            $rawJson = $this->client->extractFromImage($base64, $mimeType, $prompt, '{"iban": "DE');
            $newIban = $this->parseIbanReading($rawJson);

            if ($newIban !== null) {
                if ($this->verifyIbanMod97($newIban)) {
                    return [$newIban, 'medium'];
                }
                $stage2Iban = $newIban; // use improved reading for stage 3
            }
        } catch (\Throwable) {
            // Stage 2 failed — continue to stage 3 with original reading
        }

        // Stage 3: brute-force confusion-pair substitutions, then LLM arbitration.
        // 1-substitution pass runs first — a unique 1-sub fix is the strongest signal
        // and avoids being diluted by the larger 2-sub candidate pool.
        try {
            $singles = $this->bruteForceIbanCandidates($stage2Iban, 1);

            if (count($singles) === 1) {
                return [$singles[0], 'medium'];
            }

            // No unique single fix — expand to 2-substitution candidates.
            $doubles    = $this->bruteForceIbanCandidates($stage2Iban, 2);
            $candidates = array_values(array_unique(array_merge($singles, $doubles)));

            if (count($candidates) === 0) {
                return [$stage2Iban, 'low'];
            }

            if (count($candidates) === 1) {
                return [$candidates[0], 'medium'];
            }

            if (count($candidates) <= 5) {
                $chosen = $this->arbitrateIbanCandidates($base64, $mimeType, $candidates);
                if ($chosen !== null && $this->verifyIbanMod97($chosen)) {
                    return [$chosen, 'medium'];
                }
            }
        } catch (\Throwable) {
            // Never fail because of stage 3
        }

        return [$stage2Iban, 'low'];
    }

    /**
     * Build a focused IBAN-only prompt for when the first-pass reading was garbled.
     * No "previous reading" hint is given to avoid anchoring the model on bad data.
     */
    private function buildIbanFreshReadPrompt(): string
    {
        return <<<'PROMPT'
Extract the member's handwritten IBAN from this SEPA mandate form.
The IBAN is written in individual boxes (Kästchen). Ignore the pre-printed Creditor Identifier (CI) — it contains letter codes like "ZZZ".
Return ONLY valid JSON: {"iban": "DE..."}
PROMPT;
    }

    /**
     * Generate all German IBANs reachable from $iban by substituting exactly
     * $maxSubs digits using handwriting confusion pairs, filtering to those passing mod-97.
     *
     * Confusion pairs (bidirectional): 1↔7, 2↔8, 0↔6, 3↔8, 5↔6
     *
     * @return string[]
     */
    private function bruteForceIbanCandidates(string $iban, int $maxSubs): array
    {
        $alternatives = [
            '0' => ['6'],
            '1' => ['7'],
            '2' => ['8'],
            '3' => ['8'],
            '5' => ['6'],
            '6' => ['0', '5'],
            '7' => ['1'],
            '8' => ['2', '3'],
        ];

        $digits = str_split($iban);
        // Positions that have at least one confusion-pair alternative
        $ambiguous = [];
        foreach ($digits as $i => $d) {
            if (isset($alternatives[$d])) {
                $ambiguous[] = [$i, $alternatives[$d]];
            }
        }

        $candidates = [];

        foreach ($this->combinations($ambiguous, $maxSubs) as $selected) {
            $this->expandSubstitutions($digits, $selected, 0, function (array $candidate) use (&$candidates) {
                $s = implode('', $candidate);
                if ($this->verifyIbanMod97($s) && !in_array($s, $candidates, true)) {
                    $candidates[] = $s;
                }
            });
        }

        sort($candidates);
        return $candidates;
    }

    /**
     * Ask the LLM to identify which candidate IBAN matches the handwritten field.
     *
     * @param  string[] $candidates  2–5 valid-checksum IBAN candidates
     * @return string|null  the chosen IBAN, or null if unparseable
     */
    private function arbitrateIbanCandidates(string $base64, string $mimeType, array $candidates): ?string
    {
        $list    = implode("\n", array_map(fn($i, $c) => ($i + 1) . ". $c", array_keys($candidates), $candidates));
        $prompt  = $this->buildIbanArbitrationPrompt($list);
        $rawJson = $this->client->extractFromImage($base64, $mimeType, $prompt, '{"iban": "DE');

        $json = preg_replace('/^```json?\s*/m', '', $rawJson) ?? $rawJson;
        $json = preg_replace('/^```\s*$/m', '', $json) ?? $json;
        $json = trim($json);

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['iban']) || !is_string($data['iban'])) {
            return null;
        }

        $chosen = strtoupper(str_replace(' ', '', $data['iban']));
        return in_array($chosen, $candidates, true) ? $chosen : null;
    }

    /**
     * Recursive helper: expand one substitution position at a time.
     *
     * @param array<int,string>               $digits    current digit array
     * @param array<int,array{int,string[]}>  $selected  remaining positions to substitute
     * @param int                             $idx       position within $selected
     * @param callable                        $emit      called with each fully-expanded candidate
     */
    private function expandSubstitutions(array $digits, array $selected, int $idx, callable $emit): void
    {
        if ($idx >= count($selected)) {
            $emit($digits);
            return;
        }
        [$pos, $alts] = $selected[$idx];
        foreach ($alts as $alt) {
            $next       = $digits;
            $next[$pos] = $alt;
            $this->expandSubstitutions($next, $selected, $idx + 1, $emit);
        }
    }

    /**
     * Return all k-element combinations from $items (no repetition).
     *
     * @param  array<int,mixed> $items
     * @return array<int,array<int,mixed>>
     */
    private function combinations(array $items, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        if ($k > count($items)) {
            return [];
        }
        $result = [];
        $first  = array_shift($items);
        foreach ($this->combinations($items, $k - 1) as $combo) {
            $result[] = array_merge([$first], $combo);
        }
        foreach ($this->combinations($items, $k) as $combo) {
            $result[] = $combo;
        }
        return $result;
    }

    /**
     * Prepare image bytes for the LLM.
     *
     * For PDFs the bytes are sent as-is (Anthropic handles them natively).
     * For images we send the original bytes without any GD processing:
     * modern vision models read natural-resolution colour images well, and
     * resampling + JPEG re-encoding degrades the fine detail in Kästchen
     * (individual character boxes) that is critical for IBAN recognition.
     *
     * @return array{0: string, 1: string} [bytes, mimeType]
     */
    private function enhanceImage(string $bytes, string $mimeType): array
    {
        return [$bytes, $mimeType];
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Extract data from this scanned SEPA mandate form. Return ONLY valid JSON, no markdown, no explanation:
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

- confidence: "high" = every character certain; "medium" = mostly clear; "low" = uncertain characters
- Use null for value AND confidence when a field is absent or illegible
- iban: extract the member's handwritten IBAN from the individual boxes (Kästchen), NOT the pre-printed Creditor Identifier (CI) which contains letter codes like "ZZZ"
- mandate_signed_at: use the date in the "Mandatsdatum" field (individual boxes with pre-printed dots), not the signature date. Format: YYYY-MM-DD
PROMPT;
    }


    /**
     * Build the stage-3 prompt that presents 2–5 mathematically valid IBAN
     * candidates and asks the LLM to choose which one matches the handwriting.
     */
    private function buildIbanArbitrationPrompt(string $candidateList): string
    {
        return <<<PROMPT
Look at the member's handwritten IBAN Kästchen (22 individual boxes, grouped 4+4+4+4+4+2) on this SEPA mandate form.

All of the following IBANs pass the mod-97 checksum. They differ only in a few boxes.
Exactly one matches what is written:

{$candidateList}

Examine the differing boxes carefully and return the matching IBAN.
Return ONLY valid JSON, no markdown, no explanation:
{"iban": "DE..."}
PROMPT;
    }

    /**
     * Parse a single IBAN value from the LLM refinement response.
     *
     * @return string|null  null when unparseable or not a plausible German IBAN
     */
    private function parseIbanReading(string $rawJson): ?string
    {
        $json = preg_replace('/^```json?\s*/m', '', $rawJson) ?? $rawJson;
        $json = preg_replace('/^```\s*$/m', '', $json) ?? $json;
        $json = trim($json);

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['iban']) || !is_string($data['iban'])) {
            return null;
        }

        $iban = strtoupper(str_replace(' ', '', $data['iban']));

        // Must start with DE and have exactly 20 digits after the country code
        if (!preg_match('/^DE\d{20}$/', $iban)) {
            return null;
        }

        return $iban;
    }

    /**
     * Verify an IBAN using the mod-97 algorithm (ISO 7064).
     *
     * Moves the first 4 characters to the end, converts letters to numbers
     * (A=10 … Z=35), then checks that the result mod 97 equals 1.
     * Uses chunked arithmetic to avoid integer overflow on large strings.
     */
    private function verifyIbanMod97(string $iban): bool
    {
        if (strlen($iban) < 4) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);

        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            if (ctype_alpha($char)) {
                $numeric .= (string)(ord(strtoupper($char)) - 55);
            } elseif (ctype_digit($char)) {
                $numeric .= $char;
            } else {
                return false;
            }
        }

        $remainder = 0;
        foreach (str_split($numeric, 9) as $chunk) {
            $remainder = (int)(($remainder . $chunk) % 97);
        }

        return $remainder === 1;
    }

    private function parseResponse(string $rawJson): ExtractionResult
    {
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

            if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
                $confidence = null;
            }

            $value = ($value === '' || $value === null) ? null : (string) $value;

            $fields[$field] = ['value' => $value, 'confidence' => $confidence];
        }

        return new ExtractionResult($fields);
    }
}
