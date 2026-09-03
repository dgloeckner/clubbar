#!/usr/bin/env bash
#
# build-package.sh — Assemble the Club Bar shared hosting package
#
# Usage:
#   ./scripts/build-package.sh [VERSION]
#
# Arguments:
#   VERSION   Optional version string (default: "dev")
#
# Output:
#   dist/clubbar-VERSION.zip
#
# Local testing:
#   ./scripts/build-package.sh test
#   # Hand the tree to the uid the container serves it as. The package ships
#   # 0700 on storage/ and logs/ (#248) and the build ran as you, so without
#   # this the installer inside the container cannot write its own config:
#   ./scripts/package-permissions.sh container-user
#   docker compose -f docker-compose.yml -f docker-compose.ci.yml -f docker-compose.package.yml up -d database backend
#   # Reset DB for fresh install:
#   docker compose exec database mariadb -uroot -proot -e "DROP DATABASE IF EXISTS clubbar; CREATE DATABASE clubbar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON clubbar.* TO 'clubbar'@'%';"
#   # Run smoke tests:
#   cd e2etests && PACKAGE_TEST=1 npx playwright test --project=package-tests --workers=1
#   # Or open in browser:
#   open http://localhost:8080/install.php
#   # Clean up:
#   docker compose -f docker-compose.yml -f docker-compose.ci.yml -f docker-compose.package.yml down
#   ./scripts/package-permissions.sh builder-user
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

VERSION="${1:-dev}"
DIST_DIR="$PROJECT_ROOT/dist"
PKG_DIR="$DIST_DIR/package"

echo "=== Club Bar Package Builder ==="
echo "Version : $VERSION"
echo "Project : $PROJECT_ROOT"
echo ""

# ------------------------------------------------------------------
# 1. Clean and create dist/package/
# ------------------------------------------------------------------
echo "--- Cleaning dist/ directory..."
rm -rf "$DIST_DIR"
mkdir -p "$PKG_DIR"

# ------------------------------------------------------------------
# 2. Install backend production dependencies
# ------------------------------------------------------------------
echo "--- Installing backend production dependencies..."
composer install \
  --no-dev \
  --optimize-autoloader \
  --no-interaction \
  -d "$PROJECT_ROOT/backend"

# ------------------------------------------------------------------
# 3. Copy backend files to dist/package/api/
# ------------------------------------------------------------------
echo "--- Copying backend files..."
mkdir -p "$PKG_DIR/backend"

cp -R "$PROJECT_ROOT/backend/src"            "$PKG_DIR/backend/src"
mkdir -p "$PKG_DIR/backend/db"
cp    "$PROJECT_ROOT/backend/db/MigrationRunner.php" "$PKG_DIR/backend/db/"
cp -R "$PROJECT_ROOT/backend/db/migrations"  "$PKG_DIR/backend/db/migrations"
cp -R "$PROJECT_ROOT/backend/vendor"         "$PKG_DIR/backend/vendor"
cp    "$PROJECT_ROOT/backend/bootstrap.php"  "$PKG_DIR/backend/bootstrap.php"
# The commented `config.php` template, which `ConfigWriter` substitutes into
# (#710) — so the installer needs it on disk at runtime, long after the install
# itself.
#
# It goes into `backend/` rather than beside `install.php` (#751). Nothing ever
# requests it over HTTP, and everything in the document root is a URL: it used
# to sit next to `index.php`, where it outlived `install.php` (which the
# operator is told to delete) and stayed there for the life of the
# installation. `backend/` is denied wholesale by `.htaccess`, and it is where
# `config.php` itself lands on a host with no writable parent directory
# (ADR-0031 decision 2), so the template ends up beside the file it describes.
cp    "$PROJECT_ROOT/package/config.sample.php" "$PKG_DIR/backend/config.sample.php"
# The two scheduled entrypoints — the mail drain (ADR-0038 rule 3) and the
# backup (ADR-0049). Without these in the archive, the one thing that sends mail
# and the one thing that writes a backup do not reach the host at all.
#
# Only these two: the rest of bin/ is developer tooling, and every file under
# the document root is a file that becomes a URL the day .htaccess stops being
# honoured. Both refuse to run outside the CLI for exactly that reason — and it
# matters more for backup.php than for cron.php, because reaching it as a URL
# would not drain a queue, it would write a database dump.
mkdir -p "$PKG_DIR/backend/bin"
cp    "$PROJECT_ROOT/backend/bin/cron.php"   "$PKG_DIR/backend/bin/cron.php"
cp    "$PROJECT_ROOT/backend/bin/backup.php" "$PKG_DIR/backend/bin/backup.php"
if [ -f "$PROJECT_ROOT/backend/VERSION" ]; then
  cp    "$PROJECT_ROOT/backend/VERSION"      "$PKG_DIR/backend/VERSION"
