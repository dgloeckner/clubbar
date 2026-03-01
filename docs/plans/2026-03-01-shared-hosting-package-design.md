# Shared Hosting Package Design

**Date:** 2026-03-01
**Status:** Draft
**Goal:** Ship backend + admin frontend as a single downloadable ZIP installable on shared hosting (cPanel/Plesk) without Docker, Composer, or Node.js.

---

## Target Environment

- **Shared hosting** (Hetzner Webhosting, ALL-INKL, IONOS, Strato, etc.)
- PHP 8.3+ with `pdo_mysql`, `json`, `mbstring`
- MySQL/MariaDB
- Apache with `mod_rewrite`
- No SSH, no Docker, no CLI tools required
- FTP/file manager upload

---

## Release Artifact

Single ZIP file attached to each GitHub Release: `ruderbar-v1.0.0.zip`

### Directory Structure

```
ruderbar-v1.0.0/
├── api/                      # Slim 4 backend
│   ├── src/                  # Application code
│   ├── db/                   # Migrations + MigrationRunner
│   ├── vendor/               # Composer deps (pre-installed, --no-dev)
│   ├── storage/              # Locks, temp files (writable)
│   └── logs/                 # App logs (writable)
├── assets/                   # Built admin SPA (from admin-frontend/dist/)
│   ├── index.html
│   └── assets/               # JS/CSS bundles
├── index.php                 # Front controller
├── install.php               # Web installer wizard
├── .htaccess                 # Apache rewrite rules
├── config.sample.php         # Config template
└── README.txt                # "Upload to public_html, visit /install.php"
```

- `vendor/` is pre-bundled — no Composer on server
- `assets/` is pre-built — no Node.js on server
- `storage/` and `logs/` are the only directories needing write permissions

---

## Front Controller & Routing

### `.htaccess`

```apache
RewriteEngine On

# If file exists (SPA assets, install.php), serve directly
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]

# API routes -> index.php
RewriteRule ^api/ index.php [L,QSA]

# Everything else -> SPA
RewriteRule ^ assets/index.html [L]
```

### `index.php`

```php
<?php
$uri = $_SERVER['REQUEST_URI'];

if (str_starts_with(parse_url($uri, PHP_URL_PATH), '/api/')) {
    // Bootstrap Slim app
    require __DIR__ . '/api/bootstrap.php';
} else {
    // Fallback (shouldn't reach here — .htaccess handles it)
    readfile(__DIR__ . '/assets/index.html');
}
```

Apache handles static files and SPA routing directly. PHP is only invoked for `/api/*` requests.

---

## Install Wizard (`install.php`)

### Flow

**Step 1 — Prerequisites Check** (automatic)
- PHP >= 8.3
- Required extensions: `pdo_mysql`, `json`, `mbstring`
- `storage/` and `logs/` writable
- Shows green/red checklist, blocks if anything fails

**Step 2 — Database Credentials** (form)
- Host, port, database name, username, password
- "Test Connection" button (AJAX call to verify)
- On success, writes `config.php`

**Step 3 — Run Migrations** (automatic)
- Uses existing `MigrationRunner`
- Shows progress per migration

**Step 4 — Create Admin User** (form)
- Email, password, confirm password
- Inserts into members table with admin role

**Step 5 — Done**
- "Installation complete! Go to admin panel"
- Locks itself (checks if DB has migrations applied)

### Security

- Locked after first install — re-visiting shows "Already installed"
- For updates: user uploads new files, visits `/install.php`, enters admin password, runs pending migrations only
- No `INSTALL_KEY` env var needed — admin password is the gate

---

## Config Management

### `config.sample.php`

```php
<?php
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'ruderbar',
        'user' => '',
        'pass' => '',
    ],
    'app' => [
        'env' => 'production',
        'debug' => false,
        'url' => 'https://example.com',
    ],
    'session' => [
        'max_age' => 7200,
        'regeneration_interval' => 900,
    ],
    'api_token' => [
        'ttl_days' => 90,
    ],
];
```

- `install.php` writes `config.php` (same format, filled values)
- `api/bootstrap.php` loads: `$config = require __DIR__ . '/../config.php';`
- `config.php` is gitignored (contains credentials)

### Dual Config Support

`AppConfig` is modified to accept a config array OR fall back to environment variables. This keeps both paths working:
- **Shared hosting**: reads `config.php`
- **Docker dev**: reads environment variables (unchanged)

