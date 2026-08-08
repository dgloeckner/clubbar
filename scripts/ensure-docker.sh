#!/usr/bin/env bash
#
# ensure-docker.sh — make sure a Docker daemon is reachable in a cloud session.
#
# Claude Code cloud sessions ship the Docker binaries but do not start dockerd:
# the environment cache snapshots the filesystem, not running processes, so every
# session (and every resume) begins with no daemon and `docker` fails with
# "Cannot connect to the Docker daemon". Setup scripts are skipped once the cache
# exists, so the fix has to live in the repo — this script is wired in as a
# SessionStart hook (see .claude/settings.json).
#
# Usage:
#   scripts/ensure-docker.sh
#
# Environment:
#   CLAUDE_CODE_REMOTE      Must be "true" or this script is a no-op. This is what
#                           keeps it away from a developer's local Docker Desktop.
#   ENSURE_DOCKER_TIMEOUT   Seconds to wait for readiness (default: 60)
#
# Behaviour:
#   - Idempotent: does nothing if the daemon already answers, and never starts a
#     second dockerd if one is already running (matters on resume).
#   - Always exits 0. A broken daemon must never block the session from starting;
#     the failure is reported on stdout and in the log instead.
#
# Log:
#   logs/ensure-docker.log (gitignored)
#
# NOTE: deliberately no `set -e`. Every failure path here has to fall through to
# `exit 0`, so errors are checked explicitly rather than aborting the script.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

LOG_DIR="$PROJECT_ROOT/logs"
LOG_FILE="$LOG_DIR/ensure-docker.log"
TIMEOUT="${ENSURE_DOCKER_TIMEOUT:-60}"

mkdir -p "$LOG_DIR" 2>/dev/null

# Timestamped line to the log file only.
log() {
    printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" >> "$LOG_FILE" 2>/dev/null
}

# One-line status to the terminal, and to the log. Failures must be visible.
status() {
    echo "[ensure-docker] $*"
    log "$*"
}

daemon_ready() {
    docker info > /dev/null 2>&1
}

# Remove pid files whose process is gone.
#
# This is the failure this script exists to survive on *resume*. The cloud
# filesystem snapshot preserves /var/run while none of the processes survive, so
# a resumed session can find a containerd.pid naming a pid that no longer exists.
# dockerd then reports "containerd is still running", waits for a containerd that
# will never appear, gives up with "timeout waiting for containerd to start" and
# exits — leaving the session with no daemon and a 60s stall to show for it.
clean_stale_pidfiles() {
    local pidfile pid
    for pidfile in /var/run/docker.pid \
                   /var/run/docker/containerd/containerd.pid \
                   /run/containerd/containerd.pid; do
        [ -f "$pidfile" ] || continue
        pid="$(cat "$pidfile" 2>/dev/null)"
        # A live owner means this is not stale — leave it alone.
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            continue
        fi
        log "Removing stale pid file $pidfile (pid ${pid:-empty} is not running)."
        rm -f "$pidfile"
    done
}

# ---------------------------------------------------------------------------
# 1. Guard: cloud sessions only
# ---------------------------------------------------------------------------
# Without this the hook would start (or interfere with) Docker on a contributor's
# own machine. CLAUDE_CODE_REMOTE is set to "true" only inside a cloud session.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    log "CLAUDE_CODE_REMOTE is not \"true\" — no-op (local machine)."
    exit 0
fi

log "=== ensure-docker start (timeout ${TIMEOUT}s) ==="

# ---------------------------------------------------------------------------
# 2. Already up? Nothing to do.
# ---------------------------------------------------------------------------
if daemon_ready; then
    status "Docker daemon already running."
    exit 0
fi

# ---------------------------------------------------------------------------
# 3. Do we have a daemon to start at all?
# ---------------------------------------------------------------------------
if ! command -v dockerd > /dev/null 2>&1; then
    status "dockerd not installed — skipping (docker commands will not work)."
    exit 0
fi

# ---------------------------------------------------------------------------
# 4. Start dockerd and wait for readiness, bounded
# ---------------------------------------------------------------------------
# There is no systemd in the session image (PID 1 is the session supervisor), so
# `systemctl start docker` is not an option — dockerd is exec'd directly. The
# session user is root, so no sudo is needed.
#
# TIMEOUT is a budget for the whole step, not per attempt: a second attempt only
# happens when dockerd exits early, and it still has to finish inside the budget.
deadline=$((SECONDS + TIMEOUT))
attempt=1
attempts=2

while [ "$attempt" -le "$attempts" ]; do
    clean_stale_pidfiles

    if pgrep -x dockerd > /dev/null 2>&1; then
        status "dockerd process already present but not answering yet — waiting."
    else
        status "Starting dockerd (attempt ${attempt}/${attempts})..."
        log "--- dockerd output below ---"
        # setsid + nohup: detach from the hook's process group so the daemon survives
        # once the hook returns and Claude Code reaps the session-start subshell.
        setsid nohup dockerd >> "$LOG_FILE" 2>&1 &
        disown 2>/dev/null
    fi

    while [ "$SECONDS" -lt "$deadline" ]; do
        if daemon_ready; then
            status "Docker daemon ready after ${SECONDS}s."
            exit 0
        fi
        # dockerd giving up is a definite answer — retry now rather than sit out
        # the remaining budget waiting for a process that is already gone.
        if ! pgrep -x dockerd > /dev/null 2>&1; then
            status "dockerd exited during startup — see $LOG_FILE"
            break
        fi
        sleep 1
    done

    [ "$SECONDS" -lt "$deadline" ] || break
    attempt=$((attempt + 1))
done

status "Docker daemon did NOT become ready within ${TIMEOUT}s — see $LOG_FILE"
exit 0
