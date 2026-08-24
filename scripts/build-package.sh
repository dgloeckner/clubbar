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
cp "$PROJECT_ROOT/package/index.php"         "$PKG_DIR/index.php"
cp "$PROJECT_ROOT/package/install.php"       "$PKG_DIR/install.php"
cp "$PROJECT_ROOT/package/install.js"        "$PKG_DIR/install.js"
cp "$PROJECT_ROOT/package/upgrade.php"       "$PKG_DIR/upgrade.php"
cp "$PROJECT_ROOT/package/.htaccess"         "$PKG_DIR/.htaccess"
cp "$PROJECT_ROOT/package/.user.ini"         "$PKG_DIR/.user.ini"
cp "$PROJECT_ROOT/package/config.sample.php" "$PKG_DIR/config.sample.php"
cp "$PROJECT_ROOT/package/README.txt"        "$PKG_DIR/README.txt"
cp "$PROJECT_ROOT/LICENSE"                   "$PKG_DIR/LICENSE"
cp "$PROJECT_ROOT/package/THIRD-PARTY-NOTICES.txt" "$PKG_DIR/THIRD-PARTY-NOTICES.txt"

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
