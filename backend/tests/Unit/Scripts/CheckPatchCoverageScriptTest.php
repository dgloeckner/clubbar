<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * The seam is the CLI contract of scripts/check-patch-coverage.php: a unified
 * diff plus one or more clover reports in, an exit code and a breakdown out.
 * CI calls it exactly this way (issue #168 §3), so the tests shell out rather
 * than reaching into the script's internals.
 */
class CheckPatchCoverageScriptTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/patch-coverage-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->workDir)) {
            rmdir($this->workDir);
        }
    }

    public function test_passes_when_every_changed_line_is_covered(): void
    {
        $diff = $this->writeDiff('backend/src/Money.php', [10, 11]);
        $clover = $this->writeClover('/build/backend/src/Money.php', [10 => 3, 11 => 1, 12 => 0]);

        $result = $this->runScript(["--diff={$diff}", "--clover={$clover}", '--min=80']);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('100.00%', $result['output']);
    }

    public function test_fails_when_changed_lines_fall_below_the_threshold(): void
    {
        $diff = $this->writeDiff('backend/src/Money.php', [10, 11, 12, 13]);
        $clover = $this->writeClover('/build/backend/src/Money.php', [10 => 1, 11 => 0, 12 => 0, 13 => 0]);

        $result = $this->runScript(["--diff={$diff}", "--clover={$clover}", '--min=80']);

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString('25.00%', $result['output']);
        // The breakdown has to name the lines, or the failure is not actionable.
        $this->assertStringContainsString('backend/src/Money.php', $result['output']);
        $this->assertStringContainsString('11', $result['output']);
    }

    public function test_ignores_changed_files_outside_the_measured_scope(): void
    {
        // A frontend page is deliberately outside the measured scope (#166), so
        // it never appears in a clover report and must not drag the gate down.
        $diff = $this->writeDiff('admin-frontend/src/pages/Members.tsx', [4, 5]);
        $clover = $this->writeClover('/build/backend/src/Money.php', [10 => 1]);

        $result = $this->runScript(["--diff={$diff}", "--clover={$clover}", '--min=80']);

        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertStringContainsString('No measurable changed lines', $result['output']);
    }

    public function test_gates_backend_and_frontend_reports_together(): void
    {
        $diff = $this->diffFor('backend/src/Money.php', [10, 11])
            . $this->diffFor('admin-frontend/src/utils/format.ts', [3, 4]);
        file_put_contents($this->workDir . '/combined.diff', $diff);
        $diffPath = $this->workDir . '/combined.diff';

        $backend = $this->writeClover('/build/backend/src/Money.php', [10 => 1, 11 => 1], 'backend.xml');
        $frontend = $this->writeClover('/build/admin-frontend/src/utils/format.ts', [3 => 0, 4 => 0], 'frontend.xml');

        $result = $this->runScript(["--diff={$diffPath}", "--clover={$backend}", "--clover={$frontend}", '--min=80']);

        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertStringContainsString('50.00%', $result['output']);
        $this->assertStringContainsString('admin-frontend/src/utils/format.ts', $result['output']);
    }

    public function test_reports_a_usage_error_when_arguments_are_missing(): void
    {
        $result = $this->runScript([]);

        $this->assertSame(2, $result['exit'], $result['output']);
        $this->assertStringContainsString('Usage', $result['output']);
    }

    public function test_reports_an_error_when_a_clover_report_is_missing(): void
    {
        $diff = $this->writeDiff('backend/src/Money.php', [10]);

        $result = $this->runScript(["--diff={$diff}", '--clover=' . $this->workDir . '/absent.xml']);

        $this->assertSame(2, $result['exit'], $result['output']);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @param array<int,int> $lines */
    private function writeDiff(string $path, array $lines): string
    {
        $file = $this->workDir . '/changes.diff';
        file_put_contents($file, $this->diffFor($path, $lines));

        return $file;
    }

    /**
     * A minimal unified diff in which every listed line is an added line.
     *
     * @param array<int,int> $lines
     */
    private function diffFor(string $path, array $lines): string
    {
        $start = (int) min($lines);
        $count = count($lines);
        $body = '';
        foreach ($lines as $line) {
            $body .= '+// line ' . $line . "\n";
        }

        return "diff --git a/{$path} b/{$path}\n"
            . "--- a/{$path}\n"
            . "+++ b/{$path}\n"
            . "@@ -{$start},0 +{$start},{$count} @@\n"
            . $body;
    }

    /** @param array<int,int> $lines line number => hit count */
    private function writeClover(string $absolutePath, array $lines, string $name = 'clover.xml'): string
    {
        $body = '';
        foreach ($lines as $num => $count) {
            $body .= sprintf('      <line num="%d" type="stmt" count="%d"/>' . "\n", $num, $count);
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<coverage generated=\"0\">\n  <project timestamp=\"0\">\n"
            . sprintf('    <file name="%s">' . "\n", $absolutePath)
            . $body
            . "    </file>\n  </project>\n</coverage>\n";

        $file = $this->workDir . '/' . $name;
        file_put_contents($file, $xml);

        return $file;
    }

    /**
     * @param array<int,string> $args
     * @return array{exit:int, output:string}
     */
    private function runScript(array $args): array
    {
        $script = dirname(__DIR__, 4) . '/scripts/check-patch-coverage.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $output = [];
        $exit = 0;
        exec($command . ' 2>&1', $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }
}
