#!/usr/bin/env bash
#
# Run the backend test suite with line coverage and enforce the floor.
#
# The backend runs a stock webdevops/php-apache image with the code mounted
# (see CLAUDE.md — there is deliberately no backend Dockerfile to build), so
# the coverage driver is provisioned on demand here rather than baked in.
# The install is a no-op once pcov is present, and costs ~40s the first time
# after a container recreate.
#
# CI does not use this script: shivammathur/setup-php installs pcov directly
# and the workflow calls phpunit + check-coverage.php as separate steps.
#
# Usage:  ./scripts/coverage.sh [floor-percent]     (default: 15)

set -euo pipefail

FLOOR="${1:-15}"
SERVICE=backend
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

cd "$REPO_ROOT"

if ! docker compose ps --status running --services 2>/dev/null | grep -qx "$SERVICE"; then
  echo "The '$SERVICE' container is not running. Start it with: docker compose up -d" >&2
  exit 2
fi

echo "==> Ensuring pcov is available in the $SERVICE container"
docker compose exec -T "$SERVICE" sh -c '
  php -m | grep -qi pcov && { echo "pcov already loaded"; exit 0; }
  echo "installing pcov (first run after container recreate)..."
  pecl install pcov >/dev/null 2>&1 || true
  docker-php-ext-enable pcov >/dev/null 2>&1 || true
  php -m | grep -qi pcov || { echo "FAILED to load pcov" >&2; exit 1; }
  echo "pcov installed"
'

echo "==> Running test suite with coverage"
docker compose exec -T -w /app "$SERVICE" \
  php -d pcov.enabled=1 -d pcov.directory=/app/src vendor/bin/phpunit -c phpunit.xml

echo "==> Enforcing coverage floor (${FLOOR}%)"
docker compose exec -T -w /app "$SERVICE" \
  php scripts/check-coverage.php coverage/clover.xml "$FLOOR"

echo
echo "==> Files with the most uncovered statements"
docker compose exec -T -w /app "$SERVICE" php -r '
$x = simplexml_load_file("coverage/clover.xml");
$rows = [];
foreach ($x->xpath("//file") as $f) {
    $m = $f->metrics;
    $s = (int) $m["statements"];
    $c = (int) $m["coveredstatements"];
    if ($s > $c) {
        $rows[] = [str_replace("/app/src/", "", (string) $f["name"]), $s - $c, $s, $c];
    }
}
usort($rows, fn($a, $b) => $b[1] <=> $a[1]);
printf("%-68s %9s %6s %6s\n", "FILE", "UNCOVERED", "STMT", "PCT");
foreach (array_slice($rows, 0, 25) as $r) {
    printf("%-68s %9d %6d %5.0f%%\n", $r[0], $r[1], $r[2], $r[2] ? $r[3] / $r[2] * 100 : 100);
}
'
