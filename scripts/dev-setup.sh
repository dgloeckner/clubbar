#!/usr/bin/env bash
#
# dev-setup.sh — bring a fresh checkout to the point where the tests can run.
#
# Written for Claude Code cloud sessions, where every session is a fresh clone on
# a machine with no Docker daemon running, no vendor/, no node_modules and no
# Playwright browser matching the pinned @playwright/test. It is safe on a
# developer machine too: every step is idempotent and skipped when already done.
#
# Usage:
#   scripts/dev-setup.sh [--with-frontend] [--skip-e2e]
#
#   --with-frontend  Also build the admin frontend and serve it on :5173, which
#                    the admin-chromium and admin-mobile E2E projects need.
#                    Costs a few minutes.
#   --skip-e2e       Skip npm install and the Playwright browser downloads.
#
# Environment:
#   INSTALL_KEY   Install key for install.php (default: dev-install-key-x,
#                 matching docker-compose.yml)
#
# After it finishes:
#   cd e2etests && npm test -- --project=api-tests
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

INSTALL_KEY="${INSTALL_KEY:-dev-install-key-x}"
BACKEND_URL="http://localhost:8080"

WITH_FRONTEND=0
SKIP_E2E=0
for arg in "$@"; do
    case "$arg" in
        --with-frontend) WITH_FRONTEND=1 ;;
        --skip-e2e)      SKIP_E2E=1 ;;
        -h|--help)       sed -n '5,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "ERROR: unknown option '$arg'" >&2; exit 1 ;;
    esac
done

cd "$PROJECT_ROOT"

step() { echo ""; echo "=== $* ==="; }
note() { echo "    $*"; }

WARNINGS=()
warn() { WARNINGS+=("$1"); echo "    WARNING: $1" >&2; }

# ---------------------------------------------------------------------------
step "1/7 Docker daemon"
# ---------------------------------------------------------------------------
"$SCRIPT_DIR/ensure-docker.sh"
if ! docker info > /dev/null 2>&1; then
    echo "ERROR: no Docker daemon — see logs/ensure-docker.log" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
step "2/7 Backend PHP dependencies"
# ---------------------------------------------------------------------------
# /app is a bind mount, so without vendor/ every backend request — including the
# /api/health healthcheck the stack waits on — is a 500.
if [ -f "$PROJECT_ROOT/backend/vendor/autoload.php" ]; then
    note "backend/vendor already present — skipping composer install."
else
    note "Running composer install..."
    (cd "$PROJECT_ROOT/backend" && composer install --no-interaction)
fi

# The backend container runs as uid 1000 while a fresh clone is owned by whoever
# checked it out (root, in a cloud session). Without this, writes into the bind
# mount fail and mandate uploads 500 — the same reason CI has a "Prepare writable
# directories" step.
note "Making backend/logs and backend/storage writable by the container user..."
mkdir -p "$PROJECT_ROOT/backend/logs" "$PROJECT_ROOT/backend/storage/mandates"
# Directories only, deliberately not `chmod -R`: creating files needs the
# directory bit, and a recursive chmod would rewrite the mode of the tracked
# .gitkeep files and leave the working tree dirty.
find "$PROJECT_ROOT/backend/logs" "$PROJECT_ROOT/backend/storage" -type d -exec chmod 777 {} +

# ---------------------------------------------------------------------------
step "3/7 Compose stack"
# ---------------------------------------------------------------------------
"$SCRIPT_DIR/dev-stack.sh" up
"$SCRIPT_DIR/dev-stack.sh" wait

# ---------------------------------------------------------------------------
step "4/7 Database migrate + seed"
# ---------------------------------------------------------------------------
# install.php refuses while storage/.installed exists, and that marker is
# tracked in git — so a fresh clone always starts blocked. Clear it, run the
# actions, then put it back so the working tree stays clean and the security
# tests still see install.php as blocked (this mirrors what CI does).
if curl -sf -o /dev/null "$BACKEND_URL/api/health"; then
    rm -f "$PROJECT_ROOT/backend/storage/.installed"
    if curl -sf -o /dev/null -H "X-Install-Key: $INSTALL_KEY" "$BACKEND_URL/install.php?action=migrate"; then
        note "migrate: ok"
    else
        warn "migrate failed — see the backend logs"
    fi
    rm -f "$PROJECT_ROOT/backend/storage/.installed"
    if curl -sf -o /dev/null -H "X-Install-Key: $INSTALL_KEY" "$BACKEND_URL/install.php?action=seed"; then
        note "seed: ok"
    else
        warn "seed failed — see the backend logs"
    fi
    touch "$PROJECT_ROOT/backend/storage/.installed"
