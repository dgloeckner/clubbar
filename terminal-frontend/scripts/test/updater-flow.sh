#!/usr/bin/env bash
#
# updater-flow.sh — what `clubbar-update.sh` does to a terminal.
#
# The updater's claims are all about *not* acting: ADR-0054 lists five refusals
# and says all five are "no update", never an error. The one that is worth the
# most is the rollback, and it is also the one nobody will ever exercise by
# hand — it needs a release that installs and then does not come up.
#
# So this drives the real script against a sandbox: a fake install root, a fake
# data directory, a `curl` that serves fixtures and a `systemctl` that records
# what it was asked to do and can pretend the app came back (or did not).
# Nothing here touches /opt, the network, or a real systemd.
#
# Usage: ./updater-flow.sh      (exit 0 = all cases pass)

set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
UPDATER="$HERE/../clubbar-update.sh"

FAILURES=0
pass() { printf '[ OK ]  %s\n' "$1"; }
fail() { FAILURES=$((FAILURES + 1)); printf '[FAIL]  %s\n' "$1"; }

SANDBOX_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/clubbar-updater-test.XXXXXX")"
# TempTree's rule from CLAUDE.md, in shell: refuse to delete anything that is
# not the directory this run created under the system temp directory. An unset
# variable here would otherwise expand to `rm -rf /*`.
cleanup() {
  case "$SANDBOX_ROOT" in
    "${TMPDIR:-/tmp}"/clubbar-updater-test.*) rm -rf "$SANDBOX_ROOT" ;;
    *) echo "refusing to clean up '$SANDBOX_ROOT'" >&2 ;;
  esac
}
trap cleanup EXIT

CASE=0

# ---------------------------------------------------------------------------
# The sandbox
# ---------------------------------------------------------------------------

# Builds a terminal in a known state and exports the environment the updater
# reads. Every case starts from a fresh one, so nothing leaks between them
# (E2E pattern 001, applied to a shell suite).
setup_case() {
  CASE=$((CASE + 1))
  CASE_DIR="$SANDBOX_ROOT/case-$CASE"
  export CLUBBAR_ROOT="$CASE_DIR/opt"
  export CLUBBAR_DATA_DIR="$CASE_DIR/data"
  export CLUBBAR_REPO="clubtest/clubbar"
  export CLUBBAR_UNIT="clubbar-terminal.service"
  export CLUBBAR_HEARTBEAT_TIMEOUT=12
  export FIXTURES="$CASE_DIR/fixtures"
  export SYSTEMCTL_LOG="$CASE_DIR/systemctl.log"
  export FAKE_APP_HEALTHY=1

  mkdir -p "$CLUBBAR_ROOT/releases/v1.0.6" "$CLUBBAR_DATA_DIR" "$FIXTURES/assets"
  : > "$SYSTEMCTL_LOG"

  # A believable installed slot: the executable is what the updater checks for
  # in a downloaded bundle, so the installed one should have it too.
  printf '#!/bin/sh\nexit 0\n' > "$CLUBBAR_ROOT/releases/v1.0.6/clubbar_terminal"
  chmod +x "$CLUBBAR_ROOT/releases/v1.0.6/clubbar_terminal"
  ln -sfn "$CLUBBAR_ROOT/releases/v1.0.6" "$CLUBBAR_ROOT/current"

  cat > "$CLUBBAR_DATA_DIR/config.json" <<'JSON'
{
  "terminalId": "Test-Terminal",
  "apiUrl": "https://club.example.invalid/api",
  "apiToken": "0000000000000000000000000000000000000000000000000000000000000000"
}
JSON

  # Synced and freshly alive: the state in which an update is allowed.
  write_status "$(date -u -d '-30 seconds' +%Y-%m-%dT%H:%M:%SZ)" 0
  echo "pretend-database" > "$CLUBBAR_DATA_DIR/clubbar_terminal.db"

  backend_reports v1.0.7
  publish_release v1.0.7
}

