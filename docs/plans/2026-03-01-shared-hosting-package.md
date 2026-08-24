# Shared Hosting Package Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ship backend + admin frontend as a single ZIP installable on shared hosting via FTP.

**Architecture:** Extract Slim 4 bootstrap into reusable function, create front controller that routes `/api/*` to Slim and serves SPA for everything else, add web installer wizard, build script, and CI job that tests the assembled package end-to-end.

**Tech Stack:** PHP 8.3, Slim 4, Vite/React (pre-built), Apache mod_rewrite, Playwright E2E

**Design Doc:** `docs/plans/2026-03-01-shared-hosting-package-design.md`

---

## Task 1: Extract Slim App Bootstrap

Currently `backend/public/index.php` does everything: loads env, creates PDO, wires DI, configures Slim, adds middleware, registers routes, and runs the app. We need to split "configure" from "run" so the package front controller can reuse configuration.

**Files:**
- Create: `backend/bootstrap.php`
- Modify: `backend/public/index.php`

**Step 1: Create `backend/bootstrap.php`**

This file returns a configured Slim app without calling `$app->run()`:

```php
<?php

declare(strict_types=1);

use App\Shared\Config\Env;
use App\Shared\Config\AppConfig;
use App\Shared\Logging\Logger;
use App\ServiceFactory;
use Slim\Factory\AppFactory;

// Load environment (if .env exists — Docker dev uses this)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    Env::load($envFile);
}

// Build core dependencies
$config = new AppConfig();
$logger = new Logger($config->logDir, $config->debug ? 'DEBUG' : 'INFO');

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST'), Env::get('DB_NAME')),
    Env::get('DB_USER'),
    Env::get('DB_PASS'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

// Wire the service factory (DI container)
$factory = new ServiceFactory($pdo, $config, $logger);

// Create Slim app with our container
AppFactory::setContainer($factory);
$app = AppFactory::create();

// Set base path if provided (package uses /api prefix)
if (isset($GLOBALS['__SLIM_BASE_PATH'])) {
    $app->setBasePath($GLOBALS['__SLIM_BASE_PATH']);
}

// Add routing middleware first
$app->addRoutingMiddleware();

// Global middleware (outer to inner execution order)
$app->add($factory->getErrorHandler());
$app->add($factory->getJsonBodyParser());
$app->add($factory->getCorsMiddleware());

// Register routes
$routes = require __DIR__ . '/src/routes.php';
$routes($app);

return $app;
```

**Step 2: Simplify `backend/public/index.php`**

Replace the entire file with:

```php
<?php

declare(strict_types=1);

$app = require __DIR__ . '/../bootstrap.php';
$app->run();
```

**Step 3: Verify existing E2E tests still pass**

Run from project root:
```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
cd e2etests && npm test -- tests/api/health.spec.ts --workers=4
```

Expected: All health tests pass (backend still works identically).

Then run a broader subset:
```bash
cd e2etests && npm test -- --workers=4
```

Expected: All tests pass — this is a pure refactor.

**Step 4: Commit**

```bash
git add backend/bootstrap.php backend/public/index.php
git commit -m "refactor: extract Slim bootstrap into reusable backend/bootstrap.php

Separates app configuration from app execution so the shared hosting
package front controller can reuse the bootstrap without duplicating it."
```

---

## Task 2: Package Front Controller, .htaccess, and Config Template

Create the `package/` directory with the files that will be included in the ZIP.

**Files:**
- Create: `package/.htaccess`
- Create: `package/index.php`
- Create: `package/config.sample.php`
- Create: `package/README.txt`

**Step 1: Create `package/.htaccess`**

```apache
RewriteEngine On
RewriteBase /

# If the file exists on disk, serve it directly (SPA assets, install.php)
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]

# API routes → front controller
RewriteRule ^api/ index.php [L,QSA]

# Everything else → SPA index.html
RewriteRule ^ assets/index.html [L]
```

**Step 2: Create `package/index.php`**

The front controller loads `config.php` into `$_ENV`, then delegates to the Slim bootstrap. The `Env` class reads `$_ENV` as fallback, so no changes to backend code needed.

