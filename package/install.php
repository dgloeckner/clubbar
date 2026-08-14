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

// config.php, storage/ and logs/ live in a data directory the installer
// resolves — above the document root where the host has a writable parent,
// inside it where it does not (#245, ADR-0031 decision 2). Required by path:
// this script runs long before Composer's autoloader is available.
require_once __DIR__ . '/backend/src/Shared/Config/DataDirectory.php';

// The prerequisite step measures the effective hardening rather than assuming
// it applied (#247, ADR-0031 decision 3). Same requirement: these run before
// Composer's autoloader exists, so they are required by path.
require_once __DIR__ . '/backend/src/Shared/Security/SecurityFinding.php';
require_once __DIR__ . '/backend/src/Shared/Security/SecurityCheckContext.php';
require_once __DIR__ . '/backend/src/Shared/Security/HttpProbe.php';
require_once __DIR__ . '/backend/src/Shared/Security/SecuritySelfCheck.php';

// The narrowest mode this host tolerates on config.php, storage/ and logs/,
// applied and then verified (#248, ADR-0031 decision 4). Required by path for
// the same reason.
require_once __DIR__ . '/backend/src/Shared/Security/FileModes.php';

// Club Bar keeps every instant in UTC (#365). The installer writes timestamps of
// its own — the first admin's created_at among them — so it pins the runtime
// before it writes anything. Required by path for the same reason as the above.
require_once __DIR__ . '/backend/src/Shared/Time/Utc.php';

use App\Shared\Config\DataDirectory;
use App\Shared\Security\FileModes;
use App\Shared\Security\SecurityCheckContext;
use App\Shared\Security\SecurityFinding;
use App\Shared\Security\SecuritySelfCheck;
use App\Shared\Time\Utc;

// The wizard takes the database password, generates the TOTP encryption key and
// prints both back into a form — and it is the one page of this deployment that
// runs before the application's own startup hardening does. So it applies the
// same PHP_INI_ALL directives here, from the table the self-check measures
// against (ADR-0031 decision 1). A host default of `display_errors=On` would
// otherwise put a failed `new PDO(...)` — arguments and all — on this page.
//
// Placed above `session_start()` deliberately: PHP refuses every `session.*`
// directive once a session is open.
foreach (SecuritySelfCheck::EXPECTED_DIRECTIVES as $directive => $enabled) {
    ini_set($directive, $enabled ? '1' : '0');
}

// Same reasoning, for the clock rather than the error output: the wizard runs
// before bootstrap.php ever does, and the rows it writes are timestamped.
Utc::apply();