write_status() {
  cat > "$CLUBBAR_DATA_DIR/status.json" <<JSON
{
  "app_version": "v1.0.6",
  "last_sync_at": ${1:+\"$1\"}${1:-null},
  "unsynced_transactions": $2,
  "written_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON
}

backend_reports() {
  printf '{"status":"ok","version":"%s","instance_name":"Test"}\n' "$1" > "$FIXTURES/health.json"
}

# Build a release the fake GitHub will serve: a tarball carrying an executable,
# its .sha256 companion, and the JSON listing both.
publish_release() {
  local tag="$1" arch bundle
  arch="$(uname -m)"
  case "$arch" in aarch64|arm64) arch=arm64 ;; x86_64|amd64) arch=x64 ;; esac
  local asset="clubbar-terminal-linux-${arch}-${tag}.tar.gz"

  bundle="$FIXTURES/bundle-$tag"
  rm -rf "$bundle"; mkdir -p "$bundle"
  printf '#!/bin/sh\nexit 0\n' > "$bundle/clubbar_terminal"
  chmod +x "$bundle/clubbar_terminal"
  echo "$tag" > "$bundle/marker"
  tar -czf "$FIXTURES/assets/$asset" -C "$bundle" .
  (cd "$FIXTURES/assets" && sha256sum "$asset" > "$asset.sha256")

  cat > "$FIXTURES/release-$tag.json" <<JSON
{"tag_name":"$tag","assets":[
{"name":"$asset","browser_download_url":"https://example.invalid/assets/$asset"},
{"name":"$asset.sha256","browser_download_url":"https://example.invalid/assets/$asset.sha256"}
]}
JSON
}

unpublish_asset() {
  local tag="$1"
  cat > "$FIXTURES/release-$tag.json" <<JSON
{"tag_name":"$tag","assets":[]}
JSON
}

unpublish_checksum() {
  local tag="$1" arch
  arch="$(uname -m)"
  case "$arch" in aarch64|arm64) arch=arm64 ;; x86_64|amd64) arch=x64 ;; esac
  local asset="clubbar-terminal-linux-${arch}-${tag}.tar.gz"
  cat > "$FIXTURES/release-$tag.json" <<JSON
{"tag_name":"$tag","assets":[
{"name":"$asset","browser_download_url":"https://example.invalid/assets/$asset"}
]}
JSON
}

# ---------------------------------------------------------------------------
# The stubs
# ---------------------------------------------------------------------------

STUB_BIN="$SANDBOX_ROOT/bin"
mkdir -p "$STUB_BIN"

cat > "$STUB_BIN/curl" <<'STUB'
#!/usr/bin/env bash
# Serves the fixtures under $FIXTURES. Exit 22 is what real curl returns for a
# 404 under -f, which is the path "the backend reports a tag this project never
# released" takes.
out=""; url=""
while [ $# -gt 0 ]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    http*|https*) url="$1"; shift ;;
    *) shift ;;
  esac
done
emit() { if [ -n "$out" ]; then cat > "$out"; else cat; fi; }
case "$url" in
  */health)
    [ -f "$FIXTURES/health.json" ] || exit 7
    emit < "$FIXTURES/health.json" ;;
  */releases/tags/*)
    tag="${url##*/}"
    [ -f "$FIXTURES/release-$tag.json" ] || exit 22
    emit < "$FIXTURES/release-$tag.json" ;;
  */assets/*)
    name="${url##*/}"
    [ -f "$FIXTURES/assets/$name" ] || exit 22
    emit < "$FIXTURES/assets/$name" ;;
  *) exit 22 ;;
esac
STUB