No codebase forking needed.

---

## Update/Upgrade Flow

1. User downloads new release ZIP
2. Extracts over existing files via FTP (overwrites `api/`, `assets/`, `index.php`)
3. `config.php` is preserved (not in the ZIP)
4. Visits `/install.php` — detects existing installation
5. Enters admin password
6. Installer runs only pending migrations
7. Done

---

## Build Script

### `scripts/build-package.sh`

```bash
#!/bin/bash
set -e

VERSION=${1:-"dev"}
DIST="dist/package"

rm -rf "$DIST"
mkdir -p "$DIST"

# Backend (production deps only)
composer install --no-dev -d backend/
cp -r backend/src backend/db backend/vendor "$DIST/api/"
mkdir -p "$DIST/api/storage" "$DIST/api/logs"

# Admin frontend
cd admin-frontend && npm ci && npm run build && cd ..
cp -r admin-frontend/dist "$DIST/assets"

# Package files
cp package/index.php "$DIST/"
cp package/install.php "$DIST/"
cp package/.htaccess "$DIST/"
cp package/config.sample.php "$DIST/"
cp package/README.txt "$DIST/"

# ZIP
cd dist && zip -r "ruderbar-${VERSION}.zip" package/
```

### New Repo Directory

```
package/
├── index.php           # Front controller
├── install.php         # Web installer wizard
├── .htaccess           # Apache rewrites
├── config.sample.php   # Template
└── README.txt          # Instructions
```

---

## CI Pipeline

### New Job: `test-package`

Added to `.github/workflows/build.yaml` alongside existing jobs:

```
Existing (unchanged):
  ├── test-backend       (PHPUnit unit tests)
  ├── test-e2e           (Playwright against dev setup)
  └── build-terminal     (Flutter ARM64)

New:
  └── test-package
        ├── Build package into dist/package/
        ├── Start via docker-compose.package.yml override
        ├── curl install.php (DB creds, migrations, admin user)
        ├── Run E2E subset against single-origin package
        ├── ZIP artifact
        └── Attach to GitHub Release (on release event)
```

### Docker Compose Override

**`docker-compose.package.yml`:**

```yaml
services:
  database:
    # inherited as-is

  backend:
    volumes:
      - ./dist/package:/app
    environment:
      WEB_DOCUMENT_ROOT: /app
    ports:
      - "8080:80"
```

Reuses existing `webdevops/php-apache:8.3` image and `database` service. Only changes the volume mount and document root.

### Job Steps

1. Checkout code
2. Setup PHP 8.3 + Node 20
3. Run `scripts/build-package.sh`
4. `docker compose -f docker-compose.yml -f docker-compose.ci.yml -f docker-compose.package.yml up -d`
5. Wait for backend health
6. `curl` install.php (pass DB creds, create admin user)
7. Run Playwright E2E subset against `http://localhost:8080` (single origin)
8. Upload ZIP as build artifact
9. On release event: attach ZIP to GitHub Release

### E2E Subset

Focused tests proving the package integration works:
- Health endpoint responds
- Admin login works
- Create a member (frontend -> API -> DB -> appears in list)
- A couple of CRUD operations across modules

Not a full re-run of all tests — just enough to confirm frontend can reach backend through the front controller.

### Local Testing

Contributors can verify the package locally:

```bash
./scripts/build-package.sh
docker compose -f docker-compose.yml -f docker-compose.package.yml up -d
open http://localhost:8080/install.php
```

---

## Existing Code Changes Required

| Area | Change | Scope |
|------|--------|-------|
| `AppConfig` | Accept config array OR env vars | Small — add constructor overload |
| `backend/public/index.php` | Extract app setup into `bootstrap.php` | Refactor — move logic, keep entry point |
| `admin-frontend/vite.config.ts` | No changes needed | None — proxy is dev-only, built SPA uses relative `/api/*` |
| `.github/workflows/build.yaml` | Add `test-package` job | New job, existing jobs unchanged |

---

## Out of Scope (Future)

- **Auto-updater in admin panel** — download + extract from within the app
- **Docker-based deployment package** — `docker-compose.prod.yml` for VPS users
- **Nginx support** — would need equivalent of `.htaccess` rules
- **PHP built-in server support** — for quick local testing without Apache
