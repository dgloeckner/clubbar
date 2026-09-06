#!/usr/bin/env bash
#
# clubbar-update.sh — install exactly the version this terminal's backend
# reports, atomically, and roll it back if it does not come up healthy.
#
# The decision is recorded in ADR-0054 and the requirement in issue #318. The
# rule it implements has one sentence: **a terminal runs exactly the version its
# backend reports at /api/health**. Upgrading the backend is the single act that
# moves terminals — there is no release to bless, no channel to pick, and no
# per-release decision anywhere on this machine.
#
# Everything below is either that comparison or a refusal to act on it. Every
# refusal exits 0 and means "no update, try again tomorrow": a terminal that
# skips a night has lost nothing, while a terminal that updates when it should
# not have is a bar that cannot sell beer.
#
# Usage:
#   clubbar-update.sh              # the nightly run (what the timer calls)
#   clubbar-update.sh --check      # report what it would do, change nothing
#   clubbar-update.sh --clear-block  # forget a failed version, so it may retry
#   clubbar-update.sh --status     # what is installed, and what is blocked
#
# Environment (all optional, for testing and for odd installs):
#   CLUBBAR_ROOT      install root                (default /opt/clubbar-terminal)
#   CLUBBAR_DATA_DIR  app data directory          (default the Linux app-support path)
#   CLUBBAR_REPO      GitHub repo to fetch from   (default dgloeckner/clubbar)
#   CLUBBAR_UNIT      systemd user unit to cycle  (default clubbar-terminal.service)
#   CLUBBAR_HEARTBEAT_TIMEOUT  seconds to wait for a heartbeat (default 300)
#
# Exit codes:
#   0  nothing to do, a refusal, or an update that came up healthy
#   1  an update failed and was rolled back — the terminal is on its old version
#   2  the machine is not set up for unattended updates (see --status)

set -uo pipefail

ROOT="${CLUBBAR_ROOT:-/opt/clubbar-terminal}"
DATA_DIR="${CLUBBAR_DATA_DIR:-$HOME/.local/share/de.clubbar.clubbar_terminal}"
REPO="${CLUBBAR_REPO:-dgloeckner/clubbar}"
UNIT="${CLUBBAR_UNIT:-clubbar-terminal.service}"
HEARTBEAT_TIMEOUT="${CLUBBAR_HEARTBEAT_TIMEOUT:-300}"

CONFIG="$DATA_DIR/config.json"
STATUS_FILE="$DATA_DIR/status.json"
UPDATE_STATE="$DATA_DIR/update-state.json"
BACKUP_DIR="$DATA_DIR/update-backup"
DB_FILE="$DATA_DIR/clubbar_terminal.db"
RELEASES="$ROOT/releases"
CURRENT="$ROOT/current"
PREVIOUS="$ROOT/previous"
BLOCKED="$ROOT/blocked"

# How many pre-update database copies to keep. Three is two more than a rollback
# needs; the extras are what an operator restores from when a problem is only
# noticed on the third evening.
KEEP_BACKUPS=3

MODE=run
for arg in "$@"; do
  case "$arg" in
    --check) MODE=check ;;
    --status) MODE=status ;;
    --clear-block) MODE=clear-block ;;
    -h|--help) sed -n '2,33p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

