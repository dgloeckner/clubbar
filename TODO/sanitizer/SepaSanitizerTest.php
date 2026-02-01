<?php

declare(strict_types=1);

require_once __DIR__ . '/SepaSanitizer.php';

/**
 * Tests for SepaSanitizer
 *
 * Run with: php SepaSanitizerTest.php
 * (Self-contained test runner, no PHPUnit required)
 */
class SepaSanitizerTest
{
    private int $passed = 0;
    private int $failed = 0;
    /** @var array<string> */
    private array $failures = [];

    // ─── German Umlauts (DK Alternative: ae/oe/ue/ss) ───────────────

    public function testGermanUmlauts(): void
    {
        $this->assertEqual('Mueller', SepaSanitizer::sanitize('Müller'), 'ü → ue');
        $this->assertEqual('Gaertner', SepaSanitizer::sanitize('Gärtner'), 'ä → ae');
        $this->assertEqual('Schroeder', SepaSanitizer::sanitize('Schröder'), 'ö → oe');
        $this->assertEqual('Strasse', SepaSanitizer::sanitize('Straße'), 'ß → ss');
    }

    public function testGermanUmlautsUppercase(): void
    {
        $this->assertEqual('Ae', SepaSanitizer::sanitize('Ä'), 'Ä → Ae');
        $this->assertEqual('Oe', SepaSanitizer::sanitize('Ö'), 'Ö → Oe');
        $this->assertEqual('Ue', SepaSanitizer::sanitize('Ü'), 'Ü → Ue');
    }

    public function testComplexGermanName(): void
    {
        $this->assertEqual(
            'Baerbel Gruenwald-Goetz',
            SepaSanitizer::sanitize('Bärbel Grünwald-Götz'),
            'Complex German name with multiple umlauts and hyphen'
        );
    }

    // ─── Accented Characters (NFD Normalization) ─────────────────────

    public function testFrenchAccents(): void
    {
        $this->assertEqual('Garcon', SepaSanitizer::sanitize('Garçon'), 'ç → c');
        $this->assertEqual('Rene', SepaSanitizer::sanitize('René'), 'é → e');
        $this->assertEqual('francais', SepaSanitizer::sanitize('français'), 'ç → c');
        $this->assertEqual('a la creme', SepaSanitizer::sanitize('à la crème'), 'à → a, è → e');
    }

    public function testSpanishAccents(): void
    {
        $this->assertEqual('Jose Garcia', SepaSanitizer::sanitize('José García'), 'é → e, í → i');
        $this->assertEqual('Espana', SepaSanitizer::sanitize('España'), 'ñ → n');
        $this->assertEqual('nino', SepaSanitizer::sanitize('niño'), 'ñ → n');
    }

    public function testPortugueseAccents(): void
    {
        $this->assertEqual('Joao', SepaSanitizer::sanitize('João'), 'ã → a');
        $this->assertEqual('cacao', SepaSanitizer::sanitize('cação'), 'ç → c, ã → a');
    }

    public function testCzechSlovakAccents(): void
    {
        $this->assertEqual('Dvorak', SepaSanitizer::sanitize('Dvořák'), 'ř → r, á → a');
        $this->assertEqual('Jiri', SepaSanitizer::sanitize('Jiří'), 'ří → ri');
    }

    public function testScandinavianCharacters(): void
    {
        $this->assertEqual('O', SepaSanitizer::sanitize('Ø'), 'Ø → O');
        $this->assertEqual('o', SepaSanitizer::sanitize('ø'), 'ø → o');
        $this->assertEqual('Aa', SepaSanitizer::sanitize('Åa'), 'Å → A (via NFD)');
    }

    public function testIcelandicCharacters(): void
    {
        $this->assertEqual('D', SepaSanitizer::sanitize('Ð'), 'Ð → D');
        $this->assertEqual('d', SepaSanitizer::sanitize('ð'), 'ð → d');
        $this->assertEqual('Th', SepaSanitizer::sanitize('Þ'), 'Þ → Th');
        $this->assertEqual('th', SepaSanitizer::sanitize('þ'), 'þ → th');
    }

    public function testPolishCharacters(): void
    {
        $this->assertEqual('Lodz', SepaSanitizer::sanitize('Łódź'), 'Ł → L, ó → o, ź → z');
        $this->assertEqual('Krakow', SepaSanitizer::sanitize('Kraków'), 'ó → o');
        $this->assertEqual('Lech Walesa', SepaSanitizer::sanitize('Lech Wałęsa'), 'ł → l, ę → e');
    }

    public function testTurkishCharacters(): void
    {
        $this->assertEqual('Istanbul', SepaSanitizer::sanitize('İstanbul'), 'İ → I');
    }

