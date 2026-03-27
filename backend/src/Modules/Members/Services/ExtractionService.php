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

        // Refine IBAN when confidence is low and a value was extracted
        $fields = $result->fields;
        if (($fields['iban']['confidence'] ?? null) === 'low' && ($fields['iban']['value'] ?? null) !== null) {
            [$refinedValue, $refinedConf] = $this->refineIban($base64, $processedMime, $fields['iban']['value']);
            $fields['iban'] = ['value' => $refinedValue, 'confidence' => $refinedConf];
            $result = new ExtractionResult($fields);
        }

        return $result;
    }

    /**
     * Refine a low-confidence IBAN reading using mod-97 as a quality gate.
     *
     * 1. If the first-pass value already passes mod-97 → ['value', 'medium'].
     * 2. Otherwise, re-query the LLM on the full image with the checksum-failure hint.
     *    If the new reading passes mod-97 → ['newValue', 'medium'].
     * 3. Silently falls back to ['prevIban', 'low'] on any error or no improvement.
     *
     * @return array{0: string, 1: string}
     */
    private function refineIban(string $base64, string $mimeType, string $prevIban): array
    {
        // Fast path: first-pass value is already mathematically valid
        if ($this->verifyIbanMod97($prevIban)) {
            return [$prevIban, 'medium'];
        }

        try {
            $prompt  = $this->buildIbanRefinementPrompt($prevIban);
            $rawJson = $this->client->extractFromImage($base64, $mimeType, $prompt);
            $newIban = $this->parseIbanReading($rawJson);

            if ($newIban !== null && $this->verifyIbanMod97($newIban)) {
                return [$newIban, 'medium'];
            }
        } catch (\Throwable) {
            // Never fail because of the refinement pass
        }

        return [$prevIban, 'low'];
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
- For iban: extract the member's own bank account IBAN — this is typically handwritten by the member in the "IBAN" field. Do NOT extract the Creditor Identifier (CI / Gläubiger-Identifikationsnummer), which is a separate pre-printed field filled in by the organisation; it can be recognised by embedded letter codes such as "BZZ" or "ZZZ" within the number. German IBANs always start with "DE", followed by exactly 2 check digits, then an 8-digit Bankleitzahl (BLZ), then a 10-digit account number — total 22 characters, all digits after "DE". Common handwriting confusions: 1↔7, 0↔6, 3↔8, 5↔6. If the reading has fewer or more than 22 characters, set confidence to "low". Remove all spaces and return uppercase.
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
Common handwriting confusion pairs that could explain the error: 1↔7, 0↔6, 3↔8, 5↔6

Re-read each digit of the IBAN field carefully, focusing on digits that could match a confusion pair.
Return your single best corrected reading.

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
