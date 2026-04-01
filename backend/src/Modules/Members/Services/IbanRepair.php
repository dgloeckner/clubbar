<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

/**
 * IBAN lookalike-repair utility (ISO 7064 MOD-97 checksum).
 *
 * Tries single, double, and triple character substitutions using visually similar
 * digit pairs to recover from OCR or handwriting misreads. The maximum repair
 * depth is 3 — safe when per-character confidence filtering limits the candidate
 * pool; the false-positive risk is acceptable at depth 3 with ≤4 uncertain positions.
 *
 * Usage:
 *   // With per-character confidence (DirectExtractionService):
 *   $candidates = IbanRepair::repair($base, $characters); // only low/medium positions
 *
 *   // Without per-character confidence (ExtractionService / Vision OCR text pipeline):
 *   $candidates = IbanRepair::repair($base, null); // all positions tried
 */
class IbanRepair
{
    /**
     * Visually similar digit pairs commonly confused in handwriting and OCR.
     * Each key maps to its likely misread alternatives.
     */
    private const LOOKALIKES = [
        '0' => ['9'],
        '1' => ['7'],
        '6' => ['8'],
        '7' => ['1'],
        '8' => ['6'],
        '9' => ['0'],
    ];

    /** Maximum number of simultaneous substitutions to attempt. */
    private const MAX_DEPTH = 3;

    /**
     * Attempt to repair an invalid 22-character German IBAN.
     *
     * When $characters is provided, only positions with 'low' or 'medium' confidence
     * are tried, and $maxDepth defaults to 3 (safe because the candidate pool is small).
     *
     * Without $characters, all 22 positions are candidates. In that case the caller
     * should pass $maxDepth = 1, because depth-2 with all positions open creates
     * accidental MOD-97 collisions (false positives).
     *
     * Returns all valid IBAN candidates found at minimum repair depth: depth 1 is
     * exhausted first; depth 2 is tried only when depth 1 finds nothing. An empty
     * array means the IBAN cannot be repaired within the allowed depth.
     *
     * The caller decides what to do with multiple candidates (surface them as choices
     * to the user via ibanCandidates).
     *
     * @param  array<int, array{position: int, value: string, confidence: string}>|null $characters
     * @return string[]
     */
    public static function repair(string $base, ?array $characters, int $maxDepth = self::MAX_DEPTH): array
    {
        if (strlen($base) !== 22) {
            return [];
        }

        $positions = [];
        if ($characters !== null) {
            foreach ($characters as $char) {
                if (in_array($char['confidence'] ?? null, ['low', 'medium'], true)) {
                    $pos = (int) ($char['position'] ?? -1);
                    if ($pos >= 0 && $pos < 22) {
                        $positions[] = $pos;
                    }
                }
            }
        } else {
            $positions = range(0, 21);
        }

        $effectiveMax = min($maxDepth, self::MAX_DEPTH);
        for ($depth = 1; $depth <= $effectiveMax; $depth++) {
            $found = self::tryCombinations($base, $positions, $depth);
            if (!empty($found)) {
                return array_values(array_unique($found));
            }
        }

        return [];
    }

    private static function tryCombinations(string $base, array $positions, int $depth): array
    {
        $candidates = [];

        foreach (self::combinations($positions, $depth) as $combo) {
            // For each position in this combination, collect lookalike alternatives.
            // Skip the whole combination if any position has no lookalike.
            $altsPerPos = [];
            foreach ($combo as $pos) {
                $alts = self::LOOKALIKES[$base[$pos] ?? ''] ?? [];
                if (empty($alts)) {
                    continue 2;
                }
                $altsPerPos[] = ['pos' => $pos, 'alts' => $alts];
            }

            // Try every cartesian product of alternatives for this combination of positions.
            foreach (self::cartesian(array_column($altsPerPos, 'alts')) as $altCombo) {
                $candidate = $base;
                foreach ($altsPerPos as $i => $posInfo) {
                    $candidate[$posInfo['pos']] = $altCombo[$i];
                }
                if (self::verifyMod97($candidate)) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * Return all k-element subsets of $arr (combinations without repetition).
     *
     * @param  int[]   $arr
     * @return int[][]
     */
    private static function combinations(array $arr, int $k): array
    {
        if ($k === 0) {
            return [[]];
        }
        if (empty($arr)) {
            return [];
        }
        $first    = array_shift($arr);
        $withFirst = array_map(
            fn($c) => array_merge([$first], $c),
            self::combinations($arr, $k - 1)
        );
        return array_merge($withFirst, self::combinations($arr, $k));
    }

    /**
     * Cartesian product of multiple arrays.
     *
     * @param  array<array<string>> $arrays
     * @return array<array<string>>
     */
    private static function cartesian(array $arrays): array
    {
        $result = [[]];
        foreach ($arrays as $arr) {
            $appended = [];
            foreach ($result as $product) {
                foreach ($arr as $item) {
                    $appended[] = array_merge($product, [$item]);
                }
            }
            $result = $appended;
        }
        return $result;
    }

    /**
     * Verify an IBAN using the MOD-97 algorithm (ISO 7064).
     */
    public static function verifyMod97(string $iban): bool
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
}