    public function testLigatures(): void
    {
        $this->assertEqual('AE', SepaSanitizer::sanitize('Æ'), 'Æ → AE');
        $this->assertEqual('ae', SepaSanitizer::sanitize('æ'), 'æ → ae');
        $this->assertEqual('OE', SepaSanitizer::sanitize('Œ'), 'Œ → OE');
        $this->assertEqual('oe', SepaSanitizer::sanitize('œ'), 'œ → oe');
    }

    // ─── Special Characters ──────────────────────────────────────────

    public function testSpecialCharacterReplacements(): void
    {
        $this->assertEqual('+', SepaSanitizer::sanitize('&'), '& → +');
        $this->assertEqual('.', SepaSanitizer::sanitize('*'), '* → .');
        $this->assertEqual('-', SepaSanitizer::sanitize('–'), 'En dash → -');
        $this->assertEqual('-', SepaSanitizer::sanitize('—'), 'Em dash → -');
        $this->assertEqual("'", SepaSanitizer::sanitize("\u{2019}"), 'Right single quote → apostrophe');
    }

    public function testCurrencySymbols(): void
    {
        $this->assertEqual('EUR', SepaSanitizer::sanitize('€'), '€ → EUR');
        $this->assertEqual('GBP', SepaSanitizer::sanitize('£'), '£ → GBP');
        $this->assertEqual('USD', SepaSanitizer::sanitize('$'), '$ → USD');
    }

    // ─── Passthrough (already valid) ─────────────────────────────────

    public function testPassthroughValidChars(): void
    {
        $valid = "abcdefghijklmnopqrstuvwxyz ABCDEFGHIJKLMNOPQRSTUVWXYZ 0123456789 /-?:().,'+";
        $this->assertEqual($valid, SepaSanitizer::sanitize($valid), 'All valid SEPA chars pass through');
    }

    public function testPassthroughSimpleName(): void
    {
        $this->assertEqual('Max Mustermann', SepaSanitizer::sanitize('Max Mustermann'), 'Simple name passes through');
    }

    // ─── Whitespace Handling ─────────────────────────────────────────

    public function testWhitespaceNormalization(): void
    {
        $this->assertEqual('Max Mustermann', SepaSanitizer::sanitize('Max  Mustermann'), 'Double space collapsed');
        $this->assertEqual('Max Mustermann', SepaSanitizer::sanitize('  Max Mustermann  '), 'Leading/trailing spaces trimmed');
        $this->assertEqual('a b c', SepaSanitizer::sanitize("a\tb\nc"), 'Tab and newline → space');
    }

    // ─── Length Truncation ───────────────────────────────────────────

    public function testNameMaxLength(): void
    {
        $longName = str_repeat('A', 100);
        $result = SepaSanitizer::sanitizeName($longName);
        $this->assertEqual(70, mb_strlen($result), 'Name truncated to 70 chars');
    }

    public function testRemittanceMaxLength(): void
    {
        $longText = str_repeat('B', 200);
        $result = SepaSanitizer::sanitizeRemittance($longText);
        $this->assertEqual(140, mb_strlen($result), 'Remittance truncated to 140 chars');
    }

    public function testTruncationAfterExpansion(): void
    {
        // 'ü' expands to 'ue' (2 chars), so a string of 70 ü's becomes 140 chars
        // sanitizeName should truncate to 70
        $input = str_repeat('ü', 70);
        $result = SepaSanitizer::sanitizeName($input);
        $this->assertEqual(70, mb_strlen($result), 'Truncated after umlaut expansion');
        $this->assertEqual(str_repeat('ue', 35), $result, 'Content is correct after truncation');
    }

    // ─── Reference Sanitization ──────────────────────────────────────

    public function testReferenceSanitization(): void
    {
        $this->assertEqual('REF-001', SepaSanitizer::sanitizeReference('REF-001'), 'Simple reference passes through');
        $this->assertEqual('REF/001', SepaSanitizer::sanitizeReference('/REF//001/'), 'Slashes cleaned per SEPA rules');
    }

    // ─── Validation ──────────────────────────────────────────────────

    public function testIsValid(): void
    {
        $this->assertTrue(SepaSanitizer::isValid('Max Mustermann'), 'Valid name');
        $this->assertTrue(SepaSanitizer::isValid("Test-123/abc?:()+.,'"), 'All allowed special chars');
        $this->assertFalse(SepaSanitizer::isValid('Müller'), 'Umlaut is invalid');
        $this->assertFalse(SepaSanitizer::isValid('test@email'), '@ is invalid');
    }

    public function testGetInvalidCharacters(): void
    {
        $invalid = SepaSanitizer::getInvalidCharacters('Müller & Söhne');
        $this->assertTrue(in_array('ü', $invalid), 'Found ü');
        $this->assertTrue(in_array('&', $invalid), 'Found &');
        $this->assertTrue(in_array('ö', $invalid), 'Found ö');
    }

    // ─── Real-World FRGS Scenarios ───────────────────────────────────