log()  { printf '%s clubbar-update: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1"; }
warn() { printf '%s clubbar-update: WARNING: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" >&2; }
die()  { printf '%s clubbar-update: ERROR: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" >&2; exit "${2:-2}"; }

# ---------------------------------------------------------------------------
# Release tags
# ---------------------------------------------------------------------------
# The same three rules the backend applies in `App\Shared\Version\ReleaseVersion`
# — and they are asserted against the same table of cases in
# `test/updater-version.sh`, because two implementations of "which version is
# newer" that disagree is exactly how a terminal moves backwards.
#
# `dev` and `dev-<sha>` are what a club deployed from git reports forever. They
# are refused here, once, and that is the fail-closed reading ADR-0044 gives an
# unclassified route: an unknown version is not an infinite ceiling.

is_release_tag() {
  [[ "${1:-}" =~ ^v[0-9]{1,9}\.[0-9]{1,9}\.[0-9]{1,9}(-[0-9A-Za-z.-]{1,32})?$ ]]
}

# Print -1, 0 or 1 for $1 older than, equal to, or newer than $2.
# Numeric field by field: 'v1.0.10' sorts *before* 'v1.0.9' as a string, and
# never moving backwards is this script's whole job.
compare_tags() {
  local a="${1#v}" b="${2#v}"
  local a_pre="" b_pre=""
  case "$a" in *-*) a_pre="${a#*-}"; a="${a%%-*}" ;; esac
  case "$b" in *-*) b_pre="${b#*-}"; b="${b%%-*}" ;; esac

  local i left right
  local -a a_parts b_parts
  IFS=. read -r -a a_parts <<< "$a"
  IFS=. read -r -a b_parts <<< "$b"
  for i in 0 1 2; do
    left=$((10#${a_parts[i]:-0}))
    right=$((10#${b_parts[i]:-0}))
    if [ "$left" -lt "$right" ]; then echo -1; return; fi
    if [ "$left" -gt "$right" ]; then echo 1; return; fi
  done

  # A pre-release sorts before the final release of the same numbers.
  if [ "$a_pre" = "$b_pre" ]; then echo 0; return; fi
  if [ -z "$a_pre" ]; then echo 1; return; fi
  if [ -z "$b_pre" ]; then echo -1; return; fi
  if [[ "$a_pre" < "$b_pre" ]]; then echo -1; else echo 1; fi
}

# ---------------------------------------------------------------------------
# Reading the two JSON files this script depends on
# ---------------------------------------------------------------------------
# By hand rather than with jq: jq is not installed on a stock Raspberry Pi OS
# image, and an updater that cannot run until somebody apt-installs something is
# an updater that does not run. Both files are written by us, one flat key per
# line, so a per-key match is enough — and every value read here is validated
# before it is used in a path or a URL.

json_string() {
  local file="$1" key="$2"
  [ -f "$file" ] || return 0
  sed -n "s/.*\"$key\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" "$file" | head -1
}

json_number() {
  local file="$1" key="$2"
  [ -f "$file" ] || return 0
  sed -n "s/.*\"$key\"[[:space:]]*:[[:space:]]*\(-\{0,1\}[0-9][0-9]*\).*/\1/p" "$file" | head -1
}

json_bool() {
  local file="$1" key="$2"
  [ -f "$file" ] || return 0
  sed -n "s/.*\"$key\"[[:space:]]*:[[:space:]]*\(true\|false\).*/\1/p" "$file" | head -1
}

# ---------------------------------------------------------------------------
# What is installed
# ---------------------------------------------------------------------------
# The `current` symlink is the single source of truth. No VERSION file is baked
# into the bundle: a second copy can contradict the directory it sits in, and
# the contradiction would be silent — an updater confidently declining to update
# a terminal that is running something else.

installed_version() {
  [ -L "$CURRENT" ] || return 1
  basename "$(readlink -f "$CURRENT")"
}

blocked_tags() {
  [ -f "$BLOCKED" ] && cat "$BLOCKED" || true
}

is_blocked() {
  local tag="$1"
  blocked_tags | grep -qxF "$tag"
}

host_arch() {
  case "$(uname -m)" in
    aarch64|arm64) echo arm64 ;;
    x86_64|amd64)  echo x64 ;;
    *)             echo "$(uname -m)" ;;
  esac
}

# ---------------------------------------------------------------------------
# --status / --clear-block
# ---------------------------------------------------------------------------

if [ "$MODE" = status ]; then
  echo "install root:  $ROOT"
  echo "installed:     $(installed_version || echo '(no current symlink — not converted to A/B slots)')"
  echo "previous:      $([ -L "$PREVIOUS" ] && basename "$(readlink -f "$PREVIOUS")" || echo '(none)')"
  echo "blocked:       $(blocked_tags | tr '\n' ' ')"
  echo "architecture:  $(host_arch)"
  echo "config:        $CONFIG"
  echo "backend:       $(json_string "$CONFIG" apiUrl)"
  echo "pinned to:     $(json_string "$CONFIG" updatePin)"
  echo "updates:       $([ "$(json_bool "$CONFIG" updateEnabled)" = false ] && echo 'opted out' || echo 'enabled')"
  echo "heartbeat:     $(json_string "$STATUS_FILE" last_sync_at) ($(json_number "$STATUS_FILE" unsynced_transactions) unsynced)"
  exit 0
fi

if [ "$MODE" = clear-block ]; then
  # Recovering a blocked terminal is deliberately a human act. The updater will
  # never un-block a tag on its own: it blocked that version because installing
  # it produced a till that did not come back, and retrying that automatically
  # is the one thing worse than standing still.
  rm -f "$BLOCKED"
  printf '{\n  "blocked_version": ""\n}\n' > "$UPDATE_STATE"
  log "cleared the blocked list; the next run may install the backend's version again"
  exit 0
fi

# ---------------------------------------------------------------------------
# Preconditions
# ---------------------------------------------------------------------------

[ -f "$CONFIG" ] || die "no config.json at $CONFIG — is this a terminal?"

API_URL="$(json_string "$CONFIG" apiUrl)"
[ -n "$API_URL" ] || die "config.json has no apiUrl"
API_URL="${API_URL%/}"

# An existing Pi has a *flat* install: the bundle is $ROOT itself, and nothing
# on the box records which tag it is. So the conversion to A/B slots is a
# one-off hand migration (INSTALL.md §1) and this script refuses rather than
# guesses. Force-installing the backend's version over an unknown flat install
# is tempting and wrong — it would silently reinstall over a terminal somebody
# had deliberately held back.
INSTALLED="$(installed_version)" || die \
  "$CURRENT is not a symlink — this terminal has not been converted to A/B slots. See INSTALL.md §1, 'Converting a terminal that was installed flat'. Nothing was changed." 2

is_release_tag "$INSTALLED" || die \
  "the installed slot is named '$INSTALLED', which is not a release tag. Nothing was changed." 2

if [ "$(json_bool "$CONFIG" updateEnabled)" = false ]; then
  log "updates are switched off in config.json (updateEnabled: false); nothing to do"
  exit 0
fi

# ---------------------------------------------------------------------------
# 1. What does the backend say it is?
# ---------------------------------------------------------------------------

HEALTH="$(curl -fsS --max-time 20 "$API_URL/health" 2>/dev/null)" || {
  # The commonest refusal by far, and the least interesting: the club's
  # internet was down at 04:00.
  log "backend at $API_URL is unreachable; no update tonight"
  exit 0
}

TARGET="$(printf '%s' "$HEALTH" | sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)"

if ! is_release_tag "$TARGET"; then
  # `dev`, `dev-<sha>` or anything unparseable. A club self-hosting from git
  # hand-manages its backend and will hand-manage its terminals; this is the
  # fail-closed branch working, not a fault.
  log "backend reports version '${TARGET:-<none>}', which is not a release tag; no update, ever, on this backend"
  exit 0
fi

# ---------------------------------------------------------------------------
# 2. Is there anything to do?
# ---------------------------------------------------------------------------

ORDER="$(compare_tags "$TARGET" "$INSTALLED")"

if [ "$ORDER" = 0 ]; then
  log "installed $INSTALLED matches the backend; nothing to do"
  exit 0
fi

if [ "$ORDER" = -1 ]; then
  # The invariant is equality, and this violates it — but the updater is not the
  # thing that will fix it. Moving backwards means running a sqlite migration
  # backwards, and the pre-update backup that would make that safe is long gone.
  warn "backend is on $TARGET, older than the installed $INSTALLED. Never downgrading; leaving this terminal alone."
  exit 0
fi

PIN="$(json_string "$CONFIG" updatePin)"
if [ -n "$PIN" ] && [ "$PIN" != "$TARGET" ]; then
  log "pinned to $PIN in config.json; not installing the backend's $TARGET"
  exit 0
fi

if is_blocked "$TARGET"; then
  # Exact-match plus a blacklist means this terminal now considers nothing at
  # all until a newer release ships. That is intended, and it is why the app
  # reports the blocked tag in X-Terminal-Blocked-Version — the club finds out
  # on the Terminals page, because nothing here will say so again.
  log "$TARGET failed on this terminal before and is blocked; staying on $INSTALLED"
  exit 0
fi

# ---------------------------------------------------------------------------
# 3. Is it safe to touch this terminal right now?
# ---------------------------------------------------------------------------

UNSYNCED="$(json_number "$STATUS_FILE" unsynced_transactions)"
if [ -z "$UNSYNCED" ]; then
  # No heartbeat file at all: the app has never run since this was installed, or
  # it cannot write its data directory. Either way there is no way to know what
  # this terminal is holding, and "unknown" is not "none".
  log "no status file at $STATUS_FILE; cannot tell whether sales are waiting to sync. No update."
  exit 0
fi

if [ "$UNSYNCED" -gt 0 ]; then
  # Requirement 6. These are real purchases that exist nowhere else yet.
  log "$UNSYNCED transaction(s) have not synced; no update until they have"
  exit 0
fi

ARCH="$(host_arch)"
ASSET="clubbar-terminal-linux-${ARCH}-${TARGET}.tar.gz"

if [ "$MODE" = check ]; then
  log "would update $INSTALLED -> $TARGET using $ASSET"
  exit 0
fi

# ---------------------------------------------------------------------------
# 4. Fetch and verify
# ---------------------------------------------------------------------------

WORK="$(mktemp -d "${TMPDIR:-/tmp}/clubbar-update.XXXXXX")" || die "could not create a work directory"
cleanup() { rm -rf "$WORK"; }
trap cleanup EXIT

RELEASE_JSON="$WORK/release.json"
if ! curl -fsS --max-time 60 \
      -H 'Accept: application/vnd.github+json' \
      "https://api.github.com/repos/$REPO/releases/tags/$TARGET" -o "$RELEASE_JSON"; then
  # A tag the backend reports but the project never released is worth saying
  # loudly: it means a club is running a backend built outside the release
  # process, and no terminal anywhere will ever match it.
  warn "no release $TARGET in $REPO (or GitHub is unreachable); no update"
  exit 0
fi

# One asset per line, so the URL for a name can be picked without a JSON parser.
ASSET_URL="$(tr ',' '\n' < "$RELEASE_JSON" \
  | grep -F "browser_download_url" \
  | sed -n 's/.*"browser_download_url"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
  | grep -F "/$ASSET" | head -1)"
SHA_URL="$(tr ',' '\n' < "$RELEASE_JSON" \
  | grep -F "browser_download_url" \
  | sed -n 's/.*"browser_download_url"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
  | grep -F "/$ASSET.sha256" | head -1)"

