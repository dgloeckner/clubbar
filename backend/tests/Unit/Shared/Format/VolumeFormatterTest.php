<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Format;

use App\Shared\Format\VolumeFormatter;
use PHPUnit\Framework\TestCase;

/**
 * The PHP third of one formatting rule (ADR-0056, decision 4).
 *
 * Every vector comes from `api/fixtures/volume-format.json`, which the
 * TypeScript and Dart suites read too. Nothing is hard-coded here on purpose:
 * three implementations checked against three private lists of examples agree
 * only by luck, and the first divergence would show up as a Deckelauszug that
 * says `0.5 l` beside a terminal badge that says `0,5 l`.
 *
 * A new vector belongs in the fixture. Adding one here instead is what this
 * arrangement exists to prevent.
 */
class VolumeFormatterTest extends TestCase
{
    /** The one file all three implementations answer to. */
    private const FIXTURE = '/api/fixtures/volume-format.json';

    /** @return array<string, array{int, string, string}> */
    public static function vectors(): array
    {
        $fixture = self::fixture();

        $cases = [];
        foreach ($fixture['cases'] as $case) {
            foreach ($fixture['languages'] as $lang) {
                $cases["{$case['ml']} ml in {$lang}"] = [
                    (int) $case['ml'],
                    (string) $lang,
                    (string) $case['expected'][$lang],
                ];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider vectors
     */
    public function test_formats_every_shared_vector(int $ml, string $lang, string $expected): void
    {
        $this->assertSame(
            $expected,
            VolumeFormatter::format($ml, $lang),
            "vector {$ml} ml in {$lang}"
        );
    }

    public function test_the_fixture_carries_the_whole_range_it_claims_to(): void
    {
        // A guard on the guard: an empty or truncated fixture would make every
        // test above pass by having nothing to check.
        $fixture = self::fixture();

        $millilitres = array_column($fixture['cases'], 'ml');

        $this->assertGreaterThanOrEqual(15, count($millilitres), 'the fixture should cover both units and both rounding directions');
        $this->assertContains(1, $millilitres, 'the bottom of the validated range');
        $this->assertContains(10000, $millilitres, 'the top of the validated range');
        $this->assertContains(1005, $millilitres, 'the rounding boundary');
        $this->assertSame(['de', 'en'], $fixture['languages']);
    }

    public function test_the_unit_is_preceded_by_a_no_break_space(): void
    {
        // A plain space would let a badge wrap between the number and its unit,
        // leaving `0,5` at the end of one line and `l` at the start of the next.
        $this->assertStringContainsString("\u{00A0}", VolumeFormatter::format(500, 'de'));
        $this->assertStringNotContainsString(' ', VolumeFormatter::format(500, 'de'));
    }

    public function test_an_unknown_language_falls_back_to_the_decimal_comma(): void
    {
        // Called from the middle of rendering a statement: wrong punctuation is
        // a smaller failure than a statement that does not render at all.
        $this->assertSame("0,5\u{00A0}l", VolumeFormatter::format(500, 'fr'));
        $this->assertSame("0,5\u{00A0}l", VolumeFormatter::format(500, ''));
    }

    public function test_the_language_is_matched_case_insensitively(): void
    {
        $this->assertSame("0.5\u{00A0}l", VolumeFormatter::format(500, 'EN'));
    }

    public function test_a_product_with_no_volume_formats_to_nothing(): void
    {
        $this->assertSame('', VolumeFormatter::formatOrEmpty(null, 'de'));
        $this->assertSame("0,5\u{00A0}l", VolumeFormatter::formatOrEmpty(500, 'de'));
    }

    public function test_with_name_puts_the_size_after_the_name(): void
    {
        $this->assertSame("Weizenbier 0,5\u{00A0}l", VolumeFormatter::withName('Weizenbier', 500, 'de'));
        $this->assertSame("Wheat beer 0.5\u{00A0}l", VolumeFormatter::withName('Wheat beer', 500, 'en'));
    }

    public function test_with_name_prints_the_name_alone_when_there_is_no_size(): void
    {
        // No trailing space, no dash, nothing: a Sauna-Token is a Sauna-Token.
        $this->assertSame('Sauna-Token', VolumeFormatter::withName('Sauna-Token', null, 'de'));
    }

    /** @return array{languages: list<string>, cases: list<array<string, mixed>>} */
    private static function fixture(): array
    {
        $path = self::repoRoot() . self::FIXTURE;

        if (!is_file($path)) {
            throw new \RuntimeException(
                $path . ' is missing. It is the single source of the volume formatting rule '
                . '(ADR-0056) and all three language suites read it.'
            );
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The checkout the fixture lives in.
     *
     * `/app` in the backend container is `./backend` alone, so `api/` is not
     * reachable from there — the same gap `./:/repo:ro` already exists to close
     * for DocumentationIndexTest. A plain checkout (CI, or a host run) finds it
     * five levels up instead.
     */
    private static function repoRoot(): string
    {
        foreach ([dirname(__DIR__, 5), '/repo'] as $candidate) {
            if (is_file($candidate . self::FIXTURE)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Could not find the repository root: neither ' . dirname(__DIR__, 5)
            . ' nor /repo contains api/fixtures/volume-format.json. In a container, mount '
            . 'the repo root read-only at /repo (docker-compose.yml already does this for '
            . 'the backend service).'
        );
    }
}