cat > "$STUB_BIN/systemctl" <<'STUB'
#!/usr/bin/env bash
# Records what it was asked to do, and stands in for the app on `start`: with
# FAKE_APP_HEALTHY=1 it stamps a heartbeat the way a real first sync round-trip
# would; with 0 it stays silent, which is precisely what a crash-looping or
# wedged terminal looks like to the watchdog.
args="$*"
echo "$args" >> "$SYSTEMCTL_LOG"
case "$args" in
  *start*)
    if [ "${FAKE_APP_HEALTHY:-1}" = 1 ]; then
      version="$(basename "$(readlink -f "$CLUBBAR_ROOT/current")")"
      cat > "$CLUBBAR_DATA_DIR/status.json" <<JSON
{
  "app_version": "$version",
  "last_sync_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "unsynced_transactions": 0,
  "written_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
JSON
    fi ;;
esac
exit 0
STUB

chmod +x "$STUB_BIN/curl" "$STUB_BIN/systemctl"
export PATH="$STUB_BIN:$PATH"

run_updater() {
  OUTPUT="$("$UPDATER" "$@" 2>&1)"
  STATUS=$?
}

installed() { basename "$(readlink -f "$CLUBBAR_ROOT/current")"; }

expect_installed() {
  local want="$1" got
  got="$(installed)"
  if [ "$got" = "$want" ]; then pass "$2"; else fail "$2 (installed $got, expected $want)"; fi
}

expect_status() {
  if [ "$STATUS" = "$1" ]; then pass "$2"; else fail "$2 (exit $STATUS, expected $1)"; fi
}

expect_output() {
  if printf '%s' "$OUTPUT" | grep -qF "$1"; then pass "$2"; else fail "$2 — output was: $OUTPUT"; fi
}

expect_no_service_restart() {
  if [ -s "$SYSTEMCTL_LOG" ]; then
    fail "$1 (the service was touched: $(tr '\n' ';' < "$SYSTEMCTL_LOG"))"
  else
    pass "$1"
  fi
}

# ---------------------------------------------------------------------------
# The five refusals — ADR-0054: all of them "no update", none of them an error
# ---------------------------------------------------------------------------

echo "== refusals =="

setup_case
rm -f "$FIXTURES/health.json"          # the club's internet was down at 04:00
run_updater
expect_status 0 "an unreachable backend is not an error"
expect_installed v1.0.6 "an unreachable backend changes nothing"
expect_no_service_restart "an unreachable backend does not touch the service"

setup_case
backend_reports dev                     # a club self-hosting from git
run_updater
expect_status 0 "a dev backend is not an error"
expect_output "not a release tag" "a dev backend is refused by name"
expect_installed v1.0.6 "a dev backend changes nothing"

setup_case
backend_reports dev-4f2a9c1
run_updater
expect_status 0 "a dev-<sha> backend is not an error"
expect_installed v1.0.6 "a dev-<sha> backend changes nothing"

setup_case
backend_reports v1.0.6                  # already there
run_updater
expect_status 0 "matching the backend is not an error"
expect_output "nothing to do" "an equal version says so"
expect_no_service_restart "an equal version does not touch the service"

setup_case
backend_reports v1.0.5                  # the backend was rolled back
publish_release v1.0.5
run_updater
expect_status 0 "an older backend is not an error"
expect_output "Never downgrading" "an older backend is refused, loudly"
expect_installed v1.0.6 "an older backend never moves the terminal backwards"

setup_case
write_status "$(date -u +%Y-%m-%dT%H:%M:%SZ)" 4   # four sales not yet uploaded
run_updater
expect_status 0 "unsynced sales are not an error"
expect_output "have not synced" "unsynced sales are named as the reason"
expect_installed v1.0.6 "unsynced sales stop the update"

setup_case
rm -f "$CLUBBAR_DATA_DIR/status.json"
run_updater
expect_status 0 "a missing status file is not an error"
expect_output "cannot tell whether sales are waiting" "a missing status file refuses rather than assumes zero"
expect_installed v1.0.6 "a missing status file stops the update"

setup_case
echo "v1.0.7" > "$CLUBBAR_ROOT/blocked"
run_updater
expect_status 0 "a blocked tag is not an error"
expect_output "is blocked" "a blocked tag is named"
expect_installed v1.0.6 "a blocked tag is never retried"

setup_case
python3 - "$CLUBBAR_DATA_DIR/config.json" <<'PY'
import json, sys
p = sys.argv[1]
d = json.load(open(p))
d['updatePin'] = 'v1.0.6'
json.dump(d, open(p, 'w'), indent=2)
PY
run_updater
expect_status 0 "a pinned terminal is not an error"
expect_output "pinned to v1.0.6" "a pin is named"
expect_installed v1.0.6 "a pin holds the terminal where the operator put it"

setup_case
python3 - "$CLUBBAR_DATA_DIR/config.json" <<'PY'
import json, sys
p = sys.argv[1]
d = json.load(open(p))
d['updateEnabled'] = False
json.dump(d, open(p, 'w'), indent=2)
PY
run_updater
expect_status 0 "opting out is not an error"
expect_output "switched off" "opting out is named"
expect_installed v1.0.6 "opting out stops the update"

setup_case
backend_reports v9.9.9                  # a tag the project never released
run_updater
expect_status 0 "a tag with no release is not an error"
expect_output "no release v9.9.9" "a missing release is named"
expect_installed v1.0.6 "a missing release changes nothing"

setup_case
unpublish_asset v1.0.7                  # released, but the arm64 build failed
run_updater
expect_status 0 "a release with no bundle for this architecture is not an error"
expect_installed v1.0.6 "a release with no matching asset changes nothing"

setup_case
unpublish_checksum v1.0.7
run_updater
expect_status 0 "a release with no checksum is not an error"
expect_output "refusing to install unverified" "an unverifiable bundle is refused"
expect_installed v1.0.6 "an unverifiable bundle is never installed"

setup_case
# A tarball that does not match its published checksum: a truncated download,
# or something worse. Either way it must not reach the disk.
arch="$(uname -m)"; case "$arch" in aarch64|arm64) arch=arm64 ;; x86_64|amd64) arch=x64 ;; esac
echo "corrupted" >> "$FIXTURES/assets/clubbar-terminal-linux-${arch}-v1.0.7.tar.gz"
run_updater
expect_status 0 "a corrupted download is not an error"
expect_output "checksum mismatch" "a corrupted download is named"
expect_installed v1.0.6 "a corrupted download is never installed"

