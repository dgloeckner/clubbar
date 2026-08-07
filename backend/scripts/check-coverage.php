<?php

declare(strict_types=1);

/**
 * Fail the build when line coverage in a clover report drops below a floor.
 *
 * Usage: php scripts/check-coverage.php <clover.xml> [min-percent]
 *
 * PHPUnit itself only *reports* coverage; this is the enforcement half of the
 * coverage gate (see issue #103). CI runs it against coverage/clover.xml.
 *
 * When min-percent is omitted, the floor is read from backend/.coverage-floor
 * (issue #168) so a coverage drop shows up as a diff on a checked-in file
 * instead of hiding inside a CI workflow argument. An explicit min-percent
 * argument still wins, for local one-off checks.
 */

const DEFAULT_FLOOR_FILENAME = '.coverage-floor';

/**
 * Resolve the floor file path relative to this script (not the caller's
 * CWD), so `php scripts/check-coverage.php ...` behaves the same regardless
 * of where it's invoked from. COVERAGE_FLOOR_FILE is a test-only override —
 * not part of the documented CLI contract — so tests can point at a fixture
 * without mutating the real backend/.coverage-floor.
 */
function defaultFloorFilePath(): string
{
    $override = getenv('COVERAGE_FLOOR_FILE');
    if ($override !== false && $override !== '') {
        return $override;
    }

    return dirname(__DIR__) . '/' . DEFAULT_FLOOR_FILENAME;
}

/**
 * Parse a floor file: a single number, optionally surrounded by blank lines
 * and full-line `#` comments. Returns null if no usable number was found —
 * the caller treats that as a hard error rather than defaulting to 0, since
 * a silent 0 floor would disable the gate entirely.
 */
function parseFloorFile(string $path): ?float
{
    if (!is_file($path)) {
        return null;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return null;
    }

    foreach (explode("\n", $contents) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (is_numeric($line)) {
            return (float) $line;
        }

        // First non-comment, non-blank line wasn't numeric — the file is
        // malformed rather than merely empty; stop looking.
        return null;
    }

    return null;
}

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: php check-coverage.php <clover.xml> [min-percent]\n");
    exit(2);
}

$cloverPath = $argv[1];

if (isset($argv[2])) {
    $floor = (float) $argv[2];
} else {
    $floorFilePath = defaultFloorFilePath();
    $floor = parseFloorFile($floorFilePath);
    if ($floor === null) {
        fwrite(STDERR, "Could not read a coverage floor from {$floorFilePath} — expected a single number, optionally with '#' comment lines.\n");
        exit(2);
    }
}

if (!is_file($cloverPath)) {
    fwrite(STDERR, "Coverage report not found: {$cloverPath}\n");
    exit(2);
}

$xml = @simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Could not parse clover XML: {$cloverPath}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

if ($statements === 0) {
    fwrite(STDERR, "Clover report contains no statements — nothing was measured.\n");
    exit(2);
}

$percent = $covered / $statements * 100;
printf("Line coverage: %.2f%% (%d/%d statements), floor %.2f%%\n", $percent, $covered, $statements, $floor);

if ($percent < $floor) {
    fwrite(STDERR, sprintf("FAIL: coverage %.2f%% is below the %.2f%% floor.\n", $percent, $floor));
    exit(1);
}

echo "OK\n";
