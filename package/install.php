<?php

declare(strict_types=1);

/**
 * Club Bar Installation Wizard
 *
 * Self-contained installer with embedded HTML/CSS.
 * No external dependencies — works before Composer vendor/ is loaded
 * (except step 3, which loads vendor for MigrationRunner).
 *
 * Steps:
 * 1. Prerequisites check (PHP version, extensions, writable dirs)
 * 2. Database credentials (form + AJAX test connection)
 * 3. Run migrations
 * 4. Create admin user
 * 5. Done
 *
 * State is tracked in .installer-data (JSON):
 *   {"key": "<random hex>", "completed_step": <0|2|3>}
 *
 * The file is generated on first access and deleted after successful installation.
 * The next installer run (e.g. to apply migrations after an update) generates a fresh file.
 */

$configFile = __DIR__ . '/config.php';
$isInstalled = file_exists($configFile);
$isUpdate = isset($_GET['update']);
$dataFile = __DIR__ . '/.installer-data';

// The installer session carries install_key_verified — leaking it over plain
// HTTP hands an attacker the wizard. Secure is set only when the request
// actually arrived over TLS: a Secure cookie would otherwise be dropped and
// make the wizard unusable on a plain-HTTP host.
session_set_cookie_params([
    'path'     => '/',
    'secure'   => installerRequestIsHttps(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// --- Handle reset ---
if (isset($_GET['action']) && $_GET['action'] === 'reset') {
    session_destroy();
    header('Location: install.php');
    exit;
}

// --- Handle AJAX DB test (session-protected) ---
if (isset($_GET['action']) && $_GET['action'] === 'test_db') {
    header('Content-Type: application/json');
    if (empty($_SESSION['install_key_verified'])) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    try {
        $testPdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $_GET['host'] ?? 'localhost',
                (int) ($_GET['port'] ?? 3306),
                $_GET['name'] ?? ''
            ),
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

// --- Install key gate ---
// .installer-data holds the one-time install key and tracks completed steps.
// Generated on first access; deleted after successful installation.
// The user retrieves the key via FTP/cPanel/SSH and pastes it into the form.
$installerData = readInstallerData($dataFile);
if (empty($installerData)) {
    $installerData = ['key' => bin2hex(random_bytes(16)), 'completed_step' => 0];
    writeInstallerData($dataFile, $installerData);
}

if (empty($_SESSION['install_key_verified'])) {
    $keyError = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install_key'])) {
        $provided = trim($_POST['install_key']);
        $stored   = $installerData['key'] ?? '';
        if ($stored !== '' && hash_equals($stored, $provided)) {
            $_SESSION['install_key_verified'] = true;
        } else {
            $keyError = 'Invalid install key. Check the "key" field in .installer-data on your server.';
        }
    }

    if (empty($_SESSION['install_key_verified'])) {
        renderKeyGate($keyError);
        exit;
    }
}

$completedStep = (int) ($installerData['completed_step'] ?? 0);

// --- Already installed? ---
$step = $_GET['step'] ?? ($_POST['step'] ?? null);
if ($isInstalled && !$isUpdate && $step === null) {
    showAlreadyInstalled();
    exit;
}

$step = $_GET['step'] ?? ($_POST['step'] ?? '1');
$error = null;

// --- Enforce step ordering on GET ---
// Prevent jumping ahead to steps whose prerequisites haven't been completed.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $minCompleted = ['3' => 2, '4' => 3];
    if (isset($minCompleted[$step]) && $completedStep < $minCompleted[$step]) {
        header('Location: ?step=' . ($completedStep < 2 ? '2' : '3'));
        exit;
    }
}

// --- Handle POST actions (PRG pattern: process, then redirect) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case '2': // Test DB + write config
            $dbHost = trim($_POST['db_host'] ?? 'localhost');
            $dbPort = (int) ($_POST['db_port'] ?? 3306);
            $dbName = trim($_POST['db_name'] ?? '');
            $dbUser = trim($_POST['db_user'] ?? '');
            $dbPass = $_POST['db_pass'] ?? '';
            $llmProvider   = trim($_POST['llm_provider'] ?? '');
            $llmApiKey     = trim($_POST['llm_api_key'] ?? '');
            $llmModel      = trim($_POST['llm_model'] ?? '');
            $visionApiKey  = trim($_POST['vision_api_key'] ?? '');

            if (empty($dbName)) {
                $error = 'Database name is required.';
                break;
            }
            if (empty($dbUser)) {
                $error = 'Database username is required.';
                break;
            }

            // Generate a fresh TOTP encryption key for this installation
            $totpKey = bin2hex(random_bytes(32));

            // Preserve existing TOTP key if re-running step 2 on an already-installed instance
            if (file_exists($configFile)) {
                $existingConfig = require $configFile;
                if (!empty($existingConfig['security']['totp_encryption_key'])) {
                    $totpKey = $existingConfig['security']['totp_encryption_key'];
                }
            }

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
                        // The scheme decides more than link building: AppConfig
                        // derives the session cookie's Secure flag from it.
                        'url' => (installerRequestIsHttps() ? 'https' : 'http')
                            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                    ],
                    'session' => [
                        'max_age' => 7200,
                        'regeneration_interval' => 900,
                    ],
                    'api_token' => [
                        'ttl_days' => 90,
                    ],
                    'security' => [
                        'totp_encryption_key' => $totpKey,
                    ],
                    'llm' => [
                        'provider' => $llmProvider,
                        'api_key'  => $llmApiKey,
                        'model'    => $llmModel,
                        'thinking_budget' => 0,
                    ],
                    'vision' => [
                        'api_key' => $visionApiKey,
                    ],
                ], true) . ";\n";

                if (file_put_contents($configFile, $configContent) === false) {
                    $error = 'Failed to write config.php. Check directory permissions.';
                    break;
                }

                $installerData['completed_step'] = 2;
                writeInstallerData($dataFile, $installerData);

                $redirectParam = $isUpdate ? '&update=1' : '';
                header('Location: ?step=3' . $redirectParam);
                exit;
            } catch (\PDOException $e) {
                $error = 'Database connection failed: ' . $e->getMessage();
            }
            break;

        case '3': // Run migrations
            $autoloadPath = __DIR__ . '/backend/vendor/autoload.php';
            if (!file_exists($autoloadPath)) {
                $error = 'Vendor autoload not found at backend/vendor/autoload.php. Ensure the package was extracted correctly.';
                break;
            }

            require $autoloadPath;

            if (!file_exists($configFile)) {
                $error = 'config.php not found. Please complete step 2 first.';
                break;
            }

            $config = require $configFile;

            try {
                $pdo = new PDO(
                    sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                        $config['db']['host'],
                        $config['db']['port'],
                        $config['db']['name']
                    ),
                    $config['db']['user'],
                    $config['db']['pass'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );

                $runner = new \App\Db\MigrationRunner($pdo);
                $result = $runner->migrate(__DIR__ . '/backend/db/migrations', 'installer');

                $failed = array_filter($result, fn($r) => ($r['status'] ?? '') === 'FAIL');
                if ($failed) {
                    $failedEntry = $failed[array_key_first($failed)];
                    $error = 'Migration failed: ' . ($failedEntry['message'] ?? 'unknown error');
                } else {
                    if ($isUpdate) {
                        // Update complete — delete installer data so next run requires a fresh key
                        @unlink($dataFile);
                        header('Location: ?step=4&update=1');
                    } else {
                        $installerData['completed_step'] = 3;
                        writeInstallerData($dataFile, $installerData);
                        header('Location: ?step=4');
                    }
                    exit;
                }
            } catch (\Throwable $e) {
                $error = 'Migration error: ' . $e->getMessage();
            }
            break;

        case '4': // Create admin user
            if ($isUpdate) {
                // Update mode skips admin creation
                break;
            }

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

            if (!file_exists($configFile)) {
                $error = 'config.php not found. Please complete step 2 first.';
                break;
            }

            $config = require $configFile;

            try {
                $pdo = new PDO(
                    sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                        $config['db']['host'],
                        $config['db']['port'],
                        $config['db']['name']
                    ),
                    $config['db']['user'],
                    $config['db']['pass'],
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );

                $id = bin2hex(random_bytes(16));
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                $stmt = $pdo->prepare(
                    'INSERT INTO admin_users (id, email, password_hash, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())'
                );
                $stmt->execute([$id, $email, $hashedPassword]);

                // Installation complete — delete installer data so next run requires a fresh key
                @unlink($dataFile);
                header('Location: ?step=5');
                exit;
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    $error = 'An admin user with this email already exists.';
                } else {
                    $error = 'Failed to create admin user: ' . $e->getMessage();
                }
            }
            break;
    }
}