$configFile = DataDirectory::configPath(__DIR__);
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
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . Utc::SQL_OFFSET . "'",
            ]
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

            // Decide where this installation's data lives before writing the
            // first byte of it. A host with no writable parent keeps the
            // in-document-root layout — that must stay installable — but the
            // choice is recorded rather than assumed.
            $placement = DataDirectory::probe(__DIR__);
            $prepared  = DataDirectory::prepare($placement['path']);
            if (!$prepared['ok']) {
                $error = 'Could not prepare the data directory: ' . $prepared['error'];
                break;
            }
            // Whatever the package shipped or an older install left behind,
            // storage/ and logs/ end up as narrow as this host tolerates —
            // and a mode that broke them is put back rather than left behind
            // (#248, ADR-0031 decision 4). Nothing to report inline: the
            // security rows on the prerequisites screen and in the admin panel
            // measure the result, so a host that refused says so there instead
            // of here, where it would only block an installation that works.
            FileModes::hardenData($placement['path'], null, DataDirectory::SUBDIRECTORIES, __DIR__);
            // The data directory when there is one above the document root,
            // next to index.php when there is not — the fallback layout is left
            // exactly as it was. $configFile still points at the existing
            // config, wherever this installation currently keeps it.
            $configTarget = DataDirectory::configPathIn(__DIR__, $placement['path']);

            // Generate fresh security keys for this installation
            $totpKey = bin2hex(random_bytes(32));
            $ibanFingerprintKey = bin2hex(random_bytes(32));

            // Preserve existing keys if re-running step 2 on an already-installed
            // instance — regenerating the fingerprint key would break bank-change
            // detection for every stored mandate (ADR-0036).
            if (file_exists($configFile)) {
                $existingConfig = require $configFile;
                if (!empty($existingConfig['security']['totp_encryption_key'])) {
                    $totpKey = $existingConfig['security']['totp_encryption_key'];
                }
                if (!empty($existingConfig['security']['iban_fingerprint_key'])) {
                    $ibanFingerprintKey = $existingConfig['security']['iban_fingerprint_key'];
                }
            }

            try {
                $testPdo = new PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName),
                    $dbUser,
                    $dbPass,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . Utc::SQL_OFFSET . "'",
                    ]
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
                        'ttl_days' => 365,
                    ],
                    'security' => [
                        'totp_encryption_key' => $totpKey,
                        'iban_fingerprint_key' => $ibanFingerprintKey,
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

                if (file_put_contents($configTarget, $configContent) === false) {
                    $error = "Failed to write {$configTarget}. Check the directory's permissions.";
                    break;
                }

                // The database password and the TOTP key are in there, so the
                // file is narrowed to 0600 — and then read back, because a
                // chmod that locked PHP out of its own configuration would
                // otherwise take the installation down on the next request
                // (#248, ADR-0031 decision 4).
                FileModes::narrowConfigFile($configTarget);

                if ($placement['outside']) {
                    // Tell the front controller where the data went. Without
                    // this the next request resolves the in-document-root
                    // layout and finds no config at all.
                    if (!DataDirectory::writePointer(__DIR__, $placement['path'])) {
                        @unlink($configTarget);
                        $error = 'Failed to write ' . DataDirectory::POINTER_FILE . ' to the document root. Check its permissions.';
                        break;
                    }

                    // A config left behind in the document root is the exposure
                    // this step exists to end — and the one the front controller
                    // falls back to, so it would also shadow the new one.
                    $legacyConfig = __DIR__ . '/config.php';
                    if ($configTarget !== $legacyConfig && is_file($legacyConfig)) {
                        @unlink($legacyConfig);
                    }
                }

                $configFile = $configTarget;
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
                        // Every timestamp this installer writes must be UTC (#365);
                        // NOW() is resolved by the server in the session zone.
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . Utc::SQL_OFFSET . "'",
                    ]
                );

                $runner = new \App\Db\MigrationRunner($pdo);
                $result = $runner->migrate(
                    __DIR__ . '/backend/db/migrations',
                    'installer',
                    ['storageDir' => DataDirectory::resolve(__DIR__) . '/storage'],
                );

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
                        // Every timestamp this installer writes must be UTC (#365);
                        // NOW() is resolved by the server in the session zone.
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . Utc::SQL_OFFSET . "'",
                    ]
                );

                $id = bin2hex(random_bytes(16));
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                $stmt = $pdo->prepare(
                    'INSERT INTO admin_users (id, email, password_hash, display_name, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())'
                );
                $stmt->execute([$id, $email, $hashedPassword, $email]);

                // Instance branding (ADR-0034): optional at install time — the
                // migration already seeds a 'Club Bar' row, so an empty field
                // here just leaves that default in place rather than blocking
                // the wizard on it.
                $instanceName = trim($_POST['instance_name'] ?? '');
                if ($instanceName !== '') {
                    $stmt = $pdo->prepare(
                        'UPDATE instance_config SET instance_name = ?, updated_by_admin_id = ?, updated_at = NOW() WHERE id = 1'
                    );
                    $stmt->execute([$instanceName, $id]);
                }

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
    return SecurityCheckContext::requestIsHttps($_SERVER);
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

    // Required extensions. `fileinfo` is what types an uploaded mandate from
    // its own bytes rather than from the Content-Type the browser attached
    // (#107) — without it, uploading a document fails.
    foreach (['pdo_mysql', 'json', 'mbstring', 'fileinfo'] as $ext) {
        $checks[] = [
            'name' => "Extension: {$ext}",
            'ok' => extension_loaded($ext),
            'value' => extension_loaded($ext) ? 'loaded' : 'missing',
        ];
    }

    // Where config.php, the scanned mandates and the logs will be placed. The
    // security report below says whether that placement protects them; these
    // rows only decide whether the installation can be written at all.
    $placement = DataDirectory::probe(__DIR__);

    // storage/ and logs/ are written on every request whichever layout wins.
    foreach (DataDirectory::SUBDIRECTORIES as $subdirectory) {
        $path = $placement['path'] . '/' . $subdirectory;
        $writable = DataDirectory::canCreate($path);
        $checks[] = [
            'name'  => "Writable: {$subdirectory}/",
            'ok'    => $writable,
            'value' => $writable ? 'writable' : (is_dir($path) ? 'not writable' : 'cannot be created'),
        ];
    }

    // The document root has to stay writable regardless: config.php lands there
    // in the fallback layout, and the pointer file naming the data directory
    // lands there in the other one.
    $rootWritable = is_writable(__DIR__);
    $checks[] = [
        'name' => 'Writable: document root',
        'ok' => $rootWritable,
        'value' => $rootWritable ? 'writable' : 'not writable',
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

    return [...$checks, ...securityChecks($placement)];
}

