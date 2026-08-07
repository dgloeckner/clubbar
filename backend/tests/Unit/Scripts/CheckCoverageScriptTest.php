<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Exercises scripts/check-coverage.php as a black box (shelling out), since
 * its actual contract is the CLI exit code / stderr — not any internal
 * function signature. Fixture clover files and floor files live in a temp
 * dir per test so runs never depend on (or mutate) the real
 * backend/.coverage-floor or coverage/clover.xml.
 */
class CheckCoverageScriptTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../scripts/check-coverage.php';

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/check-coverage-test-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function writeClover(float $covered, float $statements): string
    {
        $path = $this->tmpDir . '/clover.xml';
        file_put_contents($path, sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><coverage><project><metrics statements="%d" coveredstatements="%d"/></project></coverage>',
            (int) $statements,
            (int) $covered
        ));

        return $path;
    }

    /**
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runScript(array $args, ?string $floorFileOverride = null): array
    {
        $cmd = array_merge(['php', self::SCRIPT], $args);
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // COVERAGE_FLOOR_FILE is a test-only escape hatch so fixture floor
        // files don't require mutating the real backend/.coverage-floor —
        // it is not part of the documented CLI contract.
        $env = $floorFileOverride !== null
            ? array_merge(getenv() ?: [], ['COVERAGE_FLOOR_FILE' => $floorFileOverride])
            : null;
        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $stdout, $stderr];
    }

    // ── explicit floor argument (existing two-argument form) ───────────

    public function test_explicit_floor_argument_passes_when_coverage_meets_it(): void
    {
        $clover = $this->writeClover(covered: 50, statements: 100);

        [$exitCode, $stdout] = $this->runScript([$clover, '25']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('OK', $stdout);
    }

    public function test_explicit_floor_argument_fails_when_coverage_is_below_it(): void
    {
        $clover = $this->writeClover(covered: 10, statements: 100);

        [$exitCode, , $stderr] = $this->runScript([$clover, '25']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FAIL', $stderr);
    }

    // ── floor read from backend/.coverage-floor (single-argument form) ─

    public function test_missing_floor_argument_reads_the_checked_in_floor_file(): void
    {
        // backend/.coverage-floor currently holds 25 — see that file.
        $clover = $this->writeClover(covered: 50, statements: 100);

        [$exitCode, $stdout] = $this->runScript([$clover]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('floor 25.00%', $stdout);
    }

    public function test_missing_floor_argument_fails_when_coverage_is_below_the_file_floor(): void
    {
        $clover = $this->writeClover(covered: 10, statements: 100);

        [$exitCode, , $stderr] = $this->runScript([$clover]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FAIL', $stderr);
    }

    // ── usage errors ─────────────────────────────────────────────────

    public function test_no_arguments_is_a_usage_error(): void
    {
        [$exitCode, , $stderr] = $this->runScript([]);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Usage', $stderr);
    }

    public function test_too_many_arguments_is_a_usage_error(): void
    {
        $clover = $this->writeClover(covered: 50, statements: 100);

        [$exitCode, , $stderr] = $this->runScript([$clover, '25', 'extra']);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Usage', $stderr);
    }

    // ── floor file content edge cases ───────────────────────────────

    public function test_floor_file_with_comment_header_and_whitespace_is_parsed(): void
    {
        $clover = $this->writeClover(covered: 50, statements: 100);
        $floorFile = $this->tmpDir . '/.coverage-floor';
        file_put_contents($floorFile, "# never-decrease floor\n\n  30  \n");

        [$exitCode, $stdout] = $this->runScript([$clover], $floorFile);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('floor 30.00%', $stdout);
    }

    public function test_missing_floor_file_is_a_hard_error_not_a_silent_zero(): void
    {
        $clover = $this->writeClover(covered: 50, statements: 100);
        $missingFloorFile = $this->tmpDir . '/does-not-exist';

        [$exitCode, , $stderr] = $this->runScript([$clover], $missingFloorFile);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('coverage floor', $stderr);
    }

    public function test_empty_floor_file_is_a_hard_error(): void
    {
        $clover = $this->writeClover(covered: 50, statements: 100);
        $floorFile = $this->tmpDir . '/.coverage-floor';
        file_put_contents($floorFile, "# only a comment, no number\n");

        [$exitCode, , $stderr] = $this->runScript([$clover], $floorFile);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('coverage floor', $stderr);
    }

    public function test_non_numeric_floor_file_is_a_hard_error(): void
    {
        $clover = $this->writeClover(covered: 50, statements: 100);
        $floorFile = $this->tmpDir . '/.coverage-floor';
        file_put_contents($floorFile, "not-a-number\n");

        [$exitCode, , $stderr] = $this->runScript([$clover], $floorFile);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('coverage floor', $stderr);
    }

    public function test_default_floor_file_path_resolves_next_to_the_script(): void
    {
        // No --floor-file override: must fall back to backend/.coverage-floor,
        // resolved relative to the script itself (dirname(__DIR__)), not CWD.
        $clover = $this->writeClover(covered: 24, statements: 100);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['php', self::SCRIPT, $clover],
            $descriptors,
            $pipes,
            $this->tmpDir // run from an unrelated CWD
        );
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // 24% < the checked-in 25% floor, regardless of CWD.
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('floor 25.00%', $stdout);
    }
}
