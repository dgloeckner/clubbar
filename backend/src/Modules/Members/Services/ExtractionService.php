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

        // Refine IBAN when confidence is low and the value is a plausible German IBAN.
        // Clear format-invalid first-pass readings (e.g. letters embedded by the model)
        // rather than passing garbled strings into the refinement pipeline.
        $fields = $result->fields;
        $ibanValue = $fields['iban']['value'] ?? null;
        if (($fields['iban']['confidence'] ?? null) === 'low' && $ibanValue !== null) {
            if (!preg_match('/^DE\d{20}$/', $ibanValue)) {
                // Garbled reading — reset to null rather than surfacing invalid data
                $fields['iban'] = ['value' => null, 'confidence' => null];
                $result = new ExtractionResult($fields);
            } else {
                [$refinedValue, $refinedConf] = $this->refineIban($base64, $processedMime, $ibanValue);
                $fields['iban'] = ['value' => $refinedValue, 'confidence' => $refinedConf];
                $result = new ExtractionResult($fields);
            }
        }

        return $result;
    }

    /**
     * Refine a low-confidence IBAN reading using a three-stage pipeline.
     *
     * Stage 1 — mod-97 fast path:
     *   If the first-pass value already passes mod-97 → ['value', 'medium'].
     *
     * Stage 2 — second visual pass:
     *   Re-query the LLM with a checksum-failure hint.
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
     * Silently falls back to ['prevIban', 'low'] on any error.
     *
     * @return array{0: string, 1: string}
     */
    private function refineIban(string $base64, string $mimeType, string $prevIban): array
    {
        // Stage 1: first-pass value is already mathematically valid
        if ($this->verifyIbanMod97($prevIban)) {
            return [$prevIban, 'medium'];
        }

        // Stage 2: second visual pass with checksum-failure hint
        $stage2Iban = $prevIban;
        try {
            $prompt  = $this->buildIbanRefinementPrompt($prevIban);
            $rawJson = $this->client->extractFromImage($base64, $mimeType, $prompt);
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

        // Stage 3: brute-force confusion-pair substitutions, then LLM arbitration
        try {
            $candidates = $this->bruteForceIbanCandidates($stage2Iban);

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
     * Generate all German IBANs reachable from $iban by substituting ≤2 digits
     * using the handwriting confusion pairs, filtering to those passing mod-97.
     *
     * Confusion pairs (bidirectional): 1↔7, 2↔8, 0↔6, 3↔8, 5↔6
     *
     * @return string[]
     */
    private function bruteForceIbanCandidates(string $iban): array
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

        // Try substituting 1, then 2 positions
        for ($numSubs = 1; $numSubs <= 2; $numSubs++) {
            foreach ($this->combinations($ambiguous, $numSubs) as $selected) {
                $this->expandSubstitutions($digits, $selected, 0, function (array $candidate) use (&$candidates) {
                    $s = implode('', $candidate);
                    if ($this->verifyIbanMod97($s) && !in_array($s, $candidates, true)) {
                        $candidates[] = $s;
                    }
                });
            }
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
        $rawJson = $this->client->extractFromImage($base64, $mimeType, $prompt);

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
     * Enhance image quality before sending to the LLM.
     *
     * Uses GD (universally available, crash-free) to:
     *  - Convert to grayscale
     *  - Boost contrast
     *  - Sharpen
     *  - Downsample to max 1500px wide (sufficient for LLM OCR, reduces tokens)
     *
     * Falls back to original bytes on any failure.
     *
     * @return array{0: string, 1: string} [bytes, mimeType]
     */
    private function enhanceImage(string $bytes, string $mimeType): array
    {
        if (!extension_loaded('gd')) {
            return [$bytes, $mimeType];
        }

        try {
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                return [$bytes, $mimeType];
            }

            $w = imagesx($src);
            $h = imagesy($src);

            // Downsample to max 1500px wide — reduces LLM token cost and memory usage
            if ($w > 1500) {
                $newH = (int) round($h * 1500 / $w);
                $dst  = imagecreatetruecolor(1500, $newH);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, 1500, $newH, $w, $h);
                imagedestroy($src);
                $src = $dst;
                $w   = 1500;
                $h   = $newH;
            }

            // Grayscale — removes colour noise, uniform ink representation
            imagefilter($src, IMG_FILTER_GRAYSCALE);

            // Gentle contrast boost — just enough to distinguish ink from paper
            // without over-amplifying pre-printed fields relative to handwriting
            imagefilter($src, IMG_FILTER_CONTRAST, -10);

            // Sharpen — makes thin strokes (e.g. "1" vs "9") more distinct
            imagefilter($src, IMG_FILTER_SHARPEN);

            ob_start();
            imagejpeg($src, null, 92);
            $result = ob_get_clean();
            imagedestroy($src);

            return [$result ?: $bytes, 'image/jpeg'];
        } catch (\Throwable) {
            return [$bytes, $mimeType];
        }
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
- For handwritten fields (iban, account_holder_name, mandate_signed_at): use "low" confidence whenever any character could plausibly be misread (e.g. 1/7, 0/6, 1/9, n/m). Only use "high" when every character is completely unambiguous.
- For iban: extract the member's own bank account IBAN — this is typically handwritten by the member in the "IBAN" field. Do NOT extract the Creditor Identifier (CI / Gläubiger-Identifikationsnummer), which is a separate pre-printed field filled in by the organisation; it can be recognised by embedded letter codes such as "BZZ" or "ZZZ" within the number. German IBANs always start with "DE", followed by exactly 2 check digits, then an 8-digit Bankleitzahl (BLZ), then a 10-digit account number — total 22 characters, all digits after "DE". Common handwriting confusions: 1↔7, 2↔8, 0↔6, 3↔8, 5↔6. If the reading has fewer or more than 22 characters, set confidence to "low". Remove all spaces and return uppercase.
- For mandate_signed_at: extract the date from the "Mandatsdatum" field in the SEPA mandate section (not the signature date in section 3). The date may be written in various formats such as 1.4., 01.04., 1.4.26, 01.04.26, 1.4.2026, or 01.04.2026 — always convert to YYYY-MM-DD. For 2-digit years assume 2000+. If only day and month are present without a year, set value to null.
- For name fields (first_name, last_name, account_holder_name): return in standard title case (e.g. "Müller", "Max", "Mandy Müller") regardless of whether the handwriting uses all-caps or all-lowercase. Do not return ALL CAPS names.
PROMPT;
    }

    /**
     * Build the second-pass prompt that informs the model its first reading failed
     * the IBAN mod-97 checksum and asks for a corrected single reading.
     */
    private function buildIbanRefinementPrompt(string $prevIban): string
    {
        return <<<PROMPT
Look at the handwritten IBAN field in this SEPA mandate form image.

A previous reading gave: {$prevIban}
This reading does NOT pass the IBAN mod-97 checksum (verified externally), so at least one digit is wrong.

German IBANs: DE + 2 check digits + 8-digit BLZ + 10-digit account number = exactly 22 characters.
Common handwriting confusion pairs that could explain the error: 1↔7, 2↔8, 0↔6, 3↔8, 5↔6

Re-read each digit of the IBAN field carefully, focusing on digits that could match a confusion pair.
Return your single best corrected reading.

Return ONLY valid JSON, no markdown, no explanation:
{"iban": "DE..."}
PROMPT;
    }

    /**
     * Build the stage-3 prompt that presents 2–5 mathematically valid IBAN
     * candidates and asks the LLM to choose which one matches the handwriting.
     */
    private function buildIbanArbitrationPrompt(string $candidateList): string
    {
        return <<<PROMPT
Look at the handwritten IBAN field in this SEPA mandate form image.

All of the following IBANs pass the mod-97 checksum and differ from each other
only in digits that are commonly confused in handwriting (1↔7, 2↔8, 0↔6, 3↔8, 5↔6).
Exactly one of them matches what is written:

{$candidateList}

Re-read the IBAN field carefully. Return ONLY valid JSON, no markdown, no explanation:
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