else
    warn "backend is not answering on $BACKEND_URL — skipped migrate/seed"
fi

# ---------------------------------------------------------------------------
step "5/7 E2E dependencies and Playwright browsers (chromium + webkit)"
# ---------------------------------------------------------------------------
if [ "$SKIP_E2E" -eq 1 ]; then
    note "Skipped (--skip-e2e)."
else
    if [ -d "$PROJECT_ROOT/e2etests/node_modules" ]; then
        note "e2etests/node_modules already present — skipping npm install."
    else
        note "Running npm install..."
        (cd "$PROJECT_ROOT/e2etests" && npm install --no-audit --no-fund)
    fi

    # The session image ships a pre-built Chromium, but @playwright/test resolves
    # to whatever the caret range allows, and a newer Playwright wants a newer
    # browser build than the image has. `playwright install` is the check as well
    # as the fix: it is a no-op when the required build is already there.
    note "Ensuring the Chromium build this Playwright expects is present..."
    (cd "$PROJECT_ROOT/e2etests" && npx playwright install chromium) \
        || warn "Chromium download failed — the api-tests and admin-chromium projects will not run"

    # WebKit is not optional either: `admin-mobile` uses devices['iPhone 14'] and
    # `terminal-touch` uses devices['iPad Pro 11'], and both of those default to
    # WebKit, not Chromium. Installing only Chromium leaves every test in those
    # projects failing at launch with "Executable doesn't exist at
    # .../webkit-XXXX/pw_run.sh" — 42 red tests that look like product bugs and
    # are not. CI installs the full browser set, so this closes a gap where a
    # local run could be green on a shard CI fails.
    note "Ensuring the WebKit build this Playwright expects is present..."
    if (cd "$PROJECT_ROOT/e2etests" && npx playwright install webkit); then
        # WebKit links against a long tail of system libraries (libwoff, libopus,
        # libharfbuzz-icu, libenchant, libsecret, libmanette, libgstreamer...) that
        # the download does not carry. Without them the binary is on disk and still
        # refuses to launch, which reads as the same failure as a missing download.
        if (cd "$PROJECT_ROOT/e2etests" && npx playwright install-deps webkit > /dev/null 2>&1); then
            note "WebKit system libraries: ok"
        else
            # Needs root and a reachable apt mirror; neither is guaranteed. Say so
            # here rather than letting it surface later as a launch failure.
            warn "WebKit system libraries are missing (needs root + apt) — admin-mobile and terminal-touch will not run"
        fi
    else
        warn "WebKit download failed — the admin-mobile and terminal-touch projects will not run"
    fi
fi

# ---------------------------------------------------------------------------
step "6/7 Admin frontend (:5173)"
# ---------------------------------------------------------------------------
# The admin-chromium and admin-mobile E2E projects drive http://localhost:5173.
# This builds and
# serves it the same way CI does — on the host, with `vite preview` — rather than
# through the compose `frontend` profile: `npm ci` crashes inside that image's
# build ("Exit handler never called!") and Docker still records the layer as
# successful, so the image ends up without tsc and the build fails at `npm run build`.
if [ "$WITH_FRONTEND" -eq 0 ]; then
    note "Skipped — pass --with-frontend if you need the admin UI tests."
elif curl -sf -o /dev/null "http://localhost:5173/"; then
    note "Something is already serving :5173 — leaving it alone."
