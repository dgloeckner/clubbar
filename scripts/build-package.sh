#!/usr/bin/env bash
#
# build-package.sh — Assemble the Ruderbar shared hosting package
#
# Usage:
#   ./scripts/build-package.sh [VERSION]
#
# Arguments:
#   VERSION   Optional version string (default: "dev")
#
# Output:
#   dist/ruderbar-VERSION.zip
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

VERSION="${1:-dev}"
DIST_DIR="$PROJECT_ROOT/dist"
PKG_DIR="$DIST_DIR/package"

echo "=== Ruderbar Package Builder ==="
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
mkdir -p "$PKG_DIR/api"

cp -R "$PROJECT_ROOT/backend/src"            "$PKG_DIR/api/src"
cp -R "$PROJECT_ROOT/backend/db"             "$PKG_DIR/api/db"
cp -R "$PROJECT_ROOT/backend/vendor"         "$PKG_DIR/api/vendor"
cp    "$PROJECT_ROOT/backend/bootstrap.php"  "$PKG_DIR/api/bootstrap.php"
cp    "$PROJECT_ROOT/backend/composer.json"   "$PKG_DIR/api/composer.json"

# ------------------------------------------------------------------
# 4. Create writable directories
# ------------------------------------------------------------------
echo "--- Creating writable directories..."
mkdir -p "$PKG_DIR/api/storage"
mkdir -p "$PKG_DIR/api/logs"

# ------------------------------------------------------------------
# 5. Build admin frontend
# ------------------------------------------------------------------
echo "--- Building admin frontend..."
cd "$PROJECT_ROOT/admin-frontend"
npm ci
npm run build
cd "$PROJECT_ROOT"

# ------------------------------------------------------------------
# 6. Copy built frontend to dist/package/assets
# ------------------------------------------------------------------
echo "--- Copying frontend assets..."
if [ -d "$PROJECT_ROOT/admin-frontend/dist" ]; then
  cp -R "$PROJECT_ROOT/admin-frontend/dist" "$PKG_DIR/assets"
elif [ -d "$PROJECT_ROOT/admin-frontend/build" ]; then
  cp -R "$PROJECT_ROOT/admin-frontend/build" "$PKG_DIR/assets"
else
  echo "ERROR: Could not find admin frontend build output (expected dist/ or build/)"
  exit 1
fi

# ------------------------------------------------------------------
# 7. Copy package files (index.php, install.php, .htaccess, etc.)
# ------------------------------------------------------------------
echo "--- Copying package files..."
cp "$PROJECT_ROOT/package/index.php"         "$PKG_DIR/index.php"
cp "$PROJECT_ROOT/package/install.php"       "$PKG_DIR/install.php"
cp "$PROJECT_ROOT/package/.htaccess"         "$PKG_DIR/.htaccess"
cp "$PROJECT_ROOT/package/config.sample.php" "$PKG_DIR/config.sample.php"
cp "$PROJECT_ROOT/package/README.txt"        "$PKG_DIR/README.txt"

# ------------------------------------------------------------------
# 8. Create ZIP archive
# ------------------------------------------------------------------
ARCHIVE="ruderbar-${VERSION}.zip"
echo "--- Creating archive: $ARCHIVE"
cd "$DIST_DIR"
zip -r "$ARCHIVE" package/ -q

# ------------------------------------------------------------------
# 9. Summary
# ------------------------------------------------------------------
ARCHIVE_PATH="$DIST_DIR/$ARCHIVE"
ARCHIVE_SIZE=$(du -h "$ARCHIVE_PATH" | cut -f1)

echo ""
echo "=== Build complete ==="
echo "Archive : $ARCHIVE_PATH"
echo "Size    : $ARCHIVE_SIZE"
echo ""
