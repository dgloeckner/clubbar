<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Services;

use App\Modules\Members\Services\IbanRepair;
use PHPUnit\Framework\TestCase;

class IbanRepairTest extends TestCase
{
    // ─── verifyMod97 ────────────────────────────────────────────────────────

    public function test_verifyMod97_accepts_known_valid_ibans(): void
    {
        $this->assertTrue(IbanRepair::verifyMod97('DE89370400440532013000'));
        $this->assertTrue(IbanRepair::verifyMod97('DE02100100100006820101'));
        $this->assertTrue(IbanRepair::verifyMod97('DE02370502990000684712'));
    }

    public function test_verifyMod97_rejects_invalid_ibans(): void
    {
        $this->assertFalse(IbanRepair::verifyMod97('DE89370400440532013001')); // last digit off
        $this->assertFalse(IbanRepair::verifyMod97('DE02700100100006820107')); // two 1→7 misreads
        $this->assertFalse(IbanRepair::verifyMod97('short'));
        $this->assertFalse(IbanRepair::verifyMod97(''));
    }

    // ─── repair — single substitution (depth 1) ─────────────────────────────

    public function test_repair_depth1_with_per_character_confidence(): void
    {
        // DE02700100100006820101 has '7' at position 4, should be '1'
        // Position 4 has low confidence → repair finds DE02100100100006820101
        $base       = 'DE02700100100006820101';
        $characters = $this->makeChars($base, [4 => ['confidence' => 'low']]);

        $candidates = IbanRepair::repair($base, $characters);

        $this->assertCount(1, $candidates);
        $this->assertSame('DE02100100100006820101', $candidates[0]);
    }

    public function test_repair_depth1_also_tries_medium_confidence(): void
    {
        $base       = 'DE02700100100006820101';
        $characters = $this->makeChars($base, [4 => ['confidence' => 'medium']]);

        $candidates = IbanRepair::repair($base, $characters);

        $this->assertCount(1, $candidates);
        $this->assertSame('DE02100100100006820101', $candidates[0]);
    }

    public function test_repair_skips_high_confidence_positions(): void
    {
        // All characters are high confidence — no positions are tried, so no repair
        $base       = 'DE02700100100006820101';
        $characters = $this->makeChars($base); // all high

        $candidates = IbanRepair::repair($base, $characters);

        $this->assertEmpty($candidates);
    }

    // ─── repair — double substitution (depth 2) ──────────────────────────────

    public function test_repair_depth2_fixes_two_simultaneous_misreads(): void
    {
        // DE02700100100006820107: position 4 is '7' (should be '1') AND
        // position 21 is '7' (should be '1').
        // Neither single substitution yields a valid IBAN; only the pair does.
        $base       = 'DE02700100100006820107';
        $characters = $this->makeChars($base, [
            4  => ['confidence' => 'low'],
            21 => ['confidence' => 'low'],
        ]);

        $candidates = IbanRepair::repair($base, $characters);

        $this->assertCount(1, $candidates);
        $this->assertSame('DE02100100100006820101', $candidates[0]);
    }

    public function test_repair_depth2_mixed_low_and_medium(): void
    {
        $base       = 'DE02700100100006820107';
        $characters = $this->makeChars($base, [
            4  => ['confidence' => 'medium'],
            21 => ['confidence' => 'low'],
        ]);

        $candidates = IbanRepair::repair($base, $characters);

        $this->assertCount(1, $candidates);
        $this->assertSame('DE02100100100006820101', $candidates[0]);
    }

    // ─── repair — triple substitution (depth 3) ──────────────────────────────

    public function test_repair_depth3_fixes_three_simultaneous_misreads(): void
    {
        // DE02100100100006820101 — corrupt positions 4, 7, 10 (all '1' → '7'):
        // DE02700700700006820101 — neither depth-1 nor depth-2 finds the fix alone,
        // but depth-3 substituting all three '7'→'1' yields the valid IBAN.
        $base       = 'DE02700700700006820101';
        $characters = $this->makeChars($base, [
            4  => ['confidence' => 'low'],
            7  => ['confidence' => 'low'],
            10 => ['confidence' => 'low'],
        ]);

        $candidates = IbanRepair::repair($base, $characters);

        $this->assertCount(1, $candidates);
        $this->assertSame('DE02100100100006820101', $candidates[0]);
    }

    // ─── repair — unrepairable cases ─────────────────────────────────────────

    public function test_repair_returns_empty_when_no_fix_found(): void
    {
        // Last digit tampered from '0' to '1' — '1' has no lookalike that fixes the checksum
        $base       = 'DE89370400440532013001';
        $characters = $this->makeChars($base, [21 => ['confidence' => 'low']]);

        $candidates = IbanRepair::repair($base, $characters);

        $this->assertEmpty($candidates);
    }

    public function test_repair_returns_empty_for_wrong_length(): void
    {
        $candidates = IbanRepair::repair('DE8937040044053201300', null); // 21 chars
        $this->assertEmpty($candidates);
    }

    // ─── repair — null characters (no per-character confidence) ──────────────

    public function test_repair_without_character_confidence_tries_all_positions(): void
    {
        // Same single-digit error but no character confidence provided
        $base = 'DE02700100100006820101';

        $candidates = IbanRepair::repair($base, null);

        $this->assertCount(1, $candidates);
        $this->assertSame('DE02100100100006820101', $candidates[0]);
    }

    public function test_repair_without_character_confidence_depth2_explicit(): void
    {
        // Explicitly passing maxDepth=2 with null characters finds the correct IBAN
        // but may also find other candidates (false positives via MOD-97 collision).
        // The correct IBAN must be among them.
        $base = 'DE02700100100006820107';

        $candidates = IbanRepair::repair($base, null, 2);

        $this->assertNotEmpty($candidates);
        $this->assertContains('DE02100100100006820101', $candidates);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /**
     * Build a characters array for a 22-char IBAN, all high confidence by default.
     * $overrides is keyed by position index with fields to merge.
     */
    private function makeChars(string $iban, array $overrides = []): array
    {
        $chars = [];
        for ($i = 0; $i < strlen($iban); $i++) {
            $chars[$i] = ['position' => $i, 'value' => $iban[$i], 'confidence' => 'high'];
        }
        foreach ($overrides as $pos => $data) {
            $chars[$pos] = array_merge($chars[$pos], $data);
        }
        return array_values($chars);
    }
}