else
    if [ ! -d "$PROJECT_ROOT/admin-frontend/node_modules" ]; then
        note "Installing admin frontend dependencies..."
        (cd "$PROJECT_ROOT/admin-frontend" && npm ci --no-audit --no-fund)
    fi
    if [ ! -d "$PROJECT_ROOT/admin-frontend/dist" ]; then
        note "Building the admin frontend..."
        (cd "$PROJECT_ROOT/admin-frontend" && npm run build)
    fi

    note "Serving on :5173 (vite preview)..."
    mkdir -p "$PROJECT_ROOT/logs"
    # Detach properly: a new session (setsid), stdin closed, and both streams to
    # the log. Without all three the server stays a child of this script and the
    # script blocks waiting for a process that is meant to outlive it.
    setsid nohup sh -c "cd '$PROJECT_ROOT/admin-frontend' && exec npx vite preview --port 5173 --host" \
        < /dev/null >> "$PROJECT_ROOT/logs/vite-preview.log" 2>&1 &
    vite_pid=$!
    disown 2>/dev/null || true
    echo "$vite_pid" > "$PROJECT_ROOT/logs/vite-preview.pid"

    waited=0
    until curl -sf -o /dev/null "http://localhost:5173/"; do
        if [ "$waited" -ge 60 ]; then
            warn "admin frontend did not come up on :5173 — see logs/vite-preview.log"
            break
        fi
        sleep 2
        waited=$((waited + 2))
    done
    # `|| true`: a false test is the last command of this branch, and set -e
    # would treat its exit status as a script failure.
    [ "$waited" -lt 60 ] && note "admin frontend ready after ${waited}s." || true
fi

# ---------------------------------------------------------------------------
step "7/7 Verify"
# ---------------------------------------------------------------------------
if curl -sf "$BACKEND_URL/api/health" > /dev/null 2>&1; then
    note "backend /api/health: ok"
else
    warn "backend /api/health is not responding"
fi

# bcmath: the IBAN checksum in Validator.php calls bcmod(), so the extension is
# not optional. The backend container has it; a host PHP built without it fails
# those tests with "Call to undefined function bcmod()".
if docker compose exec -T backend php -r 'exit(extension_loaded("bcmath") ? 0 : 1);' > /dev/null 2>&1; then
    note "bcmath in the backend container: ok"
else
    warn "the backend container has no bcmath — IBAN validation will fail"
fi

# Browsers are verified by actually launching them, not by looking for the
# directory: a WebKit build unpacks fine and still refuses to start when a system
# library is missing, and telling those two apart is the whole point of checking.
# chromium serves api-tests/admin-chromium; webkit serves admin-mobile
# (iPhone 14) and terminal-touch (iPad Pro 11).
if [ "$SKIP_E2E" -eq 0 ] && [ -d "$PROJECT_ROOT/e2etests/node_modules" ]; then
    for browser in chromium webkit; do
        if (cd "$PROJECT_ROOT/e2etests" && node -e "
            require('@playwright/test')['$browser'].launch()
                .then(b => b.close())
                .catch(e => { console.error(e.message); process.exit(1); });
        ") > /dev/null 2>&1; then
            note "$browser launches: ok"
        else
            warn "$browser cannot launch — run: (cd e2etests && npx playwright install --with-deps $browser)"
        fi
    done
fi

if command -v php > /dev/null 2>&1; then
    if php -r 'exit(extension_loaded("bcmath") ? 0 : 1);' > /dev/null 2>&1; then
        note "bcmath on the host: ok"
    else
        note "bcmath on the host: MISSING — run backend PHP tests in the container:"
        note "  docker compose exec -w /app backend ./vendor/bin/phpunit"
        note "(host runs also cannot resolve the 'database' hostname the feature tests use)"
    fi
fi

echo ""
if [ "${#WARNINGS[@]}" -eq 0 ]; then
    echo "=== Setup complete ==="
else
    echo "=== Setup finished with ${#WARNINGS[@]} warning(s) ==="
    for w in "${WARNINGS[@]}"; do echo "  - $w"; done
fi
echo ""
echo "Run the API suite:   cd e2etests && npx playwright test --project=api-tests"
echo "Run backend tests:   docker compose exec -w /app backend ./vendor/bin/phpunit"
if [ "$WITH_FRONTEND" -eq 1 ]; then
    echo "Admin UI tests:      cd e2etests && npx playwright test --project=admin-chromium"
    echo "Mobile UI tests:     cd e2etests && npx playwright test --project=admin-mobile"
    echo "Stop the frontend:   pkill -f 'vite preview'"
else
    echo "Admin UI tests also need: scripts/dev-setup.sh --with-frontend"
fi
