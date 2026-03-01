<?php

declare(strict_types=1);

/**
 * Ruderbar Installation Wizard
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
 */

$configFile = __DIR__ . '/config.php';
$isInstalled = file_exists($configFile);
$isUpdate = isset($_GET['update']);

// --- Already installed? ---
// Only show "already installed" if config exists AND no step/update param is set
// (steps 3-5 need config.php to exist — it's written in step 2)
$step = $_GET['step'] ?? ($_POST['step'] ?? null);
if ($isInstalled && !$isUpdate && $step === null) {
    showAlreadyInstalled();
    exit;
}

// --- Handle AJAX DB test (before any HTML output) ---
if (isset($_GET['action']) && $_GET['action'] === 'test_db') {
    header('Content-Type: application/json');
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

$step = $_GET['step'] ?? ($_POST['step'] ?? '1');
$error = null;

// --- Handle POST actions (PRG pattern: process, then redirect) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case '2': // Test DB + write config
            $dbHost = trim($_POST['db_host'] ?? 'localhost');
            $dbPort = (int) ($_POST['db_port'] ?? 3306);
            $dbName = trim($_POST['db_name'] ?? '');
            $dbUser = trim($_POST['db_user'] ?? '');
            $dbPass = $_POST['db_pass'] ?? '';

            if (empty($dbName)) {
                $error = 'Database name is required.';
                break;
            }
            if (empty($dbUser)) {
                $error = 'Database username is required.';
                break;
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
                        'url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                    ],
                    'session' => [
                        'max_age' => 7200,
                        'regeneration_interval' => 900,
                    ],
                    'api_token' => [
                        'ttl_days' => 90,
                    ],
                ], true) . ";\n";

                if (file_put_contents($configFile, $configContent) === false) {
                    $error = 'Failed to write config.php. Check directory permissions.';
                    break;
                }

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
                        header('Location: ?step=4&update=1');
                    } else {
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

function showAlreadyInstalled(): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ruderbar - Already Installed</title>
        <style><?php echo getStyles(); ?></style>
    </head>
    <body>
        <div class="container">
            <h1>Ruderbar</h1>
            <div class="card">
                <h2>Already Installed</h2>
                <p>Ruderbar is already installed and configured.</p>
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
    $title = $isUpdate ? 'Ruderbar Update' : 'Ruderbar Installation';
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
    $defaults = ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => ''];
    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
        if (isset($config['db'])) {
            $defaults = array_merge($defaults, $config['db']);
        }
    }
    ?>
    <h2>Step 2: Database</h2>
    <p>Enter your MySQL/MariaDB database credentials.</p>
    <form method="post" action="?step=2<?php echo $updateParam; ?>" id="dbForm">
        <input type="hidden" name="step" value="2">
        <label>
            Host
            <input type="text" name="db_host" value="<?php echo htmlspecialchars((string)$defaults['host']); ?>" required>
        </label>
        <label>
            Port
            <input type="number" name="db_port" value="<?php echo (int)$defaults['port']; ?>" required>
        </label>
        <label>
            Database Name
            <input type="text" name="db_name" value="<?php echo htmlspecialchars((string)$defaults['name']); ?>" required>
        </label>
        <label>
            Username
            <input type="text" name="db_user" value="<?php echo htmlspecialchars((string)$defaults['user']); ?>" required>
        </label>
        <label>
            Password
            <input type="password" name="db_pass" value="<?php echo htmlspecialchars((string)$defaults['pass']); ?>">
        </label>
        <div class="btn-row">
            <button type="button" onclick="testConnection()" class="btn btn-secondary" id="testBtn">Test Connection</button>
            <span id="testResult"></span>
        </div>
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
    <p>Ruderbar has been installed successfully. You can now log in with the admin account you just created.</p>
    <a href="/" class="btn">Go to Admin Panel</a>
    <hr>
    <p><small>For security, consider deleting or renaming <code>install.php</code> after installation.</small></p>
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
@media (max-width: 640px) {
    .container { margin: 20px auto; }
    .card { padding: 20px; }
    h1 { font-size: 24px; }
}
CSS;
}