/**
 * The security self-check, as prerequisite rows (#247, ADR-0031 decision 3).
 *
 * Same engine as the admin panel's report and the package smoke test — the
 * only thing that differs is the context, because this runs before the
 * installation exists. Two consequences of that are worth naming:
 *
 * - The data directory is the one `DataDirectory::probe()` *would* choose, not
 *   one that has been created. Rows about files that are not there yet come
 *   back as "could not be measured", which is the honest answer and not a pass.
 * - `session.save_path` is left out: the wizard's own session is not the
 *   application's, and reporting the host default here would be a finding about
 *   nothing.
 *
 * None of these rows block the wizard. A host that will not honour `.htaccess`
 * must stay installable — ADR-0031 keeps the in-document-root layout supported
 * precisely so it does — so a red row here states the consequence and leaves
 * the decision with the treasurer.
 *
 * @param array{path:string,outside:bool,reason:string} $placement
 */
function securityChecks(array $placement): array
{
    $configFile = DataDirectory::configPathIn(__DIR__, $placement['path']);
    $https      = installerRequestIsHttps();

    $findings = SecuritySelfCheck::run(new SecurityCheckContext(
        documentRoot: __DIR__,
        dataDirectory: $placement['path'],
        configFile: is_file($configFile) ? $configFile : null,
        dataDirectoryReason: $placement['reason'],
        expectedSessionSavePath: null,
        https: $https,
        debug: false,
        baseUrlCandidates: SecurityCheckContext::baseUrlCandidatesFrom($_SERVER, $https),
        // The package's own file, and one no other vhost would answer 200 for
        // at this stage: the application cannot serve /api/health until step 3
        // has created its tables.
        controlPaths: ['/README.txt'],
    ));

    return array_map(static fn(SecurityFinding $finding): array => [
        'name' => $finding->label,
        // `ok` gates the Continue button and is always true here; the colour of
        // the row comes from `severity` instead.
        'ok'       => true,
        'severity' => $finding->status,
        'value'    => $finding->observed,
        'note'     => $finding->remedy,
    ], $findings);
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
        <script src="install.js"></script>
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
    <p>Checking that your server meets all requirements, and measuring the protections it actually applies —
    <em>measuring</em>, because on shared hosting a rule we ship can be ignored without anything saying so.</p>
    <table>
        <?php foreach ($checks as $check): ?>
            <?php
            // A prerequisite that is not met blocks; a security finding never
            // does, but is still shown in the colour it earned — red where the
            // consequence is that member data is exposed, amber where the host
            // simply does not offer the protection (#247).
            $severity = $check['severity'] ?? (!$check['ok'] ? 'fail' : (!empty($check['warn']) ? 'warn' : 'pass'));
            $class = ['fail' => 'check-fail', 'warn' => 'check-warn', 'unknown' => 'check-warn'][$severity] ?? 'check-ok';
            $icon  = ['fail' => '&#10007;', 'warn' => '&#33;', 'unknown' => '&#63;'][$severity] ?? '&#10003;';
            ?>
            <tr class="<?php echo $class; ?>">
                <td class="check-icon"><?php echo $icon; ?></td>
                <td>
                    <?php echo htmlspecialchars($check['name']); ?>
                    <?php if (!empty($check['note'])): ?>
                        <br><small class="check-note"><?php echo htmlspecialchars($check['note']); ?></small>
                    <?php endif; ?>
                </td>
                <td class="check-value"><?php echo htmlspecialchars($check['value']); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p><small>Only the requirements above block the installation. A <strong>!</strong>, a <strong>?</strong> or a
    red security row does not: <strong>!</strong> records a protection this host does not offer, <strong>?</strong>
    a check this host would not let us run — and a check we could not run is never reported as passed. Each one
    says what it costs you, so you know it was a choice the server made and not a default we hid from you.</small></p>
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
    $configFile = DataDirectory::configPath(__DIR__);
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
            <button type="button" class="btn btn-secondary" id="testBtn">Test Connection</button>
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
    <?php
    // testConnection() lives in install.js, loaded once from renderPage() —
    // the panel's CSP (#250) blocks an inline <script> here just as it would
    // in the SPA.
}