```php
<?php

declare(strict_types=1);

// --- Load config into environment ---
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    // Not installed yet — redirect to installer
    header('Location: /install.php');
    exit;
}

$config = require $configFile;

// Map config array to environment variables (Env class reads $_ENV)
$_ENV['DB_HOST'] = $config['db']['host'];
$_ENV['DB_PORT'] = (string) ($config['db']['port'] ?? 3306);
$_ENV['DB_NAME'] = $config['db']['name'];
$_ENV['DB_USER'] = $config['db']['user'];
$_ENV['DB_PASS'] = $config['db']['pass'];
$_ENV['APP_ENV'] = $config['app']['env'] ?? 'production';
$_ENV['APP_DEBUG'] = ($config['app']['debug'] ?? false) ? 'true' : 'false';
$_ENV['APP_URL'] = $config['app']['url'] ?? '';
$_ENV['SESSION_MAX_AGE'] = (string) ($config['session']['max_age'] ?? 7200);
$_ENV['SESSION_REGEN_INTERVAL'] = (string) ($config['session']['regeneration_interval'] ?? 900);
$_ENV['API_TOKEN_TTL_DAYS'] = (string) ($config['api_token']['ttl_days'] ?? 90);

// --- Route request ---
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/api/')) {
    // Slim app handles API requests
    // Set base path so Slim routes match without /api prefix
    // Routes are defined as /api/... so no base path change needed
    $app = require __DIR__ . '/api/bootstrap.php';
    $app->run();
} else {
    // Should not reach here — .htaccess serves SPA directly
    // Fallback for servers where .htaccess is ignored
    $spaIndex = __DIR__ . '/assets/index.html';
    if (file_exists($spaIndex)) {
        readfile($spaIndex);
    } else {
        http_response_code(404);
        echo 'Admin panel not found. Check that assets/ directory exists.';
    }
}
```

**Step 3: Create `package/config.sample.php`**

```php
<?php

/**
 * Club Bar Configuration
 *
 * Copy this file to config.php and fill in your database credentials.
 * Or use the web installer: visit /install.php in your browser.
 */
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'clubbar',
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

**Step 4: Create `package/README.txt`**

```
Club Bar - Member-Managed Bar/Club POS System
==============================================

Installation:
1. Upload all files to your web hosting document root (e.g. public_html/)
2. Make sure storage/ and logs/ directories inside api/ are writable
3. Open your domain in a browser — you will be redirected to /install.php
4. Follow the installation wizard

Requirements:
- PHP 8.3 or higher
- MySQL 5.7+ or MariaDB 10.5+
- Apache with mod_rewrite enabled
- PHP extensions: pdo_mysql, json, mbstring

Updating:
1. Download the new release ZIP
2. Upload and overwrite all files (config.php is preserved)
3. Visit /install.php — enter your admin password to run pending migrations

More info: https://github.com/[org]/clubbar
```

**Step 5: Commit**

```bash
git add package/
git commit -m "feat: add shared hosting package files (front controller, .htaccess, config template)

Adds package/ directory with:
- index.php: front controller routing /api/* to Slim and SPA fallback
- .htaccess: Apache rewrite rules for single-origin deployment
- config.sample.php: template for database and app configuration
- README.txt: installation instructions for shared hosting"
```

---

## Task 3: Install Wizard

Self-contained PHP file with embedded HTML. Manages steps via query param `?step=N`. No external dependencies — works before Composer vendor/ is loaded.

**Files:**
- Create: `package/install.php`

**Step 1: Create `package/install.php`**

This is a longer file. Key sections:

```php
<?php

declare(strict_types=1);

/**
 * Club Bar Installation Wizard
 *
 * Steps:
 * 1. Prerequisites check (PHP version, extensions, writable dirs)
 * 2. Database credentials (form + AJAX test connection)
 * 3. Run migrations
 * 4. Create admin user
 * 5. Done (lock installer)
 */

// --- Already installed? ---
$configFile = __DIR__ . '/config.php';
$isInstalled = file_exists($configFile);

// If installed and no update param, show "already installed" page
if ($isInstalled && !isset($_GET['update'])) {
    showAlreadyInstalled();
    exit;
}