fi

# ------------------------------------------------------------------
# 4. Create writable directories
# ------------------------------------------------------------------
echo "--- Creating writable directories..."
mkdir -p "$PKG_DIR/backend/storage"
mkdir -p "$PKG_DIR/backend/logs"
# Modes are set in one place, at the end of the build: scripts/package-permissions.sh
# harden. These two directories hold scanned SEPA mandates, sessions and logs,
# and used to ship 0777 along with the document root — a convenience for the
# Docker package test that survived into every production install
# (#248, ADR-0031 decision 4).

# Defense-in-depth: block direct HTTP access to storage and logs
# (primary protection is the RewriteRule ^backend/ in .htaccess)
printf 'Require all denied\n' > "$PKG_DIR/backend/storage/.htaccess"
printf 'Require all denied\n' > "$PKG_DIR/backend/logs/.htaccess"

# ------------------------------------------------------------------
# 5. Build admin frontend
# ------------------------------------------------------------------
if [ -f "$PROJECT_ROOT/admin-frontend/dist/index.html" ]; then
  echo "--- Admin frontend already built, skipping npm build..."
else
  echo "--- Building admin frontend..."
  cd "$PROJECT_ROOT/admin-frontend"
  npm ci
  npm run build
  cd "$PROJECT_ROOT"
fi

# ------------------------------------------------------------------
# 6. Copy built frontend to dist/package/assets
# ------------------------------------------------------------------
echo "--- Copying frontend assets..."
# Vite outputs index.html + assets/ into dist/. Copy contents directly
# to the package root so /assets/index-xxx.js resolves correctly.
# The SPA index.html is renamed to spa.html to avoid conflicting with
# the front controller index.php.
if [ -d "$PROJECT_ROOT/admin-frontend/dist" ]; then
  cp -R "$PROJECT_ROOT/admin-frontend/dist/assets" "$PKG_DIR/assets"
  cp "$PROJECT_ROOT/admin-frontend/dist/index.html" "$PKG_DIR/spa.html"
  # Copy any other top-level files (locales, icons, etc.)
  for f in "$PROJECT_ROOT/admin-frontend/dist/"*; do
    fname="$(basename "$f")"
    [ "$fname" = "assets" ] && continue
    [ "$fname" = "index.html" ] && continue
    cp -R "$f" "$PKG_DIR/$fname"
  done
elif [ -d "$PROJECT_ROOT/admin-frontend/build" ]; then
  cp -R "$PROJECT_ROOT/admin-frontend/build/assets" "$PKG_DIR/assets"
  cp "$PROJECT_ROOT/admin-frontend/build/index.html" "$PKG_DIR/spa.html"
  for f in "$PROJECT_ROOT/admin-frontend/build/"*; do
    fname="$(basename "$f")"
    [ "$fname" = "assets" ] && continue
    [ "$fname" = "index.html" ] && continue
    cp -R "$f" "$PKG_DIR/$fname"
  done
else
  echo "ERROR: Could not find admin frontend build output (expected dist/ or build/)"
  exit 1
fi

# ------------------------------------------------------------------
# 7. Write package metadata
# ------------------------------------------------------------------
echo "--- Writing package metadata..."
BUILD_DATE=$(date -u +%Y-%m-%dT%H:%M:%SZ)
GIT_SHA=$(git -C "$PROJECT_ROOT" rev-parse --short HEAD 2>/dev/null || echo "unknown")
cat > "$PKG_DIR/package.json" <<PKGJSON
{
  "name": "clubbar",
  "version": "$VERSION",
  "build_date": "$BUILD_DATE",
  "git_sha": "$GIT_SHA",
  "min_php": "8.3"
}
PKGJSON

# ------------------------------------------------------------------
# 8. Copy package files (index.php, install.php, .htaccess, etc.)
# ------------------------------------------------------------------
echo "--- Copying package files..."
# The public self-registration page (ADR-0052 decision 11, #781).
#
# In the dev/docker layout the document root *is* `backend/public`, so
# `/register` resolves without anybody arranging it — which is exactly why it
# was missed here. The shipped package has its own document root, this
# directory was never copied into it, and `/register` therefore fell through
# `.htaccess` to the front controller, which serves `spa.html` for anything
# that is not `/api/`. A member scanning the club's QR poster got the admin
# login form (#796).
#
# It goes to the package root, not inside `backend/`, which `.htaccess` denies
# wholesale. Three static files, no build step — see the header of
# `register/index.html`.
mkdir -p "$PKG_DIR/register"
cp -R "$PROJECT_ROOT/backend/public/register/." "$PKG_DIR/register/"