function renderStep3(bool $isUpdate): void
{
    $updateParam = $isUpdate ? '&update=1' : '';
    ?>
    <h2>Step 3: Database Setup</h2>
    <p>Click the button below to create or update database tables.</p>
    <form method="post" action="?step=3<?php echo $updateParam; ?>">
        <input type="hidden" name="step" value="3">
        <button type="submit" class="btn" id="migrateBtn">
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
        <label>
            Instance name <small>(shown in the admin panel, Terminal, and authenticator app — you can change it later in Settings)</small>
            <input type="text" name="instance_name" maxlength="100" placeholder="Club Bar" autocomplete="organization">
        </label>
        <button type="submit" class="btn">Create Admin &amp; Finish</button>
    </form>
    <?php
}

function renderStep5(): void
{
    // Resolved live rather than remembered: .installer-data is deleted when the
    // install completes, and what matters on this page is where the data
    // actually ended up.
    $dataDir = DataDirectory::resolve(__DIR__);
    $outside = !str_starts_with($dataDir . '/', rtrim(__DIR__, '/') . '/');
    $configFile = DataDirectory::configPath(__DIR__);
    ?>
    <h2>Installation Complete!</h2>
    <p>Club Bar has been installed successfully. You can now log in with the admin account you just created.</p>

    <h3 style="margin: 20px 0 8px; font-size: 16px; color: #374151;">Where your data is kept</h3>
    <p><code><?php echo htmlspecialchars($dataDir); ?></code><br>
    <small>Configuration: <code><?php echo htmlspecialchars($configFile); ?></code></small></p>
    <?php if ($outside): ?>
        <p><small>This directory is <strong>outside your document root</strong>, so the database password, the
        scanned SEPA mandates and the logs cannot be requested over the web even if your hosting stops honouring
        <code>.htaccess</code>.</small></p>
    <?php else: ?>
        <p class="check-warn"><small><strong>Note:</strong> this hosting account has no writable directory above
        the document root, so your data stays inside it. It is protected by the <code>.htaccess</code> rules
        shipped with Club Bar — which your host may stop honouring after a tariff or server change. If you can
        create a writable directory next to your document root, re-run this installer to move the data there.</small></p>
    <?php endif; ?>

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
    overflow-wrap: anywhere;
}
.check-icon {
    width: 24px;
    text-align: center;
    font-size: 16px;
}
.check-ok .check-icon { color: #16a34a; }
.check-fail .check-icon { color: #dc2626; }
.check-warn .check-icon { color: #b45309; }
.check-ok { color: #1f2937; }
.check-fail { color: #dc2626; }
.check-warn { color: #b45309; }
.check-note { color: #92400e; font-size: 12px; }
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
