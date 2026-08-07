<?php

declare(strict_types=1);

/**
 * Fail the build when the lines a branch changed are not covered by tests.
 *
 * Usage:
 *   php scripts/check-patch-coverage.php --diff=<unified.diff> \
 *       --clover=<clover.xml> [--clover=<clover.xml> ...] [--min=80]
 *
 * The never-decrease floor (backend/.coverage-floor) and this gate fail
 * differently and both are wanted: the floor blocks a regression in the whole
 * codebase, patch coverage blocks *new* untested code. Decided in issue #168
 * §3, per the ruling on #146.
 *
 * Scope inherits each project's declared measurement scope rather than being
 * declared again here: a file the project does not measure never appears in
 * its clover report, so it is silently ignored. That is deliberate — the
 * admin frontend delegates pages and interactive components to the Playwright
 * suite (ruled on #166, see admin-frontend/vite.config.ts), and this gate must
 * not quietly overturn that.
 *
 * The diff is expected to come from the PR merge-base, e.g.
 *   git diff --unified=0 "$(git merge-base origin/main HEAD)" HEAD
 */

/** @return array{diff:?string, clover:array<int,string>, min:float} */
function parseArguments(array $argv): array
{
    $diff = null;
    $clover = [];
    $min = 80.0;

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--diff=')) {
            $diff = substr($arg, 7);
        } elseif (str_starts_with($arg, '--clover=')) {
            $clover[] = substr($arg, 9);
        } elseif (str_starts_with($arg, '--min=')) {
            $min = (float) substr($arg, 6);
        } else {
            fwrite(STDERR, "Unknown argument: {$arg}\n");
            exit(2);
        }
    }

    return ['diff' => $diff, 'clover' => $clover, 'min' => $min];
}

/**
 * Added and modified lines per file, keyed by the diff's post-image path.
 *
 * Deletions are ignored: a line that no longer exists cannot be covered.
 *
 * @return array<string, array<int, true>>
 */
function changedLines(string $diffText): array
{
    $changed = [];
    $path = null;
    $lineNumber = 0;

    foreach (preg_split('/\R/', $diffText) ?: [] as $line) {
        if (str_starts_with($line, '+++ ')) {
            $target = substr($line, 4);
            // /dev/null is a deletion; b/ is git's post-image prefix.
            $path = $target === '/dev/null' ? null : preg_replace('#^b/#', '', $target);
            continue;
        }
        if (str_starts_with($line, '@@')) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)/', $line, $m) === 1) {
                $lineNumber = (int) $m[1];
            }
            continue;
        }
        if ($path === null) {
            continue;
        }
        if (str_starts_with($line, '+')) {
            $changed[$path][$lineNumber] = true;
            $lineNumber++;
        } elseif (str_starts_with($line, ' ')) {
            $lineNumber++;
        }
        // '-' lines and metadata advance nothing in the post-image.
    }

    return $changed;
}

/**
 * Executable statements per measured file, keyed by the clover path.
 *
 * @return array<string, array<int, int>> path => (line => hit count)
 */
function measuredLines(string $cloverPath): array
{
    $xml = @simplexml_load_file($cloverPath);
    if ($xml === false) {
        fwrite(STDERR, "Could not parse clover XML: {$cloverPath}\n");
        exit(2);
    }

    $measured = [];
    foreach ($xml->xpath('//file') ?: [] as $file) {
        $name = (string) $file['name'];
        foreach ($file->line as $line) {
            // Only statements: clover also emits method and condition rows,
            // and counting a method signature twice would skew the ratio.
            if ((string) $line['type'] !== 'stmt') {
                continue;
            }
            $measured[$name][(int) $line['num']] = (int) $line['count'];
        }
    }

    return $measured;
}

/**
 * Clover records absolute build paths; the diff records repo-relative ones.
 * Matching on the path suffix avoids having to know the CI checkout root.
 */
function matchesPath(string $cloverPath, string $diffPath): bool
{
    $cloverPath = str_replace('\\', '/', $cloverPath);

    return $cloverPath === $diffPath || str_ends_with($cloverPath, '/' . $diffPath);
}

$options = parseArguments($argv);

if ($options['diff'] === null || $options['clover'] === []) {
    fwrite(STDERR, "Usage: php check-patch-coverage.php --diff=<unified.diff> --clover=<clover.xml> [--clover=...] [--min=80]\n");
    exit(2);
}

if (!is_file($options['diff'])) {
    fwrite(STDERR, "Diff not found: {$options['diff']}\n");
    exit(2);
}

$measured = [];
foreach ($options['clover'] as $report) {
    if (!is_file($report)) {
        fwrite(STDERR, "Coverage report not found: {$report}\n");
        exit(2);
    }
    $measured += measuredLines($report);
}

$changed = changedLines((string) file_get_contents($options['diff']));

$totalLines = 0;
$coveredLines = 0;
/** @var array<string, array<int,int>> $uncovered */
$uncovered = [];

foreach ($changed as $path => $lines) {
    foreach ($measured as $cloverPath => $statements) {
        if (!matchesPath($cloverPath, $path)) {
            continue;
        }
        foreach (array_keys($lines) as $lineNumber) {
            if (!isset($statements[$lineNumber])) {
                continue; // Not an executable statement — a comment, a brace.
            }
            $totalLines++;
            if ($statements[$lineNumber] > 0) {
                $coveredLines++;
            } else {
                $uncovered[$path][] = $lineNumber;
            }
        }
    }
}

if ($totalLines === 0) {
    echo "No measurable changed lines — patch coverage gate passes.\n";
    exit(0);
}

$percent = $coveredLines / $totalLines * 100;
printf(
    "Patch coverage: %.2f%% (%d/%d changed lines), minimum %.2f%%\n",
    $percent,
    $coveredLines,
    $totalLines,
    $options['min']
);

if ($uncovered !== []) {
    echo "\nUncovered changed lines:\n";
    foreach ($uncovered as $path => $lines) {
        sort($lines);
        echo '  ' . $path . ': ' . implode(', ', array_map('strval', $lines)) . "\n";
    }
    echo "\n";
}

if ($percent < $options['min']) {
    fwrite(STDERR, sprintf(
        "FAIL: patch coverage %.2f%% is below the %.2f%% minimum.\n",
        $percent,
        $options['min']
    ));
    exit(1);
}

echo "OK\n";
