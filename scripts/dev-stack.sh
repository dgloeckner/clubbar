#!/usr/bin/env bash
#
# dev-stack.sh — start, stop and wait for the local Docker Compose stack.
#
# `docker compose up -d` returns as soon as the containers are *created*, which is
# well before the backend answers HTTP: the database still has to pass its
# healthcheck, and only then does Apache/PHP-FPM come up behind it. Running the
# E2E suite in that window produces connection-refused failures that look like
# test bugs. `wait` closes that gap — it blocks on the compose healthchecks rather
# than sleeping a fixed interval.
#
# Usage:
#   scripts/dev-stack.sh up    [SERVICE...]   Start the stack (starts dockerd first)
#   scripts/dev-stack.sh wait  [SERVICE...]   Block until services are healthy
#   scripts/dev-stack.sh down  [ARG...]       Stop the stack
#
# Examples:
#   scripts/dev-stack.sh up && scripts/dev-stack.sh wait
#   scripts/dev-stack.sh up database && scripts/dev-stack.sh wait database
#   scripts/dev-stack.sh down -v
#
# Environment:
#   DEV_STACK_TIMEOUT   Seconds `wait` blocks before failing (default: 180)
#
# Exit codes:
#   0  success
#   1  usage error, or the stack did not come up
#   2  `wait` timed out — the unhealthy services are printed
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

TIMEOUT="${DEV_STACK_TIMEOUT:-180}"

cd "$PROJECT_ROOT"

usage() {
    sed -n '5,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 1
}

daemon_ready() {
    docker info > /dev/null 2>&1
}

# ---------------------------------------------------------------------------
# One line per container: "<service> <state> <health>".
# <health> is empty for services without a healthcheck.
# ---------------------------------------------------------------------------
# Optionally narrowed to the services named on the command line.
WAIT_SERVICES=()

service_states() {
    docker compose ps --all --format '{{.Service}} {{.State}} {{.Health}}' \
        ${WAIT_SERVICES[@]+"${WAIT_SERVICES[@]}"} 2>/dev/null || true
}

# Services that are not ready yet. Ready means:
#   - healthy, for a service with a healthcheck; or
#   - running, for a service without one.
# "starting" counts as NOT ready — that is the whole point of this script.
not_ready_services() {
    service_states | awk '
        $1 == "" { next }
        $3 == "healthy" { next }
        $3 == "" && $2 == "running" { next }
        { print $1 }
    '
}

# Services whose container has given up. No amount of waiting fixes these.
dead_services() {
    service_states | awk '$2 == "exited" || $2 == "dead" || $3 == "unhealthy" { print $1 }'
}

# ---------------------------------------------------------------------------
# Print why the wait failed, naming the offending services.
# ---------------------------------------------------------------------------
report_state() {
    local failed="$1"

    echo ""
    echo "--- Not ready: $(echo "$failed" | tr '\n' ' ')"
    echo ""
    docker compose ps --all || true

    # By far the most common cause in a fresh clone or a fresh cloud session:
    # the backend serves /app from a bind mount, so without vendor/ every
    # request — including /api/health — is a 500 and the healthcheck can never pass.
    if echo "$failed" | grep -qx "backend" && [ ! -d "$PROJECT_ROOT/backend/vendor" ]; then
        echo ""
        echo "HINT: backend/vendor is missing — the backend cannot serve /api/health."
        echo "      Run: cd backend && composer install"
    fi

    local svc
    for svc in $failed; do
        echo ""
        echo "--- Last 30 log lines for '$svc' ---"
        docker compose logs --tail 30 "$svc" 2>&1 || true
    done
}

cmd_up() {
    echo "=== Club Bar Dev Stack: up ==="
    "$SCRIPT_DIR/ensure-docker.sh"

    if ! daemon_ready; then
        echo "ERROR: Docker daemon is not reachable — cannot start the stack." >&2
        echo "       See logs/ensure-docker.log for why dockerd did not come up." >&2
        exit 1
    fi

    echo "--- docker compose up -d ${*:-}"
    docker compose up -d "$@"
    echo ""
    echo "Containers started. They are NOT ready yet — run:"
    echo "  scripts/dev-stack.sh wait"
}

cmd_wait() {
    WAIT_SERVICES=("$@")
    echo "=== Club Bar Dev Stack: wait (timeout ${TIMEOUT}s) ==="

    if ! daemon_ready; then
        echo "ERROR: Docker daemon is not reachable — run 'scripts/dev-stack.sh up' first." >&2
        exit 1
    fi

    if [ -z "$(service_states)" ]; then
        echo "ERROR: no containers for this project — run 'scripts/dev-stack.sh up' first." >&2
        exit 1
    fi

    # NOTE: this deliberately does not use `docker compose up --wait`. That flag
    # returns success for a container still inside its healthcheck start_period
    # (health "starting"), which is precisely the window this script exists to
    # cover — it reported the backend ready while /api/health was still 500ing.
    # Polling the health state directly is the only reliable signal.
    local waited=0 pending dead
    while :; do
        pending="$(not_ready_services)"

        if [ -z "$pending" ]; then
            echo ""
            echo "All services are ready (${waited}s)."
            return 0
        fi

        # An exited or unhealthy container will never recover on its own.
        dead="$(dead_services)"
        if [ -n "$dead" ]; then
            echo "" >&2
            echo "ERROR: service(s) failed to start." >&2
            report_state "$dead" >&2
            exit 2
        fi

        if [ "$waited" -ge "$TIMEOUT" ]; then
            echo "" >&2
            echo "ERROR: services were not ready within ${TIMEOUT}s." >&2
            report_state "$pending" >&2
            exit 2
        fi

        # Progress line every 10s so a long wait does not look like a hang.
        if [ $((waited % 10)) -eq 0 ]; then
            echo "--- waiting (${waited}s): $(echo "$pending" | tr '\n' ' ')"
        fi

        sleep 2
        waited=$((waited + 2))
    done
}

cmd_down() {
    echo "=== Club Bar Dev Stack: down ==="

    # Nothing can be running without a daemon, so this is a success, not an error.
    if ! daemon_ready; then
        echo "Docker daemon is not running — nothing to stop."
        return 0
    fi

    docker compose down "$@"
}

[ $# -ge 1 ] || usage

command="$1"
shift

case "$command" in
    up)   cmd_up "$@" ;;
    wait) cmd_wait "$@" ;;
    down) cmd_down "$@" ;;
    -h|--help|help) usage ;;
    *)
        echo "ERROR: unknown command '$command'" >&2
        usage
        ;;
esac