$step = $_GET['step'] ?? ($_POST['step'] ?? '1');
$error = null;

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case '2': // Test DB + write config
            $dbHost = trim($_POST['db_host'] ?? 'localhost');
            $dbPort = (int) ($_POST['db_port'] ?? 3306);
            $dbName = trim($_POST['db_name'] ?? '');
            $dbUser = trim($_POST['db_user'] ?? '');
            $dbPass = $_POST['db_pass'] ?? '';

            // Test connection
            try {
                $testPdo = new PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName),
                    $dbUser,
                    $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                // Connection works — write config
                $configContent = "<?php\n\nreturn " . var_export([
                    'db' => [
                        'host' => $dbHost,
                        'port' => $dbPort,
                        'name' => $dbName,
                        'user' => $dbUser,
                        'pass' => $dbPass,
                    ],
                    'app' => [
                        'env' => 'production',
                        'debug' => false,
                        'url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                    ],
                    'session' => [
                        'max_age' => 7200,
                        'regeneration_interval' => 900,
                    ],
                    'api_token' => [
                        'ttl_days' => 90,
                    ],
                ], true) . ";\n";

                file_put_contents($configFile, $configContent);
                header('Location: ?step=3');
                exit;
            } catch (\PDOException $e) {
                $error = 'Database connection failed: ' . $e->getMessage();
                $step = '2';
            }
            break;

        case '3': // Run migrations
            require __DIR__ . '/api/vendor/autoload.php';
            $config = require $configFile;
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $config['db']['host'], $config['db']['port'], $config['db']['name']),
                $config['db']['user'],
                $config['db']['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $runner = new \App\Db\MigrationRunner($pdo);
            $result = $runner->migrate(__DIR__ . '/api/db/migrations', 'installer');

            $failed = array_filter($result, fn($r) => ($r['status'] ?? '') === 'FAIL');
            if ($failed) {
                $error = 'Migration failed: ' . ($failed[array_key_first($failed)]['message'] ?? 'unknown error');
            } else {
                header('Location: ?step=4');
                exit;
            }
            break;

        case '4': // Create admin user
            $email = trim($_POST['admin_email'] ?? '');
            $password = $_POST['admin_password'] ?? '';
            $passwordConfirm = $_POST['admin_password_confirm'] ?? '';

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
                break;
            }
            if (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
                break;
            }
            if ($password !== $passwordConfirm) {
                $error = 'Passwords do not match.';
                break;
            }

            $config = require $configFile;
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    $config['db']['host'], $config['db']['port'], $config['db']['name']),
                $config['db']['user'],
                $config['db']['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );

            $id = bin2hex(random_bytes(16));
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $pdo->prepare('INSERT INTO admin_users (id, email, password_hash, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())');
            $stmt->execute([$id, $email, $hashedPassword]);

            header('Location: ?step=5');
            exit;
    }
}

