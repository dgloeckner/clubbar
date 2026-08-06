<?php

declare(strict_types=1);

/**
 * Fail the build when line coverage in a clover report drops below a floor.
 *
 * Usage: php scripts/check-coverage.php <clover.xml> <min-percent>
 *
 * PHPUnit itself only *reports* coverage; this is the enforcement half of the
 * coverage gate (see issue #103). CI runs it against coverage/clover.xml.
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php check-coverage.php <clover.xml> <min-percent>\n");
    exit(2);
}

[, $cloverPath, $minPercent] = $argv;
$floor = (float) $minPercent;

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