if [ -z "$ASSET_URL" ]; then
  # Not an error: a release that carries no bundle for this architecture is one
  # this terminal simply cannot take. CI publishes both artifacts or neither
  # (the release-gate job), but a guarantee in CI is not a guarantee on a Pi
  # three months later.
  log "release $TARGET has no $ASSET; no update"
  exit 0
fi

if [ -z "$SHA_URL" ]; then
  # Refused rather than installed unverified. TLS already authenticates the
  # host; the checksum is what catches a truncated download, and a truncated
  # tarball extracts into a bundle that starts and then does not work.
  warn "release $TARGET publishes $ASSET but no $ASSET.sha256; refusing to install unverified"
  exit 0
fi

log "downloading $TARGET ($ASSET)"
curl -fsSL --max-time 900 "$ASSET_URL" -o "$WORK/$ASSET" || { warn "download of $ASSET failed"; exit 0; }
curl -fsSL --max-time 60 "$SHA_URL" -o "$WORK/$ASSET.sha256" || { warn "download of the checksum failed"; exit 0; }

if ! (cd "$WORK" && sha256sum -c "$ASSET.sha256" >/dev/null 2>&1); then
  warn "checksum mismatch for $ASSET; refusing to install"
  exit 0
fi
log "checksum verified"

# Extract into a staging directory first. A slot is only ever named after a tag
# once it is complete, so a power loss mid-extract leaves a `.incoming`
# directory the next run overwrites, never a half-populated `releases/$TARGET`
# that a later run would mistake for an installed version.
STAGE="$RELEASES/.incoming-$TARGET"
rm -rf "$STAGE"
mkdir -p "$STAGE" || die "cannot write $RELEASES — is $ROOT owned by $(id -un)? See INSTALL.md §1." 2
if ! tar -xzf "$WORK/$ASSET" -C "$STAGE"; then
  warn "could not extract $ASSET"
  rm -rf "$STAGE"
  exit 0
