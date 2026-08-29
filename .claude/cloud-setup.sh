#!/bin/bash
#
# Canonical copy of the Claude Code cloud environment setup script.
#
# The live copy is pasted into the environment dialog at claude.ai/code; this
# file is the reviewable one. Keep the two in sync — see
# docs/agents/cloud-environment.md, which also explains when the environment
# cache rebuilds.
#
# Contract this script has to honour:
#   - Always exit 0. A non-zero exit fails the whole session start, so every
#     step is best-effort and the verification is left to dev-setup.sh.
#   - Finish in roughly five minutes, or the cache does not build and every
#     session pays the cost again.
#   - Runs as root on Ubuntu 24.04, before Claude Code launches. $CLAUDE_PROJECT_DIR
#     is not guaranteed to be set this early, so the checkout is located rather
#     than assumed.
#
# Deliberately NOT here:
#   - Anything to work around Composer. Composer dists download fine through the
#     session proxy (issue #696 item 3 does not reproduce); a `vendor/` release
#     bundle would be permanent CI machinery for a phantom.
#   - `docker compose up`. The stack costs 30-90s and a lot of RAM that most
#     sessions never need; scripts/ensure-docker.sh starts only the daemon, and
#     scripts/dev-setup.sh brings the stack up when a session actually wants it.

set -uo pipefail

log() { echo "[cloud-setup] $*"; }

# ---------------------------------------------------------------------------
# PHP 8.3 with bcmath, from the Ubuntu archive
# ---------------------------------------------------------------------------
# The image ships PHP 8.4 from the ondrej/sury PPA, and `php8.4-bcmath` is only
# ever available from that same PPA — which the egress policy answers 403 for.
# So bcmath can never be added to the 8.4 that is already installed, and
# Validator.php's IBAN checksum calls bcmod(): without it the backend suite
# errors out (10 errors, all SEPA/Validator).
#
# PHP *8.3* is a different matter: php8.3-cli and php8.3-bcmath are both in
# archive.ubuntu.com, which is allowed. 8.3 is also what the project targets
# (backend/composer.json pins the platform to 8.3.30), so this is the right
# runtime rather than a workaround.
#
# The pin is what makes it resolvable. Without it every php8.3-* dependency
# resolves to the PPA's 8.3.30 and apt gives up with "held broken packages",
# because php8.3-common cannot be satisfied from two origins at once. Pinning
# the unreachable PPA to -1 removes it from consideration entirely; nothing is
# lost, since it cannot be fetched anyway. Installed 8.4 packages stay put —
# priority -1 blocks installing, not keeping.
cat > /etc/apt/preferences.d/99-clubbar-no-sury <<'PIN' || true
# The ondrej/sury PPA is 403 behind the session egress policy. Pinning it out
# lets php8.3-* resolve from archive.ubuntu.com instead of failing to resolve
# at all. See docs/agents/cloud-environment.md.
Package: *
Pin: origin ppa.launchpadcontent.net
Pin-Priority: -1
PIN

# Non-zero here is expected: apt still reports the unreachable PPA lists.
apt-get update -qq || true

# php `php` stays 8.4; this installs an 8.3 alongside it, invoked as `php8.3`.
apt-get install -y -qq --no-install-recommends \
    php8.3-cli php8.3-bcmath php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-mysql php8.3-sqlite3 php8.3-intl php8.3-zip || true

if php8.3 -m 2>/dev/null | grep -qx bcmath; then
    log "php8.3 with bcmath is available."
else
    log "WARNING: php8.3/bcmath not available; backend tests will error in SEPA paths."
fi

# pdo_sqlite is not optional for the *unit* suite: 29 tests build their fixture
# in `sqlite::memory:` precisely so they need no stack and no `database` host.
# Without the driver they error with "could not find driver" — 29 red entries in
# repositories and domain classes, which reads as a broken data layer rather
# than a missing package, and pushes the next agent off the fast path and back
# into the container. Same trap as bcmath, so it gets the same one-line guard.
if php8.3 -r 'exit(in_array("sqlite", PDO::getAvailableDrivers()) ? 0 : 1);' 2>/dev/null; then
    log "php8.3 with pdo_sqlite is available."
else
    log "WARNING: php8.3/pdo_sqlite not available; the in-memory unit tests will error with 'could not find driver'."
fi

# ---------------------------------------------------------------------------
# Backend dependencies
# ---------------------------------------------------------------------------
# Done here so it lands in the environment cache and a session starts with a
# populated vendor/. Composer dists work through the proxy, so this is fast
# (~3s) rather than the ten-minute source-clone fallback #696 reported.
repo=""
for candidate in "${CLAUDE_PROJECT_DIR:-}" /home/user/clubbar "$(dirname "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")")"; do
    if [ -n "$candidate" ] && [ -f "$candidate/backend/composer.json" ]; then
        repo="$candidate"
        break
    fi
done

if [ -n "$repo" ]; then
    log "Repository at $repo"
    ( cd "$repo/backend" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist ) || true

    # The backend container runs as uid 1000 and a fresh clone is owned by root;
    # without this, mandate uploads answer 500. CI has the same step.
    chmod 777 "$repo/backend/logs" "$repo/backend/storage" 2>/dev/null || true
else
    log "WARNING: could not locate the checkout; skipped composer install."
fi

log "Done."
exit 0