# Cache-busting for the two assets the page loads (#812).
#
# `register.css` and `register.js` are hand-written files with stable names —
# no build, no content hash — and the shipped `.htaccess` grants every stylesheet
# and script `Expires: access plus 1 year`, which is correct for the SPA's
# hashed `assets/` and catastrophic here. An upgrade rewrites `index.html`
# (HTML gets no expiry, so it revalidates) while every phone that has already
# visited keeps last year's stylesheet: the club's masthead and colophon arrive
# as markup no rule styles, flush to the edge of the screen, in the palette of
# whichever release that phone saw first. The member reports "no styling"; the
# club upgraded weeks ago; nothing on the server is wrong.
#
# A header change alone cannot fix that, because a cached response already
# carries its year — only a different URL does. So the reference gets the
# asset's own content hash, which changes exactly when the file does and never
# when it does not. `.htaccess` still carves these two out of the long expiry:
# if this stamp ever silently stops applying, the cost is one revalidation per
# load rather than a year of a broken page.
asset_hash() {
    if command -v sha1sum > /dev/null 2>&1; then
        sha1sum "$1" | cut -c1-8
    else
        shasum "$1" | cut -c1-8
    fi
}

stamp_register_asset() {
    local file="$1"
    local index="$PKG_DIR/register/index.html"
    local version

    # Loudly, not quietly: a rename or a rewritten tag would otherwise turn this
    # into a no-op that nobody notices until a club upgrades and its members do.
    if ! grep -q "\"\./$file\"" "$index"; then
        echo "ERROR: register/index.html no longer references \"./$file\" — the cache-busting stamp would do nothing" >&2
        exit 1
    fi

    version="$(asset_hash "$PKG_DIR/register/$file")"
    sed -i.bak "s|\"\./$file\"|\"./$file?v=$version\"|g" "$index"
    rm -f "$index.bak"
}

stamp_register_asset register.css
stamp_register_asset register.js

cp "$PROJECT_ROOT/package/index.php"         "$PKG_DIR/index.php"
cp "$PROJECT_ROOT/package/install.php"       "$PKG_DIR/install.php"
cp "$PROJECT_ROOT/package/install.js"        "$PKG_DIR/install.js"
cp "$PROJECT_ROOT/package/upgrade.php"       "$PKG_DIR/upgrade.php"
cp "$PROJECT_ROOT/package/.htaccess"         "$PKG_DIR/.htaccess"
cp "$PROJECT_ROOT/package/.user.ini"         "$PKG_DIR/.user.ini"
cp "$PROJECT_ROOT/package/README.txt"        "$PKG_DIR/README.txt"
cp "$PROJECT_ROOT/LICENSE"                   "$PKG_DIR/LICENSE"
cp "$PROJECT_ROOT/package/THIRD-PARTY-NOTICES.txt" "$PKG_DIR/THIRD-PARTY-NOTICES.txt"

# The offline tools, which the release was shipping without (#710).
#
# This is not a convenience. `docs/runbook-backup-recovery.md` §1 — the restore
# procedure a club follows on the worst day of its year — says to open the
# archive in `tools/backup-decryptor.html`, and until now that file was in the
# git repository and not in the release. A club that installed from a download
# had an encrypted archive and no way to open it, which makes the backup
# feature unrestorable for exactly the people it was built for.
#
# `keypair-generator.html` is the other half: `config.sample.php` tells the
# operator to generate backup recipient keys with it, and the installer's
# backup step links to it. Both pages are self-contained and run from file://,
# so they carry their own vendored libsodium.
cp -R "$PROJECT_ROOT/tools"                  "$PKG_DIR/tools"

# ------------------------------------------------------------------
# 9. Apply the modes the release carries, and prove it carries them
# ------------------------------------------------------------------
# `verify` is a gate rather than a report: a world-writable path in here ships
# to every install, and on mass hosting that is a directory any other customer
# — or anything already running as another user on the box — can drop an
# executable .php file into (#248, ADR-0031 decision 4).
echo "--- Applying package file modes..."
"$SCRIPT_DIR/package-permissions.sh" harden "$PKG_DIR"
"$SCRIPT_DIR/package-permissions.sh" verify "$PKG_DIR"

# ------------------------------------------------------------------
# 10. Create ZIP archive
# ------------------------------------------------------------------
ARCHIVE="clubbar-${VERSION}.zip"
echo "--- Creating archive: $ARCHIVE"
cd "$PKG_DIR"
zip -r "$DIST_DIR/$ARCHIVE" . -q

# ------------------------------------------------------------------
# 11. Summary
# ------------------------------------------------------------------
ARCHIVE_PATH="$DIST_DIR/$ARCHIVE"
ARCHIVE_SIZE=$(du -h "$ARCHIVE_PATH" | cut -f1)

echo ""
echo "=== Build complete ==="
echo "Archive : $ARCHIVE_PATH"
echo "Size    : $ARCHIVE_SIZE"
echo ""