// --- Render page ---
renderPage($step, $error, $isUpdate);

// ============================================================================
// Functions
// ============================================================================

/**
 * True when the browser reached this script over TLS — directly, or through a
 * reverse proxy that terminated it (common on shared hosting).
 */
function installerRequestIsHttps(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    if ($https !== '' && strtolower((string) $https) !== 'off') {
        return true;
    }

    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if ($forwarded !== '') {
        return strtolower(trim(explode(',', (string) $forwarded)[0])) === 'https';
    }

    return ((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443;
}

function readInstallerData(string $dataFile): array
{
    if (!file_exists($dataFile)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($dataFile), true);
    return is_array($data) ? $data : [];
}

function writeInstallerData(string $dataFile, array $data): void
{
    file_put_contents($dataFile, json_encode($data));
}

function checkPrerequisites(): array
{
    $checks = [];

    // PHP version
    $checks[] = [
        'name' => 'PHP >= 8.3',
        'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
        'value' => PHP_VERSION,
    ];

    // Required extensions
    foreach (['pdo_mysql', 'json', 'mbstring'] as $ext) {
        $checks[] = [
            'name' => "Extension: {$ext}",
            'ok' => extension_loaded($ext),
            'value' => extension_loaded($ext) ? 'loaded' : 'missing',
        ];
    }

    // Writable directories
    $apiDir = __DIR__ . '/backend';
    foreach (['storage', 'logs'] as $dir) {
        $path = "{$apiDir}/{$dir}";
        $writable = is_dir($path) && is_writable($path);
        $checks[] = [
            'name' => "Writable: backend/{$dir}/",
            'ok' => $writable,
            'value' => $writable ? 'writable' : (is_dir($path) ? 'not writable' : 'directory missing'),
        ];
    }

    // config.php writable (parent directory)
    $configDir = __DIR__;
    $configWritable = is_writable($configDir);
    $checks[] = [
        'name' => 'Writable: document root (for config.php)',
        'ok' => $configWritable,
        'value' => $configWritable ? 'writable' : 'not writable',
    ];

    // mod_rewrite (best-effort)
    if (function_exists('apache_get_modules')) {
        $hasRewrite = in_array('mod_rewrite', apache_get_modules());
        $checks[] = [
            'name' => 'Apache mod_rewrite',
            'ok' => $hasRewrite,
            'value' => $hasRewrite ? 'enabled' : 'disabled',
        ];
    } else {
        $checks[] = [
            'name' => 'Apache mod_rewrite',
            'ok' => true,
            'value' => 'cannot detect (CGI/FPM mode — likely OK)',
        ];
    }

    return $checks;
}

function renderKeyGate(?string $error): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Club Bar - Installer</title>
        <style><?php echo getStyles(); ?></style>
    </head>
    <body>
        <div class="container">
            <h1>Club Bar</h1>
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <div class="card">
                <h2>Install Key Required</h2>
                <p>A one-time install key has been written to <code>.installer-data</code> in your document root.</p>
                <p>Open the file via <strong>FTP</strong>, <strong>cPanel File Manager</strong>, or <strong>SSH</strong> and copy the value of the <code>key</code> field, then paste it below.</p>
                <form method="post" action="install.php">
                    <label>
                        Install Key
                        <input type="text" name="install_key" required autofocus autocomplete="off"
                               placeholder='Paste the "key" value from .installer-data'>
                    </label>
                    <button type="submit" class="btn">Verify &amp; Continue</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
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
                <p>Club Bar is already installed and configured.</p>
                <a href="/" class="btn">Go to Admin Panel</a>
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
        <title><?php echo htmlspecialchars($title); ?></title>
        <style><?php echo getStyles(); ?></style>
    </head>
    <body>
        <div class="container">
            <h1><?php echo htmlspecialchars($title); ?></h1>

            <?php if (!$isUpdate): ?>
            <div class="steps">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="step <?php echo $i == (int)$step ? 'active' : ($i < (int)$step ? 'done' : ''); ?>">
                        <?php echo $i; ?>
                    </span>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card">
            <?php
            switch ($step) {
                case '1':
                    renderStep1($isUpdate);
                    break;
                case '2':
                    renderStep2($isUpdate);
                    break;
                case '3':
                    renderStep3($isUpdate);
                    break;
                case '4':
                    renderStep4($isUpdate);
                    break;
                case '5':
                    renderStep5();
                    break;
                default:
                    renderStep1($isUpdate);
                    break;
            }
            ?>
            </div>
            <p class="reset-link"><small><a href="?action=reset">Start over</a></small></p>
        </div>
    </body>
    </html>
    <?php
}

function renderStep1(bool $isUpdate): void
{
    $checks = checkPrerequisites();
    $allOk = !array_filter($checks, fn($c) => !$c['ok']);
    $updateParam = $isUpdate ? '&update=1' : '';
    ?>
    <h2>Step 1: Prerequisites</h2>
    <p>Checking that your server meets all requirements.</p>
    <table>
        <?php foreach ($checks as $check): ?>
            <tr class="<?php echo $check['ok'] ? 'check-ok' : 'check-fail'; ?>">
                <td class="check-icon"><?php echo $check['ok'] ? '&#10003;' : '&#10007;'; ?></td>
                <td><?php echo htmlspecialchars($check['name']); ?></td>
                <td class="check-value"><?php echo htmlspecialchars($check['value']); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($allOk): ?>
        <a href="?step=2<?php echo $updateParam; ?>" class="btn">Continue</a>
    <?php else: ?>
        <p class="error-inline">Please fix the issues above before continuing.</p>
        <a href="?step=1<?php echo $updateParam; ?>" class="btn btn-secondary">Re-check</a>
    <?php endif; ?>
    <?php
}

function renderStep2(bool $isUpdate): void
{
    $updateParam = $isUpdate ? '&update=1' : '';

    // Pre-fill from existing config if available
    $dbDefaults  = ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => ''];
    $llmDefaults    = ['provider' => '', 'api_key' => '', 'model' => ''];
    $visionDefaults = ['api_key' => ''];
    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
        if (isset($config['db'])) {
            $dbDefaults = array_merge($dbDefaults, $config['db']);
        }
        if (isset($config['llm'])) {
            $llmDefaults = array_merge($llmDefaults, $config['llm']);
        }
        if (isset($config['vision'])) {
            $visionDefaults = array_merge($visionDefaults, $config['vision']);
        }
    }
    ?>
    <h2>Step 2: Database</h2>
    <p>Enter your MySQL/MariaDB database credentials.</p>
    <form method="post" action="?step=2<?php echo $updateParam; ?>" id="dbForm">
        <input type="hidden" name="step" value="2">
        <label>
            Host
            <input type="text" name="db_host" value="<?php echo htmlspecialchars((string)$dbDefaults['host']); ?>" required>
        </label>
        <label>
            Port
            <input type="number" name="db_port" value="<?php echo (int)$dbDefaults['port']; ?>" required>
        </label>
        <label>
            Database Name
            <input type="text" name="db_name" value="<?php echo htmlspecialchars((string)$dbDefaults['name']); ?>" required>
        </label>
        <label>
            Username
            <input type="text" name="db_user" value="<?php echo htmlspecialchars((string)$dbDefaults['user']); ?>" required>
        </label>
        <label>
            Password
            <input type="password" name="db_pass" value="<?php echo htmlspecialchars((string)$dbDefaults['pass']); ?>">
        </label>
        <div class="btn-row">
            <button type="button" onclick="testConnection()" class="btn btn-secondary" id="testBtn">Test Connection</button>
            <span id="testResult"></span>
        </div>

        <hr style="margin: 24px 0;">
        <h3 style="margin-bottom: 8px; font-size: 16px; color: #374151;">Mandate Scan Extraction <small style="font-weight:400; color:#6b7280;">(optional)</small></h3>
        <p style="font-size: 13px; color: #6b7280; margin-bottom: 16px;">
            Enables AI-powered extraction of member data from scanned SEPA mandate images.
            Requires both a Google Cloud Vision API key (for OCR) and an LLM provider key (for field extraction).
            Leave either field blank to disable this feature silently.
        </p>
        <label>
            Google Cloud Vision API Key
            <input type="password" name="vision_api_key" value="<?php echo htmlspecialchars((string)$visionDefaults['api_key']); ?>"
                   placeholder="AIza...">
        </label>

        <h4 style="margin: 16px 0 8px; font-size: 14px; color: #374151; font-weight: 600;">LLM Provider</h4>
        <label>
            Provider
            <select name="llm_provider" style="display:block;width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;margin-top:4px;font-size:14px;color:#1f2937;background:#fff;">
                <option value="" <?php echo $llmDefaults['provider'] === '' ? 'selected' : ''; ?>>Disabled</option>
                <option value="anthropic" <?php echo $llmDefaults['provider'] === 'anthropic' ? 'selected' : ''; ?>>Anthropic (Claude)</option>
                <option value="openai" <?php echo $llmDefaults['provider'] === 'openai' ? 'selected' : ''; ?>>OpenAI (GPT)</option>
            </select>
        </label>
        <label>
            API Key
            <input type="password" name="llm_api_key" value="<?php echo htmlspecialchars((string)$llmDefaults['api_key']); ?>"
                   placeholder="sk-ant-... or sk-...">
        </label>
        <label>
            Model <small>(optional — leave blank for provider default)</small>
            <input type="text" name="llm_model" value="<?php echo htmlspecialchars((string)$llmDefaults['model']); ?>"
                   placeholder="claude-haiku-4-5-20251001 / gpt-4o-mini">
        </label>

        <button type="submit" class="btn">Save &amp; Continue</button>
    </form>
    <script>
    function testConnection() {
        var btn = document.getElementById('testBtn');
        var result = document.getElementById('testResult');
        btn.disabled = true;
        result.textContent = 'Testing...';
        result.className = '';
        var form = document.getElementById('dbForm');
        var params = new URLSearchParams({
            action: 'test_db',
            host: form.db_host.value,
            port: form.db_port.value,
            name: form.db_name.value,
            user: form.db_user.value,
            pass: form.db_pass.value
        });
        fetch('?' + params)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    result.textContent = 'Connection successful!';
                    result.className = 'test-ok';
                } else {
                    result.textContent = 'Failed: ' + data.error;
                    result.className = 'test-fail';
                }
                btn.disabled = false;
            })
            .catch(function() {
                result.textContent = 'Request failed — check your server.';
                result.className = 'test-fail';
                btn.disabled = false;
            });
    }
    </script>
    <?php
}