    public function testFrgsMembers(): void
    {
        // Typical German club member names
        $this->assertEqual('Hans-Juergen Schaefer', SepaSanitizer::sanitize('Hans-Jürgen Schäfer'));
        $this->assertEqual('Goetz von Berlichingen', SepaSanitizer::sanitize('Götz von Berlichingen'));
        $this->assertEqual('Bjoern Hoecke-Mueller', SepaSanitizer::sanitize('Björn Höcke-Müller'));
    }

    public function testFrgsAbweichenderKontoinhaber(): void
    {
        // Parent paying for child — account holder has different name than member
        $this->assertEqual(
            'Dr. Sabine Koenig-Schaefer',
            SepaSanitizer::sanitize('Dr. Sabine König-Schäfer'),
            'Account holder name with title and double umlauts'
        );
    }

    public function testFrgsVerwendungszweck(): void
    {
        $this->assertEqual(
            'FRGS Mitgliedsbeitrag 2026 - Mueller, Hans',
            SepaSanitizer::sanitizeRemittance('FRGS Mitgliedsbeitrag 2026 – Müller, Hans'),
            'Verwendungszweck with en dash and umlaut'
        );
    }

    public function testFrgsInternationalMember(): void
    {
        // International members at a Frankfurt rowing club
        $this->assertEqual('Francois Lefevre', SepaSanitizer::sanitize('François Lefèvre'));
        $this->assertEqual('Soren Kierkegaard', SepaSanitizer::sanitize('Søren Kierkegaard'));
        $this->assertEqual('Pawel Nowak', SepaSanitizer::sanitize('Paweł Nowak'));
    }

    // ─── Edge Cases ──────────────────────────────────────────────────

    public function testEmptyString(): void
    {
        $this->assertEqual('', SepaSanitizer::sanitize(''), 'Empty string');
    }

    public function testOnlyInvalidChars(): void
    {
        $this->assertEqual('', SepaSanitizer::sanitize('🍺🚣'), 'Emoji stripped completely');
    }

    public function testMixedScripts(): void
    {
        // If someone copy-pastes from somewhere with Cyrillic or other scripts
        $this->assertEqual('Test', SepaSanitizer::sanitize('Test'), 'Latin part preserved');
    }

    public function testPrecomposedVsDecomposed(): void
    {
        // é can be either U+00E9 (precomposed) or U+0065 U+0301 (decomposed)
        // Both should produce the same result
        $precomposed = SepaSanitizer::sanitize("\u{00E9}");  // é precomposed
        $decomposed = SepaSanitizer::sanitize("e\u{0301}");  // e + combining acute
        $this->assertEqual($precomposed, $decomposed, 'Precomposed and decomposed yield same result');
        $this->assertEqual('e', $precomposed, 'Both become plain e');
    }

    // ─── Test Runner ─────────────────────────────────────────────────

    public function run(): void
    {
        echo "╔══════════════════════════════════════════════════╗\n";
        echo "║        SepaSanitizer Test Suite                  ║\n";
        echo "╚══════════════════════════════════════════════════╝\n\n";

        $reflection = new \ReflectionClass($this);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if (str_starts_with($method->getName(), 'test')) {
                $label = $this->formatMethodName($method->getName());
                echo "  ▸ {$label}\n";
                try {
                    $method->invoke($this);
                } catch (\Throwable $e) {
                    $this->failed++;
                    $this->failures[] = "  ✗ {$label}: EXCEPTION: {$e->getMessage()}";
                    echo "    ✗ EXCEPTION: {$e->getMessage()}\n";
                }
            }
        }

        echo "\n" . str_repeat('─', 52) . "\n";
        $total = $this->passed + $this->failed;
        echo "  Results: {$this->passed}/{$total} passed";
        if ($this->failed > 0) {
            echo ", {$this->failed} FAILED";
        }
        echo "\n";

        if (!empty($this->failures)) {
            echo "\n  Failures:\n";
            foreach ($this->failures as $failure) {
                echo "  {$failure}\n";
            }
        }

        echo "\n";
        exit($this->failed > 0 ? 1 : 0);
    }

    private function assertEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "    ✓ {$message}\n";
        } else {
            $this->failed++;
            $detail = "Expected: " . var_export($expected, true) . " | Got: " . var_export($actual, true);
            $this->failures[] = "{$message}: {$detail}";
            echo "    ✗ {$message}\n      {$detail}\n";
        }
    }

    private function assertTrue(bool $value, string $message = ''): void
    {
        $this->assertEqual(true, $value, $message);
    }

    private function assertFalse(bool $value, string $message = ''): void
    {
        $this->assertEqual(false, $value, $message);
    }

    private function formatMethodName(string $name): string
    {
        $name = preg_replace('/^test/', '', $name);
        $name = preg_replace('/([A-Z])/', ' $1', $name);
        return trim($name);
    }
}

// Run tests
(new SepaSanitizerTest())->run();