// --- Handle AJAX DB test ---
if (isset($_GET['action']) && $_GET['action'] === 'test_db') {
    header('Content-Type: application/json');
    try {
        $testPdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $_GET['host'] ?? 'localhost', (int) ($_GET['port'] ?? 3306), $_GET['name'] ?? ''),
            $_GET['user'] ?? '',
            $_GET['pass'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo json_encode(['success' => true]);
    } catch (\PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- Render page ---
renderPage($step, $error, $isInstalled);

// === Functions ===

function checkPrerequisites(): array
{
    $checks = [];

    // PHP version
    $checks[] = [
        'name' => 'PHP >= 8.3',
        'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
        'value' => PHP_VERSION,
    ];

    // Extensions
    foreach (['pdo_mysql', 'json', 'mbstring'] as $ext) {
        $checks[] = [
            'name' => "Extension: {$ext}",
            'ok' => extension_loaded($ext),
            'value' => extension_loaded($ext) ? 'loaded' : 'missing',
        ];
    }

    // Writable directories
    $apiDir = __DIR__ . '/api';
    foreach (['storage', 'logs'] as $dir) {
        $path = "{$apiDir}/{$dir}";
        $writable = is_dir($path) && is_writable($path);
        $checks[] = [
            'name' => "Writable: api/{$dir}/",
            'ok' => $writable,
            'value' => $writable ? 'writable' : (is_dir($path) ? 'not writable' : 'missing'),
        ];
    }

    // mod_rewrite (best-effort check)
    $checks[] = [
        'name' => 'Apache mod_rewrite',
        'ok' => function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules()) : true,
        'value' => function_exists('apache_get_modules')
            ? (in_array('mod_rewrite', apache_get_modules()) ? 'enabled' : 'disabled')
            : 'cannot detect (non-Apache or CGI mode — likely OK)',
    ];

    return $checks;
}

function showAlreadyInstalled(): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Club Bar - Already Installed</title>
        <style><?php echo getStyles(); ?></style>
    </head>
    <body>
        <div class="container">
            <h1>Club Bar</h1>
            <div class="card">
                <h2>Already Installed</h2>
                <p>Club Bar is already installed. <a href="/">Go to admin panel</a></p>
                <hr>
                <p><small>Need to run database migrations after an update? <a href="?update=1">Run updater</a></small></p>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function renderPage(string $step, ?string $error, bool $isUpdate): void
{
    $title = $isUpdate ? 'Club Bar Update' : 'Club Bar Installation';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $title; ?></title>
        <style><?php echo getStyles(); ?></style>
    </head>
    <body>
        <div class="container">
            <h1><?php echo $title; ?></h1>
            <div class="steps">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="step <?php echo $i == $step ? 'active' : ($i < $step ? 'done' : ''); ?>">
                        <?php echo $i; ?>
                    </span>
                <?php endfor; ?>
            </div>

            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card">
            <?php
            switch ($step) {
                case '1':
                    renderStep1();
                    break;
                case '2':
                    renderStep2();
                    break;
                case '3':
                    renderStep3();
                    break;
                case '4':
                    renderStep4($isUpdate);
                    break;
                case '5':
                    renderStep5();
                    break;
            }
            ?>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function renderStep1(): void
{
    $checks = checkPrerequisites();
    $allOk = !array_filter($checks, fn($c) => !$c['ok']);
    ?>
    <h2>Step 1: Prerequisites</h2>
    <table>
        <?php foreach ($checks as $check): ?>
            <tr>
                <td><?php echo $check['ok'] ? '&#10003;' : '&#10007;'; ?></td>
                <td><?php echo htmlspecialchars($check['name']); ?></td>
                <td><?php echo htmlspecialchars($check['value']); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($allOk): ?>
        <a href="?step=2" class="btn">Continue</a>
    <?php else: ?>
        <p class="error">Please fix the issues above before continuing.</p>
        <a href="?step=1" class="btn btn-secondary">Re-check</a>
    <?php endif; ?>
    <?php
}

function renderStep2(): void
{
    ?>
    <h2>Step 2: Database</h2>
    <form method="post" action="?step=2">
        <input type="hidden" name="step" value="2">
        <label>Host <input type="text" name="db_host" value="localhost" required></label>
        <label>Port <input type="number" name="db_port" value="3306" required></label>
        <label>Database Name <input type="text" name="db_name" required></label>
        <label>Username <input type="text" name="db_user" required></label>
        <label>Password <input type="password" name="db_pass"></label>
        <button type="button" onclick="testConnection()" class="btn btn-secondary" id="testBtn">Test Connection</button>
        <span id="testResult"></span>
        <br>
        <button type="submit" class="btn">Save &amp; Continue</button>
    </form>
    <script>
    function testConnection() {
        const btn = document.getElementById('testBtn');
        const result = document.getElementById('testResult');
        btn.disabled = true;
        result.textContent = 'Testing...';
        const form = document.querySelector('form');
        const params = new URLSearchParams({
            action: 'test_db',
            host: form.db_host.value,
            port: form.db_port.value,
            name: form.db_name.value,
            user: form.db_user.value,
            pass: form.db_pass.value,
        });
        fetch('?'+params)
            .then(r => r.json())
            .then(data => {
                result.textContent = data.success ? 'Connection OK!' : 'Failed: ' + data.error;
                result.style.color = data.success ? 'green' : 'red';
                btn.disabled = false;
            })
            .catch(() => { result.textContent = 'Request failed'; btn.disabled = false; });
    }
    </script>
    <?php
}

function renderStep3(): void
{
    ?>
    <h2>Step 3: Database Setup</h2>
    <p>Click the button below to create database tables.</p>
    <form method="post" action="?step=3">
        <input type="hidden" name="step" value="3">
        <button type="submit" class="btn">Run Migrations</button>
    </form>
    <?php
}

function renderStep4(bool $isUpdate): void
{
    if ($isUpdate) {
        ?>
        <h2>Update Complete</h2>
        <p>Database migrations have been applied successfully.</p>
        <a href="/" class="btn">Go to Admin Panel</a>
        <?php
        return;
    }
    ?>
    <h2>Step 4: Admin Account</h2>
    <form method="post" action="?step=4">
        <input type="hidden" name="step" value="4">
        <label>Email <input type="email" name="admin_email" required></label>
        <label>Password <input type="password" name="admin_password" minlength="8" required></label>
        <label>Confirm Password <input type="password" name="admin_password_confirm" minlength="8" required></label>
        <button type="submit" class="btn">Create Admin &amp; Finish</button>
    </form>
    <?php
}

function renderStep5(): void
{
    ?>
    <h2>Installation Complete!</h2>
    <p>Club Bar has been installed successfully.</p>
    <a href="/" class="btn">Go to Admin Panel</a>
    <?php
}

function getStyles(): string
{
    return <<<'CSS'
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; color: #333; }
    .container { max-width: 600px; margin: 40px auto; padding: 0 20px; }
    h1 { text-align: center; margin-bottom: 20px; color: #1a56db; }
    h2 { margin-bottom: 16px; }
    .card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
    .steps { display: flex; justify-content: center; gap: 8px; margin-bottom: 24px; }
    .step { width: 32px; height: 32px; border-radius: 50%; background: #ddd; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: bold; }
    .step.active { background: #1a56db; color: #fff; }
    .step.done { background: #16a34a; color: #fff; }
    label { display: block; margin-bottom: 12px; font-weight: 500; }
    input[type="text"], input[type="password"], input[type="email"], input[type="number"] { display: block; width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; margin-top: 4px; font-size: 14px; }
    .btn { display: inline-block; padding: 10px 20px; background: #1a56db; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; text-decoration: none; margin-top: 12px; }
    .btn:hover { background: #1e40af; }
    .btn-secondary { background: #6b7280; }
    .btn-secondary:hover { background: #4b5563; }
    .error { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px; border-radius: 4px; margin-bottom: 16px; }
    table { width: 100%; margin-bottom: 16px; }
    td { padding: 6px 8px; border-bottom: 1px solid #eee; }
    hr { margin: 16px 0; border: none; border-top: 1px solid #eee; }
    a { color: #1a56db; }
    CSS;
}
```

**Step 2: Verify install.php is syntactically valid**

```bash
php -l package/install.php
```

Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add package/install.php
git commit -m "feat: add web installer wizard for shared hosting deployment

5-step wizard: prerequisites check, database credentials with AJAX test,
migrations, admin user creation, completion. Self-locking after install.
Supports update mode (?update=1) for running pending migrations."
```

---

## Task 4: Build Script

**Files:**
- Create: `scripts/build-package.sh`

**Step 1: Create `scripts/build-package.sh`**

```bash
#!/bin/bash
set -euo pipefail

VERSION=${1:-"dev"}
DIST="dist/package"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo "=== Building Club Bar package v${VERSION} ==="

# Clean
rm -rf "$PROJECT_ROOT/dist"
mkdir -p "$PROJECT_ROOT/$DIST"

# --- Backend ---
echo "Installing backend dependencies (production)..."
composer install --no-dev --optimize-autoloader --no-interaction -d "$PROJECT_ROOT/backend"

echo "Copying backend files..."
mkdir -p "$PROJECT_ROOT/$DIST/api"
cp -r "$PROJECT_ROOT/backend/src" "$PROJECT_ROOT/$DIST/api/"
cp -r "$PROJECT_ROOT/backend/db" "$PROJECT_ROOT/$DIST/api/"
cp -r "$PROJECT_ROOT/backend/vendor" "$PROJECT_ROOT/$DIST/api/"
cp "$PROJECT_ROOT/backend/bootstrap.php" "$PROJECT_ROOT/$DIST/api/"
cp "$PROJECT_ROOT/backend/composer.json" "$PROJECT_ROOT/$DIST/api/"
mkdir -p "$PROJECT_ROOT/$DIST/api/storage"
mkdir -p "$PROJECT_ROOT/$DIST/api/logs"

# --- Admin Frontend ---
echo "Building admin frontend..."
cd "$PROJECT_ROOT/admin-frontend"
npm ci
npm run build
cd "$PROJECT_ROOT"

echo "Copying admin frontend build..."
cp -r "$PROJECT_ROOT/admin-frontend/dist" "$PROJECT_ROOT/$DIST/assets"

# --- Package files ---
echo "Copying package files..."
cp "$PROJECT_ROOT/package/index.php" "$PROJECT_ROOT/$DIST/"
cp "$PROJECT_ROOT/package/install.php" "$PROJECT_ROOT/$DIST/"
cp "$PROJECT_ROOT/package/.htaccess" "$PROJECT_ROOT/$DIST/"
cp "$PROJECT_ROOT/package/config.sample.php" "$PROJECT_ROOT/$DIST/"
cp "$PROJECT_ROOT/package/README.txt" "$PROJECT_ROOT/$DIST/"

# --- ZIP ---
echo "Creating ZIP archive..."
cd "$PROJECT_ROOT/dist"
zip -r "clubbar-${VERSION}.zip" package/ -q

echo ""
echo "=== Build complete ==="
echo "Archive: dist/clubbar-${VERSION}.zip"
echo "Size: $(du -h "clubbar-${VERSION}.zip" | cut -f1)"
```

**Step 2: Make executable and add dist/ to .gitignore**

```bash
chmod +x scripts/build-package.sh
```

Check `.gitignore` for `dist/` — add if missing:
```bash
echo "dist/" >> .gitignore
```

**Step 3: Test the build script runs (dry run)**

```bash
./scripts/build-package.sh test
```

Expected: Script completes, `dist/clubbar-test.zip` created.

**Step 4: Commit**

```bash
git add scripts/build-package.sh .gitignore
git commit -m "feat: add build script for shared hosting package

scripts/build-package.sh assembles backend (with vendor/), built admin
frontend, and package files into a single ZIP for shared hosting upload."
```

---

## Task 5: Docker Compose Package Override

**Files:**
- Create: `docker-compose.package.yml`

**Step 1: Create `docker-compose.package.yml`**

```yaml
# Override for testing the assembled shared hosting package.
# Usage: docker compose -f docker-compose.yml -f docker-compose.package.yml up -d
#
# Requires: run scripts/build-package.sh first to create dist/package/
services:
  backend:
    volumes:
      - ./dist/package:/app
    environment:
      WEB_DOCUMENT_ROOT: /app
```

**Step 2: Test the package locally**

```bash
# Build the package
./scripts/build-package.sh test

# Start with package override
docker compose -f docker-compose.yml -f docker-compose.package.yml up -d

# Wait for backend
sleep 3
curl -s http://localhost:8080/install.php?step=1
```

Expected: HTML response with the prerequisites check page.

**Step 3: Test the full install flow via curl**

```bash
# Step 2: Set DB credentials (POST)
curl -X POST 'http://localhost:8080/install.php?step=2' \
  -d 'step=2&db_host=database&db_port=3306&db_name=clubbar&db_user=clubbar&db_pass=clubbar' \
  -v 2>&1 | grep "Location"
# Expected: Location: ?step=3

# Step 3: Run migrations (POST)
curl -X POST 'http://localhost:8080/install.php?step=3' \
  -d 'step=3' -v 2>&1 | grep "Location"
# Expected: Location: ?step=4

# Step 4: Create admin user (POST)
curl -X POST 'http://localhost:8080/install.php?step=4' \
  -d 'step=4&admin_email=admin@example.com&admin_password=password123&admin_password_confirm=password123' \
  -v 2>&1 | grep "Location"
# Expected: Location: ?step=5

# Verify API works through front controller
curl -s http://localhost:8080/api/health | jq .
# Expected: {"status":"ok",...}

# Verify SPA is served
curl -s http://localhost:8080/ | head -5
# Expected: HTML with React SPA
```

**Step 4: Shut down and restore normal dev environment**

```bash
docker compose -f docker-compose.yml -f docker-compose.package.yml down
docker compose up -d  # back to normal dev mode
```

**Step 5: Commit**

```bash
git add docker-compose.package.yml
git commit -m "feat: add docker-compose.package.yml for testing shared hosting package

Overrides backend service to serve assembled package from dist/package/
instead of mounted backend/ source. Uses same database and webdevops image."
```

---

## Task 6: Package Smoke Tests (E2E)

A small Playwright test file that verifies the package works end-to-end: installer, API through front controller, SPA serving.

**Files:**
- Create: `e2etests/tests/package/package-smoke.spec.ts`

**Step 1: Create the smoke test**

```typescript
import { test, expect } from '@playwright/test';

/**
 * Package smoke tests — verify the shared hosting package works end-to-end.
 *
 * These tests run against the assembled package served via docker-compose.package.yml.
 * The install wizard must be completed before API/SPA tests run.
 *
 * Run: PACKAGE_TEST=1 npm test -- tests/package/package-smoke.spec.ts --workers=1
 */

const PACKAGE_URL = process.env.PACKAGE_URL || 'http://localhost:8080';

test.describe('Package: Install Wizard', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  test('install.php shows prerequisites page', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/install.php?step=1`);
    expect(response.ok()).toBeTruthy();
    const html = await response.text();
    expect(html).toContain('Prerequisites');
    expect(html).toContain('PHP');
  });

  test('install wizard completes via POST steps', async ({ request }) => {
    // Step 2: DB credentials
    const step2 = await request.post(`${PACKAGE_URL}/install.php?step=2`, {
      form: {
        step: '2',
        db_host: 'database',
        db_port: '3306',
        db_name: 'clubbar',
        db_user: 'clubbar',
        db_pass: 'clubbar',
      },
      maxRedirects: 0,
    });
    expect(step2.status()).toBe(302);
    expect(step2.headers()['location']).toContain('step=3');

    // Step 3: Migrations
    const step3 = await request.post(`${PACKAGE_URL}/install.php?step=3`, {
      form: { step: '3' },
      maxRedirects: 0,
    });
    expect(step3.status()).toBe(302);
    expect(step3.headers()['location']).toContain('step=4');

    // Step 4: Admin user
    const step4 = await request.post(`${PACKAGE_URL}/install.php?step=4`, {
      form: {
        step: '4',
        admin_email: 'admin@example.com',
        admin_password: 'password123',
        admin_password_confirm: 'password123',
      },
      maxRedirects: 0,
    });
    expect(step4.status()).toBe(302);
    expect(step4.headers()['location']).toContain('step=5');
  });
});

test.describe('Package: API through front controller', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  test('GET /api/health returns ok', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/api/health`);
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.status).toBe('ok');
  });

  test('POST /api/auth/login works through front controller', async ({ request }) => {
    const response = await request.post(`${PACKAGE_URL}/api/auth/login`, {
      data: {
        email: 'admin@example.com',
        password: 'password123',
      },
    });
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.success).toBe(true);
  });
});

test.describe('Package: SPA serving', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  test('root URL serves SPA index.html', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/`);
    expect(response.ok()).toBeTruthy();
    const html = await response.text();
    expect(html).toContain('<div id="root">');
  });

  test('unknown route serves SPA (client-side routing)', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/members`);
    expect(response.ok()).toBeTruthy();
    const html = await response.text();
    expect(html).toContain('<div id="root">');
  });
});
```

**Step 2: Verify tests are skipped in normal runs**

```bash
cd e2etests && npm test -- tests/package/package-smoke.spec.ts --workers=1
```

Expected: All tests skipped (PACKAGE_TEST not set).

**Step 3: Run tests against the package**

```bash
# Build and start package
./scripts/build-package.sh test
docker compose -f docker-compose.yml -f docker-compose.package.yml up -d
sleep 3

# Drop DB to test fresh install
docker compose exec database mysql -uroot -proot -e "DROP DATABASE IF EXISTS clubbar; CREATE DATABASE clubbar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON clubbar.* TO 'clubbar'@'%';"

# Run package smoke tests
cd e2etests && PACKAGE_TEST=1 npm test -- tests/package/package-smoke.spec.ts --workers=1
```

Expected: All tests pass.

**Step 4: Restore normal dev environment**

```bash
docker compose -f docker-compose.yml -f docker-compose.package.yml down
docker compose up -d
# Re-run migrations for dev
curl "http://localhost:8080/install.php?action=migrate&key=dev-install-key"
```

**Step 5: Commit**

```bash
git add e2etests/tests/package/
git commit -m "test: add package smoke tests for shared hosting deployment

Tests install wizard flow, API routing through front controller, and SPA
serving. Skipped by default — enabled via PACKAGE_TEST=1 env var."
```

---

## Task 7: CI Pipeline — test-package Job

**Files:**
- Modify: `.github/workflows/build.yaml`

**Step 1: Add `test-package` job**

Add this job to the `jobs:` section in `build.yaml`, after the existing jobs:

```yaml
  test-package:
    name: Build & Test Package
    runs-on: ubuntu-24.04
    timeout-minutes: 20
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP 8.3
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer:v2
          extensions: pdo_mysql, mbstring

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'

      - name: Install backend dependencies
        run: composer install --no-dev --optimize-autoloader --no-interaction -d backend

      - name: Build admin frontend
        run: cd admin-frontend && npm ci && npm run build

      - name: Assemble package
        run: |
          mkdir -p dist/package/api
          cp -r backend/src backend/db backend/vendor backend/bootstrap.php backend/composer.json dist/package/api/
          mkdir -p dist/package/api/storage dist/package/api/logs
          cp -r admin-frontend/dist dist/package/assets
          cp package/index.php package/install.php package/.htaccess package/config.sample.php package/README.txt dist/package/

      - name: Start services
        run: |
          docker compose -f docker-compose.yml -f docker-compose.ci.yml -f docker-compose.package.yml up -d database backend
          echo "Waiting for database..."
          for i in $(seq 1 30); do
            if docker compose exec database mariadb-admin ping -uroot -proot --silent 2>/dev/null; then
              echo "Database ready"
              break
            fi
            sleep 2
          done

      - name: Wait for backend
        run: |
          echo "Waiting for backend..."
          for i in $(seq 1 30); do
            if curl -sf http://localhost:8080/install.php?step=1 > /dev/null 2>&1; then
              echo "Backend ready"
              break
            fi
            sleep 2
          done

      - name: Install via curl
        run: |
          # Step 2: DB credentials
          curl -X POST 'http://localhost:8080/install.php?step=2' \
            -d 'step=2&db_host=database&db_port=3306&db_name=clubbar&db_user=clubbar&db_pass=clubbar' \
            -sf -o /dev/null -w '%{http_code}' | grep -q 302

          # Step 3: Migrations
          curl -X POST 'http://localhost:8080/install.php?step=3' \
            -d 'step=3' \
            -sf -o /dev/null -w '%{http_code}' | grep -q 302

          # Step 4: Admin user
          curl -X POST 'http://localhost:8080/install.php?step=4' \
            -d 'step=4&admin_email=admin@example.com&admin_password=password123&admin_password_confirm=password123' \
            -sf -o /dev/null -w '%{http_code}' | grep -q 302

          # Verify API works
          curl -sf http://localhost:8080/api/health | jq .

      - name: Install E2E test dependencies
        run: cd e2etests && npm ci

      - name: Cache Playwright browsers
        uses: actions/cache@v4
        with:
          path: ~/.cache/ms-playwright
          key: playwright-${{ hashFiles('e2etests/package-lock.json') }}

      - name: Install Playwright browsers
        run: cd e2etests && npx playwright install chromium

      - name: Run package smoke tests
        run: cd e2etests && PACKAGE_TEST=1 npx playwright test tests/package/package-smoke.spec.ts --workers=1
        env:
          CI: true

      - name: Upload test results
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: package-test-results
          path: e2etests/test-results/
          retention-days: 14

      - name: Create package ZIP
        run: cd dist && zip -r clubbar-package.zip package/ -q

      - name: Upload package artifact
        uses: actions/upload-artifact@v4
        with:
          name: clubbar-package
          path: dist/clubbar-package.zip
          retention-days: 30

      - name: Upload to GitHub Release
        if: github.event_name == 'release'
        uses: softprops/action-gh-release@v2
        with:
          files: dist/clubbar-package.zip
```

**Step 2: Verify YAML is valid**

```bash
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/build.yaml'))"
```

Expected: No errors.

**Step 3: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "ci: add test-package job to build, test, and release shared hosting package

New CI job:
- Assembles package (backend vendor + admin frontend build + package files)
- Starts services via docker-compose.package.yml override
- Runs install wizard via curl
- Runs Playwright package smoke tests
- Uploads ZIP as artifact (30 days)
- Attaches ZIP to GitHub Release on release events"
```

---

## Task 8: Update plans/INDEX.md

**Files:**
- Modify: `plans/INDEX.md`

**Step 1: Add shared hosting package to Current Plan section**

Add after the current plans:

```markdown
### Shared Hosting Package (📍 IN PROGRESS)

**Plan**: [2026-03-01-shared-hosting-package.md](./2026-03-01-shared-hosting-package.md)
**Design**: [2026-03-01-shared-hosting-package-design.md](./2026-03-01-shared-hosting-package-design.md)

**Goal**: Ship backend + admin frontend as a single ZIP installable on shared hosting (cPanel/Plesk) via FTP upload.

**Status**: Implementation plan written, 7 tasks.

**Tasks**:
1. [ ] Extract Slim bootstrap into reusable file
2. [ ] Package front controller, .htaccess, config template
3. [ ] Install wizard (5-step web installer)
4. [ ] Build script (scripts/build-package.sh)
5. [ ] Docker compose package override for testing
6. [ ] Package smoke tests (Playwright)
7. [ ] CI pipeline test-package job
```

**Step 2: Commit**

```bash
git add plans/INDEX.md
git commit -m "docs: add shared hosting package plan to INDEX.md"
```