function renderStep3(bool $isUpdate): void
{
    $updateParam = $isUpdate ? '&update=1' : '';
    ?>
    <h2>Step 3: Database Setup</h2>
    <p>Click the button below to create or update database tables.</p>
    <form method="post" action="?step=3<?php echo $updateParam; ?>">
        <input type="hidden" name="step" value="3">
        <button type="submit" class="btn" id="migrateBtn" onclick="this.disabled=true;this.textContent='Running...';this.form.submit();">
            Run Migrations
        </button>
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
    <p>Create the first administrator account.</p>
    <form method="post" action="?step=4">
        <input type="hidden" name="step" value="4">
        <label>
            Email
            <input type="email" name="admin_email" required autocomplete="email">
        </label>
        <label>
            Password <small>(minimum 8 characters)</small>
            <input type="password" name="admin_password" minlength="8" required autocomplete="new-password">
        </label>
        <label>
            Confirm Password
            <input type="password" name="admin_password_confirm" minlength="8" required autocomplete="new-password">
        </label>
        <button type="submit" class="btn">Create Admin &amp; Finish</button>
    </form>
    <?php
}

function renderStep5(): void
{
    ?>
    <h2>Installation Complete!</h2>
    <p>Club Bar has been installed successfully. You can now log in with the admin account you just created.</p>
    <a href="/" class="btn">Go to Admin Panel</a>
    <?php
}

