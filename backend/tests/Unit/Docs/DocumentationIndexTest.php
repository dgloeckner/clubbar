<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The indexes in `adr/README.md` and `use-cases/README.md` are how anyone —
 * contributor or agent — finds out what exists. A file missing from one is
 * invisible: it is not wrong anywhere, so no review catches it, and the reader
 * simply never learns it is there.
 *
 * That had already happened twice. `UC-A64-manage-instance-branding.md` and
 * `UC-A66-credit-limit-digest.md` were both written, both merged, and neither
 * listed (#696 item 7). Nothing checked, so nothing complained.
 *
 * This is the check. It reads the repository rather than any copy of it, so it
 * fails on the same commit that forgot the entry.
 */
final class DocumentationIndexTest extends TestCase
{
    /**
     * Every `adr/NNNN-*.md` has to appear in the ADR index.
     */
    #[DataProvider('adrs')]
    public function test_every_adr_is_listed_in_the_adr_index(string $filename): void
    {
        $index = (string) file_get_contents(self::repoRoot() . '/adr/README.md');

        $this->assertStringContainsString(
            $filename,
            $index,
            "adr/{$filename} is not linked from adr/README.md — add a row for it",
        );
    }

    /**
     * Every `use-cases/**\/UC-*.md` has to appear in the use-case index.
     */
    #[DataProvider('useCases')]
    public function test_every_use_case_is_listed_in_the_use_case_index(string $filename): void
    {
        $index = (string) file_get_contents(self::repoRoot() . '/use-cases/README.md');

        $this->assertStringContainsString(
            $filename,
            $index,
            "A use case file is not linked from use-cases/README.md — add a row for {$filename}",
        );
    }

    /**
     * The cloud environment's setup script is pasted into a dialog at
     * claude.ai/code and lives nowhere in git unless someone keeps a copy here.
     * The copy is only worth having if it is findable, so the doc that explains
     * how to keep the two in sync has to point at it.
     */
    public function test_the_cloud_setup_script_exists_and_is_documented(): void
    {
        $root = self::repoRoot();

        $this->assertFileExists(
            $root . '/.claude/cloud-setup.sh',
            'The canonical copy of the cloud environment setup script is missing',
        );

        $doc = $root . '/docs/agents/cloud-environment.md';
        $this->assertFileExists($doc, 'docs/agents/cloud-environment.md is missing');

        $this->assertStringContainsString(
            '.claude/cloud-setup.sh',
            (string) file_get_contents($doc),
            'docs/agents/cloud-environment.md must point at the script it describes',
        );
    }

    /**
     * If any unit test opens a SQLite DSN, the setup script has to install the
     * driver for it.
     *
     * The requirement is *derived from the tests* rather than written down,
     * because the failure it guards against is silent in exactly the way an
     * assertion on a hard-coded list would not catch. Twenty-nine tests used
     * `sqlite::memory:` and `php8.3-sqlite3` was simply not among the eight
     * extensions installed, so in a cloud session they errored with
     * `PDOException: could not find driver` — in repositories and domain
     * classes, reading as a broken data layer rather than as a missing package
     * (#754). CI never saw it: its runner has the driver.
     *
     * So the check asks the question the packager forgot to: does anything here
     * need SQLite, and is it installed?
     */
    public function test_the_cloud_setup_script_installs_the_drivers_the_unit_suite_opens(): void
    {
        $root = self::repoRoot();

        $usesSqlite = [];
        foreach (self::phpFilesUnder($root . '/backend/tests/Unit') as $file) {
            // This file talks *about* the DSN, so it would otherwise match
            // itself and report the wrong culprit.
            if ($file === __FILE__) {
                continue;
            }
            // A DSN in a string literal, not the word in prose.
            if (preg_match('#[\'"]sqlite:#', (string) file_get_contents($file)) === 1) {
                $usesSqlite[] = substr($file, strlen($root) + 1);
            }
        }

        if ($usesSqlite === []) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertStringContainsString(
            'php8.3-sqlite3',
            (string) file_get_contents($root . '/.claude/cloud-setup.sh'),
            count($usesSqlite) . ' unit test(s) open a SQLite DSN (e.g. ' . $usesSqlite[0]
            . ') but .claude/cloud-setup.sh does not install php8.3-sqlite3. In a cloud '
            . 'session every one of them errors with "could not find driver".',
        );
    }

    /** @return list<string> */
    private static function phpFilesUnder(string $directory): array
    {
        $files = [];
        $tree  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $directory,
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($tree as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return array<string, array{string}> */
    public static function adrs(): array
    {
        return self::cases(self::repoRoot() . '/adr/[0-9][0-9][0-9][0-9]-*.md');
    }

    /** @return array<string, array{string}> */
    public static function useCases(): array
    {
        return self::cases(self::repoRoot() . '/use-cases/*/UC-*.md');
    }

    /**
     * Named by basename so a failure reads as the missing file, not as "data set #37".
     *
     * @return array<string, array{string}>
     */
    private static function cases(string $pattern): array
    {
        $cases = [];
        foreach (glob($pattern) ?: [] as $path) {
            $cases[basename($path)] = [basename($path)];
        }

        return $cases;
    }

    /**
     * The checkout these docs live in.
     *
     * `/app` in the backend container is `./backend` alone, so the docs are not
     * reachable from there — the same gap `./scripts:/scripts:ro` already exists
     * to close. `./:/repo:ro` is what makes this test runnable in the container
     * workflow CLAUDE.md documents; a plain checkout (CI, or a host run) finds
     * them four levels up instead.
     */
    private static function repoRoot(): string
    {
        foreach ([dirname(__DIR__, 4), '/repo'] as $candidate) {
            if (is_file($candidate . '/adr/README.md')) {
                return $candidate;
            }
        }

        self::fail(
            'Could not find the repository root: neither ' . dirname(__DIR__, 4)
            . ' nor /repo contains adr/README.md. In a container, mount the repo '
            . 'root read-only at /repo (docker-compose.yml already does this for '
            . 'the backend service).',
        );
    }
}