fi

if [ ! -x "$STAGE/clubbar_terminal" ]; then
  warn "the extracted bundle has no clubbar_terminal executable; refusing to install"
  rm -rf "$STAGE"
  exit 0
fi

rm -rf "${RELEASES:?}/$TARGET"
mv "$STAGE" "$RELEASES/$TARGET"

# ---------------------------------------------------------------------------
# 5. Stop, back up, swap, start
# ---------------------------------------------------------------------------
# The app is stopped *before* the database is copied, not after. ADR-0054's
# flow diagram has the backup before the swap, and this is that step done
# honestly: copying a sqlite file out from under a process that has it open can
# produce a copy that is torn between two transactions, and the one moment that
# copy matters is the rollback path, where restoring it is the only thing
# standing between a failed update and lost sales.

mkdir -p "$BACKUP_DIR"
BACKUP="$BACKUP_DIR/clubbar_terminal-$INSTALLED-$(date -u +%Y%m%dT%H%M%SZ).db"

log "stopping $UNIT"
systemctl --user stop "$UNIT" || warn "could not stop $UNIT; continuing"

if [ -f "$DB_FILE" ]; then
  cp -a "$DB_FILE" "$BACKUP" || die "could not back up $DB_FILE; nothing was changed" 2
  log "database backed up to $BACKUP"