function getStyles(): string
{
    return <<<'CSS'
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: #f3f4f6;
    color: #1f2937;
    line-height: 1.6;
}
.container {
    max-width: 600px;
    margin: 40px auto;
    padding: 0 20px;
}
h1 {
    text-align: center;
    margin-bottom: 24px;
    color: #1a56db;
    font-size: 28px;
    letter-spacing: -0.5px;
}
h2 {
    margin-bottom: 12px;
    font-size: 20px;
    color: #111827;
}
p {
    margin-bottom: 16px;
    color: #4b5563;
}
.card {
    background: #fff;
    border-radius: 8px;
    padding: 28px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
    margin-bottom: 20px;
}
.steps {
    display: flex;
    justify-content: center;
    gap: 12px;
    margin-bottom: 24px;
}
.step {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #e5e7eb;
    color: #6b7280;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 600;
    transition: background 0.2s, color 0.2s;
}
.step.active {
    background: #1a56db;
    color: #fff;
    box-shadow: 0 0 0 3px rgba(26,86,219,0.2);
}
.step.done {
    background: #16a34a;
    color: #fff;
}
label {
    display: block;
    margin-bottom: 16px;
    font-weight: 500;
    font-size: 14px;
    color: #374151;
}
label small {
    font-weight: 400;
    color: #6b7280;
}
input[type="text"],
input[type="password"],
input[type="email"],
input[type="number"] {
    display: block;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    margin-top: 4px;
    font-size: 14px;
    color: #1f2937;
    background: #fff;
    transition: border-color 0.15s;
}
input:focus {
    outline: none;
    border-color: #1a56db;
    box-shadow: 0 0 0 3px rgba(26,86,219,0.1);
}
.btn {
    display: inline-block;
    padding: 10px 24px;
    background: #1a56db;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    margin-top: 8px;
    transition: background 0.15s;
}
.btn:hover {
    background: #1e40af;
}
.btn-secondary {
    background: #6b7280;
}
.btn-secondary:hover {
    background: #4b5563;
}
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
.btn-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}
.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #dc2626;
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 14px;
}
.error-inline {
    color: #dc2626;
    font-size: 14px;
    margin-bottom: 8px;
}
table {
    width: 100%;
    margin-bottom: 20px;
    border-collapse: collapse;
}
td {
    padding: 8px 10px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
}
.check-icon {
    width: 24px;
    text-align: center;
    font-size: 16px;
}
.check-ok .check-icon { color: #16a34a; }
.check-fail .check-icon { color: #dc2626; }
.check-ok { color: #1f2937; }
.check-fail { color: #dc2626; }
.check-value {
    color: #6b7280;
    text-align: right;
}
.test-ok { color: #16a34a; font-weight: 500; }
.test-fail { color: #dc2626; font-size: 13px; }
hr {
    margin: 20px 0;
    border: none;
    border-top: 1px solid #e5e7eb;
}
a { color: #1a56db; }
a:hover { text-decoration: underline; }
code {
    background: #f3f4f6;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 13px;
}
.reset-link {
    text-align: center;
    color: #9ca3af;
    margin-top: 4px;
    margin-bottom: 0;
}
.reset-link a { color: #9ca3af; }
@media (max-width: 640px) {
    .container { margin: 20px auto; }
    .card { padding: 20px; }
    h1 { font-size: 24px; }
}
CSS;
}