# ---------------------------------------------------------------------------
# A terminal that was never converted to A/B slots
# ---------------------------------------------------------------------------

echo
echo "== an unconverted terminal =="

setup_case
rm "$CLUBBAR_ROOT/current"
run_updater
expect_status 2 "an unconverted terminal reports a setup problem, not a refusal"
expect_output "has not been converted" "an unconverted terminal says what is wrong"
expect_no_service_restart "an unconverted terminal is not force-installed over"

# ---------------------------------------------------------------------------
# The happy path
# ---------------------------------------------------------------------------

echo
echo "== a healthy update =="

setup_case
run_updater
expect_status 0 "a healthy update succeeds"
expect_installed v1.0.7 "the current symlink moves to the new version"
if [ "$(readlink -f "$CLUBBAR_ROOT/previous")" = "$CLUBBAR_ROOT/releases/v1.0.6" ]; then
  pass "previous points at the version that was replaced"
else
  fail "previous does not point at v1.0.6"
fi
if [ -f "$CLUBBAR_ROOT/releases/v1.0.7/marker" ]; then
  pass "the new bundle was extracted into its own slot"
else
  fail "the new bundle is missing from releases/v1.0.7"
fi
if ls "$CLUBBAR_DATA_DIR/update-backup"/*.db >/dev/null 2>&1; then
  pass "the database was backed up before the swap"
else
  fail "no database backup was taken"
fi
if grep -q 'stop' "$SYSTEMCTL_LOG" && grep -q 'start' "$SYSTEMCTL_LOG"; then
  pass "the app was stopped before the database was copied, and started after"
else
  fail "the service was not cycled: $(tr '\n' ';' < "$SYSTEMCTL_LOG")"
fi
if [ ! -f "$CLUBBAR_ROOT/blocked" ]; then
  pass "a healthy update blocks nothing"
else
  fail "a healthy update wrote a blocked list"
fi

# ---------------------------------------------------------------------------
# The rollback — the path nobody exercises by hand
# ---------------------------------------------------------------------------

echo
echo "== a version that does not come up =="

setup_case
export FAKE_APP_HEALTHY=0               # installed, restarted, never heartbeats
run_updater
expect_status 1 "a failed update reports failure"
expect_installed v1.0.6 "the terminal is back on the version that worked"
expect_output "rolling back" "the rollback is announced"

if [ "$(cat "$CLUBBAR_DATA_DIR/clubbar_terminal.db")" = "pretend-database" ]; then
  pass "the pre-update database was restored"
else
  fail "the database was not restored"
fi

if grep -qxF v1.0.7 "$CLUBBAR_ROOT/blocked"; then
  pass "the failed version is blocked"
else
  fail "the failed version was not added to the blocked list"
fi

if grep -qF '"blocked_version": "v1.0.7"' "$CLUBBAR_DATA_DIR/update-state.json"; then
  pass "the app is told what to report, so the club can find out"
else
  fail "update-state.json does not name the blocked version"
fi

if [ ! -d "$CLUBBAR_ROOT/releases/v1.0.7" ]; then
  pass "the failed slot is removed"
else
  fail "the failed slot is still on disk"
fi

# A blocked terminal now considers nothing at all until a newer release ships.
# That is intended — and the next run must confirm it rather than retry.
export FAKE_APP_HEALTHY=1
run_updater
expect_status 0 "the next run after a rollback is not an error"
expect_output "is blocked" "the next run refuses the version that failed"
expect_installed v1.0.6 "the next run leaves the working version alone"

# --clear-block is how an operator releases it, deliberately.
"$UPDATER" --clear-block >/dev/null 2>&1
run_updater
expect_status 0 "clearing the block lets the update run again"
expect_installed v1.0.7 "after --clear-block the update is installed"

echo
if [ "$FAILURES" -gt 0 ]; then
  echo "$FAILURES case(s) failed"
  exit 1
fi
echo "all cases passed"