else
  # A terminal that has never opened its database has nothing to lose, and a
  # rollback simply finds no backup to restore.
  BACKUP=""
  log "no database at $DB_FILE yet; nothing to back up"
fi

# `ln -sfn` writes a new symlink beside the old one and `mv -T` renames it over
# the top — one rename(2), so a reader sees the old target or the new one and
# never a missing `current`.
swap_current_to() {
  ln -sfn "$1" "$ROOT/.current.new" && mv -Tf "$ROOT/.current.new" "$CURRENT"
}

ln -sfn "$RELEASES/$INSTALLED" "$ROOT/.previous.new" && mv -Tf "$ROOT/.previous.new" "$PREVIOUS"
swap_current_to "$RELEASES/$TARGET" || die "could not swap the current symlink" 2
log "current -> $TARGET"

SWAP_EPOCH="$(date -u +%s)"
systemctl --user start "$UNIT" || warn "could not start $UNIT"

# ---------------------------------------------------------------------------
# 6. Watchdog
# ---------------------------------------------------------------------------
# The heartbeat is the *sole* health criterion, and it has to be. The app's unit
# ships `StartLimitIntervalSec=0` on purpose — a kiosk always comes back up — so
# a restart count can never trip, and an app crash-looping forever looks exactly
# like a healthy one to systemd. A heartbeat that moves covers the app that
# crash-looped and the app that came up wedged with the same test.

heartbeat_epoch() {
  local iso
  iso="$(json_string "$STATUS_FILE" last_sync_at)"
  [ -n "$iso" ] || return 1
  date -u -d "$iso" +%s 2>/dev/null
}

log "waiting up to ${HEARTBEAT_TIMEOUT}s for a sync round-trip on $TARGET"
HEALTHY=0
DEADLINE=$((SWAP_EPOCH + HEARTBEAT_TIMEOUT))
while [ "$(date -u +%s)" -lt "$DEADLINE" ]; do
  sleep 10
  beat="$(heartbeat_epoch)" || continue
  if [ "$beat" -ge "$SWAP_EPOCH" ]; then
    HEALTHY=1
    break
  fi
done

if [ "$HEALTHY" = 1 ]; then
  log "$TARGET is healthy; update complete"
  # Old slots are kept for exactly one generation: `previous` is the rollback
  # target and must survive, everything below it is disk nobody will read.
  for slot in "$RELEASES"/v*; do
    [ -d "$slot" ] || continue
    name="$(basename "$slot")"
    [ "$name" = "$TARGET" ] && continue
    [ "$name" = "$INSTALLED" ] && continue
    rm -rf "$slot"
  done
  # Trim the backups the same way, oldest first.
  ls -1t "$BACKUP_DIR"/*.db 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | while read -r old; do
    rm -f "$old"
  done
  exit 0
fi

# ---------------------------------------------------------------------------
# 7. Rollback
# ---------------------------------------------------------------------------
# Safe because this runs at night on an idle, synced terminal: a version that
# never got healthy booked nothing, so restoring the pre-update database loses
# no sale. That is the same fact the unsynced-transactions refusal established
# before anything was touched.

warn "$TARGET did not report a sync round-trip within ${HEARTBEAT_TIMEOUT}s; rolling back to $INSTALLED"

systemctl --user stop "$UNIT" || warn "could not stop $UNIT during rollback"
swap_current_to "$RELEASES/$INSTALLED" || warn "could not swap current back — $CURRENT may be wrong"

if [ -n "$BACKUP" ] && [ -f "$BACKUP" ]; then
  cp -a "$BACKUP" "$DB_FILE" && log "database restored from $BACKUP"
fi

# Never retried on this terminal. The club learns about it from the Terminals
# page, because the app reads this file at startup and puts the tag on the wire
# in X-Terminal-Blocked-Version — without which a blocked terminal silently
# stops updating and nobody finds out.
printf '%s\n' "$TARGET" >> "$BLOCKED"
printf '{\n  "blocked_version": "%s"\n}\n' "$TARGET" > "$UPDATE_STATE"
rm -rf "${RELEASES:?}/$TARGET"

systemctl --user start "$UNIT" || warn "could not restart $UNIT after rollback"

warn "rolled back to $INSTALLED and blocked $TARGET. This terminal will not update again until a newer release ships; see docs/runbook-terminal-pi.md."
exit 1
