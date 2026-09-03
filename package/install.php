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
 * 5. Mail transport (optional; #710)
 * 6. Backups (optional; ADR-0049)
 * 7. Scheduler — the two cron lines, the AJAX "has it run yet" check, and the
 *    monitor URL that answers that question every day afterwards (#743)
 * 8. Done
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
require_once __DIR__ . '/backend/src/Shared/Config/ConfigWriter.php';
require_once __DIR__ . '/backend/src/Shared/Config/ConfigWriterException.php';
// Files an older release put in the document root and this one does not ship
// there any more, swept once migrations have run (#751).
require_once __DIR__ . '/backend/src/Shared/Config/RetiredFiles.php';

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
use App\Shared\Config\ConfigWriter;
use App\Shared\Config\ConfigWriterException;
use App\Shared\Config\RetiredFiles;
use App\Shared\Security\FileModes;
use App\Shared\Security\SecurityCheckContext;
use App\Shared\Security\SecurityFinding;
use App\Shared\Security\SecuritySelfCheck;
use App\Shared\Time\Utc;

// The commented template ConfigWriter substitutes values into, rather than
// generating `config.php` from scratch (#710).
//
// It ships *inside* `backend/` and not next to this file (#751). Every file in
// the document root is a URL, and this one is neither needed there nor ever
// requested by a browser: the installer reads it from disk. `backend/` is
// denied wholesale by the shipped `.htaccess`, which is the same reason
// `config.php` itself is allowed to fall back into that directory (ADR-0031
// decision 2) — and it puts the template beside the file it is a template for.
const INSTALLER_CONFIG_TEMPLATE = __DIR__ . '/backend/config.sample.php';

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

// --- Handle AJAX scheduler check (session-protected) ---
// The **Prüfen** button of step 5 (#405). It reads `cron_heartbeat` and answers
// whether a run has ever been observed — nothing here triggers a drain, because
// a self-triggered test call would only prove the endpoint answers, not that
// anything is scheduled to call it, which is the only thing the gate asks.
//
// The installer holds the database credentials it just wrote, so it asks the
// database directly rather than through an API that would need a session it
// does not have.
if (isset($_GET['action']) && $_GET['action'] === 'check_cron') {
    header('Content-Type: application/json');
    if (empty($_SESSION['install_key_verified'])) {
        echo json_encode(['verified' => false, 'error' => 'Not authenticated']);
        exit;
    }
    if (!file_exists($configFile)) {
        echo json_encode(['verified' => false, 'error' => 'config.php not found — complete step 2 first.']);
        exit;
    }
    try {
        $config = require $configFile;
        $checkPdo = new PDO(
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
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . Utc::SQL_OFFSET . "'",
            ]
        );
        $row = $checkPdo->query('SELECT last_run_at, source, php_version FROM cron_heartbeat WHERE id = 1')->fetch();
        $lastRunAt = $row['last_run_at'] ?? null;
        // Remembered in the session so the completion page can state the
        // outcome, verified or not. A finish without a green check is allowed —
        // the treasurer should not have to stare at the installer waiting for a
        // tick that may be fifteen minutes away — but it is recorded as an
        // explicit unverified state rather than a silent pass.
        $_SESSION['scheduler_verified'] = $lastRunAt !== null;
        echo json_encode([
            'verified' => $lastRunAt !== null,
            'last_run_at' => $lastRunAt,
            'source' => $row['source'] ?? null,
            'php_version' => $row['php_version'] ?? null,
        ]);
    } catch (\PDOException $e) {
        echo json_encode(['verified' => false, 'error' => $e->getMessage()]);
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

// Set only by a failed step-6 POST, so a mistake in one recipient row never
// costs the operator the rest of what they typed (#735) — the mail and DB
// steps have no equivalent because they have nothing this repeatable to lose.
$step6Repost = null;

// The same idea, one field wide: a rejected monitor URL is handed back to the
// screen rather than replaced by whatever config.php still says, so the
// operator can see the typo they made instead of retyping the whole URL (#743).
$step7Repost = null;

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
            $timeZone = trim($_POST['app_timezone'] ?? '');

            if (empty($dbName)) {
                $error = 'Database name is required.';
                break;
            }
            if (empty($dbUser)) {
                $error = 'Database username is required.';
                break;
            }
            // The one screen that can refuse a bad zone out loud. Everywhere
            // else the fallback is deliberately silent — a mail that arrives
            // with the wrong hour still reaches somebody, one that throws in
            // the builder reaches nobody — but here a human is present and
            // watching, so `Europe/Berlim` is a message rather than a club
            // reading its books an hour out for a year. ALL_WITH_BC, because a
            // config written when `Europe/Kiev` was current must survive a
            // re-run of this step unchanged.
            if ($timeZone === '' || !in_array($timeZone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                $error = 'Please choose the time zone your club keeps its books in.';
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
            // Authorises the URL drain trigger for hosting with no CLI cron
            // (ADR-0038 rule 3). Written unconditionally so the scheduler
            // instructions have something to show; an installation that uses
            // the CLI entrypoint simply never presents it.
            $cronSecret = bin2hex(random_bytes(32));

            // Preserve existing keys if re-running step 2 on an already-installed
            // instance — regenerating the fingerprint key would break bank-change
            // detection for every stored mandate (ADR-0036), and regenerating the
            // cron secret would silently break a scheduler that is already set up.
            if (file_exists($configFile)) {
                $existingConfig = require $configFile;
                if (!empty($existingConfig['security']['totp_encryption_key'])) {
                    $totpKey = $existingConfig['security']['totp_encryption_key'];
                }
                if (!empty($existingConfig['security']['iban_fingerprint_key'])) {
                    $ibanFingerprintKey = $existingConfig['security']['iban_fingerprint_key'];
                }
                if (!empty($existingConfig['cron']['secret'])) {
                    $cronSecret = $existingConfig['cron']['secret'];
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

                // Connection works — write config.
                //
                // Through ConfigWriter, which substitutes into the commented
                // template rather than generating the file from scratch. The
                // club therefore gets the same guidance config.sample.php
                // carries, including the two sections this screen does not ask
                // about — `mail` and `backup` — which are exactly the two
                // configured later. Before #710 this was var_export(), and a
                // club that used the installer never saw a single comment.
                //
                // Every value already in the file is carried across: re-running
                // this step, or reaching it from the backup step, must never
                // cost an installation something it is not being asked about.
                try {
                    $writer = new ConfigWriter(INSTALLER_CONFIG_TEMPLATE);
                    $writer->writeTo($configTarget, ConfigWriter::merge(
                        ConfigWriter::read($configFile),
                        [
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
                                // The scheme decides more than link building:
                                // AppConfig derives the session cookie's Secure
                                // flag from it.
                                'url' => (installerRequestIsHttps() ? 'https' : 'http')
                                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                                // Stated, never inferred. Written even when it
                                // equals the default, because `configured` and
                                // `default` are different answers: the admin
                                // dashboard warns about the second, and a club
                                // that picked Berlin on this screen has decided
                                // rather than defaulted (#365).
                                'timezone' => $timeZone,
                            ],
                            'security' => [
                                'totp_encryption_key' => $totpKey,
                                'iban_fingerprint_key' => $ibanFingerprintKey,
                            ],
                            'cron' => [
                                'secret' => $cronSecret,
                            ],
                        ]
                    ));
                } catch (ConfigWriterException $e) {
                    // Never a partial write: ConfigWriter refuses rather than
                    // producing a file that looks right and is missing a
                    // credential.
                    $error = $e->getMessage();
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
                    // The migrate step is the one screen every upgrade route
                    // passes through — `docs/deployment.md` sends a manual
                    // upgrade here rather than to `upgrade.php` — and an
                    // upgrade unpacked over an existing installation adds files
                    // without ever removing one. So this is where a file an
                    // older release put in the document root stops existing
                    // (#751).
                    RetiredFiles::sweep(__DIR__);

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
            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $password)) {
                $error = 'Password must contain at least one lowercase letter, one uppercase letter, and one digit.';
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

                // The first account holds `admin` — the root of ADR-0044's
                // ladder, and the only role that can hand out the others. The
                // migration backfills the accounts that exist when it runs;
                // this one is created afterwards, so it grants its own. Without
                // it the very first login lands on an account with no role at
                // all, which from #519 on can open nothing — including the page
                // that would fix it.
                $stmt = $pdo->prepare(
                    'INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)'
                );
                $stmt->execute([$id, 'admin']);

                // The panel audits every admin it creates (AdminUsersService::
                // createAdminUser) — the very first, highest-privilege account
                // must not be the one exception (#501). Composer's autoloader
                // is deliberately out of reach this early (see the file header),
                // so this writes the equivalent audit_log row directly rather
                // than pulling in AuditService/AuditLogRepository, mirroring
                // Pattern 016's shape and masking by hand: action=create,
                // entity_type=admin_user, password never in plaintext. There is
                // no session admin yet to attribute the row to — the installer
                // has no "current admin" the way a panel request does — so the
                // account is recorded as having created itself, which is what
                // actually happened.
                $stmt = $pdo->prepare(
                    'INSERT INTO audit_log (admin_user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                );
                $stmt->execute([
                    $id,
                    'create',
                    'admin_user',
                    $id,
                    null,
                    json_encode(['email' => $email, 'display_name' => $email, 'password' => '[INSTALLER]']),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]);

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

        case '5': // Mail transport (ADR-0038) — reachable after install via ?update=1
            if (!file_exists($configFile)) {
                $error = 'config.php not found. Please complete step 2 first.';
                break;
            }

            $mailDsn = trim($_POST['mail_dsn'] ?? '');
            $existing = ConfigWriter::read($configFile);

            if (($_POST['mail_clear'] ?? '') === '1') {
                // Turning mail off is the deliberate opposite of leaving the
                // field blank, and merge() cannot tell them apart: it reads a
                // blank answer as "unchanged", which is the rule that lets this
                // screen decline to echo an SMTP password back into the HTML.
                // So an erase removes the key here rather than blanking it
                // through merge, which would silently keep the old transport.
                unset($existing['mail']['dsn']);
                $mailDsn = '';
            } elseif ($mailDsn === '') {
                // Blank and not clearing: keep what is stored, the same rule the
                // backup step's client secret follows, for the same reason.
                $mailDsn = (string) ($existing['mail']['dsn'] ?? '');
            }

            // Refused here rather than at the first send. A transport that
            // cannot be parsed does not throw when mail is queued — the queue
            // fills and the drain fails, in a job nobody watches, which is the
            // failure mode ADR-0038 exists to avoid.
            if ($mailDsn !== '') {
                require_once __DIR__ . '/backend/vendor/autoload.php';

                try {
                    App\Shared\Mail\MailDsn::parse($mailDsn);
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                    break;
                }
            }

            try {
                $writer = new ConfigWriter(INSTALLER_CONFIG_TEMPLATE);
                $writer->writeTo($configTarget ?? $configFile, ConfigWriter::merge(
                    $existing,
                    ['mail' => ['dsn' => $mailDsn]]
                ));
            } catch (ConfigWriterException $e) {
                $error = $e->getMessage();
                break;
            }

            header('Location: ?step=6' . ($isUpdate ? '&update=1' : ''));
            exit;

        case '6': // Backups (ADR-0049) — reachable after install via ?update=1
            if (!file_exists($configFile)) {
                $error = 'config.php not found. Please complete step 2 first.';
                break;
            }

            // What the file already says, so a blank field can mean "unchanged"
            // rather than "erase this" — see the client-secret handling below.
            $existingBackup = ConfigWriter::read($configFile)['backup'] ?? [];

            // Repeating label/key rows (#735) rather than one freeform textarea
            // the operator hand-assembles "label:key" into. Assembled into that
            // format below, still the only shape BackupKeyring understands.
            $labelInputs = (array) ($_POST['recipient_label'] ?? []);
            $keyInputs = (array) ($_POST['recipient_key'] ?? []);
            $rowCount = max(count($labelInputs), count($keyInputs));
            $recipientRows = [];
            for ($i = 0; $i < $rowCount; $i++) {
                $recipientRows[] = [
                    'label' => trim((string) ($labelInputs[$i] ?? '')),
                    // Normalized the way a sloppy paste needs it — whitespace
                    // and newlines stripped, hex lowercased — the same rule
                    // applied client-side, so a value accepted there also
                    // survives here.
                    'key' => strtolower(preg_replace('/\s+/', '', (string) ($keyInputs[$i] ?? '')) ?? ''),
                ];
            }

            $backupDsn = trim($_POST['backup_dsn'] ?? '');
            $clientSecret = trim($_POST['backup_client_secret'] ?? '');
            $secretExpires = trim($_POST['backup_secret_expires_at'] ?? '');
            $backupHeartbeat = trim($_POST['backup_heartbeat_url'] ?? '');

            // Captured before any validation runs, and used on every failure
            // path below — a bad DSN must not cost the operator the recipient
            // rows any more than a bad recipient row should cost the DSN.
            $step6Repost = [
                'rows' => $recipientRows,
                'errorRowIndex' => null,
                'dsn' => $backupDsn,
                'expires' => $secretExpires,
                'heartbeat' => $backupHeartbeat,
            ];

            // Validated through the application's own parsers rather than a
            // second copy of the rules here. Both already refuse in sentences
            // written for the person reading this screen — BackupKeyring names
            // the malformed entry, BackupDsn names the missing part — and a
            // rule that lived in two places would eventually disagree with
            // itself.
            require_once __DIR__ . '/backend/vendor/autoload.php';

            // Fed to the parser one row at a time, cumulatively, rather than
            // all at once: the exception BackupKeyring already throws names
            // the malformed entry or the duplicate label, and doing it this
            // way tells us which *row* introduced it — a duplicate is only
            // detectable once the second occurrence joins the string.
            $recipientKeys = [];
            $accumulated = [];
            foreach ($recipientRows as $i => $row) {
                if ($row['label'] === '' && $row['key'] === '') {
                    continue; // an untouched row is not a mistake
                }

                $accumulated[] = $row['label'] . ':' . $row['key'];

                try {
                    (new App\Modules\Backups\Services\BackupKeyring())
                        ->parse(implode("\n", $accumulated));
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                    $step6Repost['errorRowIndex'] = $i;
                    break 2; // out of this foreach AND the switch — nothing here saves
                }

                $recipientKeys[] = end($accumulated);
            }

            if ($backupDsn !== '') {
                try {
                    App\Modules\Backups\Domain\BackupDsn::parse($backupDsn);
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                    break;
                }

                // The field is deliberately not pre-filled — a live credential
                // does not belong in an HTML value attribute — so blank means
                // "keep what is there", and only an installation with no secret
                // at all is refused. A DSN that cannot sign in produces a
                // nightly failure naming Microsoft rather than this screen.
                if ($clientSecret === '') {
                    $clientSecret = (string) ($existingBackup['client_secret'] ?? '');
                }

                if ($clientSecret === '') {
                    $error = 'A remote is configured but no client secret is stored. '
                        . 'The secret is shown once by scripts/setup-msgraph-backup.ps1 and '
                        . 'cannot be retrieved afterwards — mint a new one with -RotateSecretOnly.';
                    break;
                }
            }

            try {
                $writer = new ConfigWriter(INSTALLER_CONFIG_TEMPLATE);
                $writer->writeTo($configTarget ?? $configFile, ConfigWriter::merge(
                    ConfigWriter::read($configFile),
                    ['backup' => [
                        'recipient_public_keys' => $recipientKeys,
                        'dsn' => $backupDsn,
                        'client_secret' => $clientSecret,
                        'client_secret_expires_at' => $secretExpires,
                        'heartbeat_url' => $backupHeartbeat,
                    ]]
                ));
            } catch (ConfigWriterException $e) {
                $error = $e->getMessage();
                break;
            }

            header('Location: ?step=7' . ($isUpdate ? '&update=1' : ''));
            exit;

        case '7': // The drain's monitor (ADR-0038 rule 6) — reachable after
                  // install via ?update=1
            if (!file_exists($configFile)) {
                $error = 'config.php not found. Please complete step 2 first.';
                break;
            }

            // Rotating the URL trigger's secret (#744). A separate button in
            // the same step rather than a step of its own: it is one field of
            // `config.php`, it belongs beside the `curl` line that quotes it,
            // and on an install nobody is rotating anything — the secret step 2
            // wrote is simply shown for the first time.
            if (($_POST['action'] ?? '') === 'rotate_cron_secret') {
                $rotation = installerRotateCronSecret($configFile, $configTarget ?? $configFile);

                if ($rotation['secret'] === null) {
                    $error = $rotation['error'];
                    break;
                }

                // Through the session, not the query string: the secret must
                // not reach the access log the header form exists to keep it
                // out of. Shown once by renderStep7(), which unsets it.
                $_SESSION['installer_cron_secret'] = $rotation['secret'];
                $_SESSION['installer_cron_secret_warning'] = $rotation['error'];

                header('Location: ?step=7' . ($isUpdate ? '&update=1' : ''));
                exit;
            }

            $cronHeartbeat = trim($_POST['cron_heartbeat_url'] ?? '');

            // Refused here rather than at the first run, and this is the one
            // field on the wizard where a typo is *completely* silent: a
            // monitor URL that goes nowhere pings nowhere, and the club learns
            // it configured an alarm only when the outage it was meant to catch
            // has already run for a fortnight.
            if ($cronHeartbeat !== '') {
                $heartbeatError = installerHeartbeatUrlError($cronHeartbeat);
                if ($heartbeatError !== null) {
                    $error = $heartbeatError;
                    $step7Repost = $cronHeartbeat;
                    break;
                }
            }

            $existingCron = ConfigWriter::read($configFile);

            // A blank field here means erase, and merge() reads a blank answer
            // as "unchanged" — the rule that lets the mail and backup screens
            // decline to echo a secret back into the HTML. A monitor URL is not
            // a secret, so this screen *does* show what is stored, and an
            // operator who clears the field is asking for the alarm to go away
            // rather than leaving a question unanswered. So the key is removed
            // here rather than blanked through merge, which would keep the old
            // URL and report success.
            if ($cronHeartbeat === '') {
                unset($existingCron['cron']['heartbeat_url']);
            }

            try {
                $writer = new ConfigWriter(INSTALLER_CONFIG_TEMPLATE);
                $writer->writeTo($configTarget ?? $configFile, ConfigWriter::merge(
                    $existingCron,
                    ['cron' => ['heartbeat_url' => $cronHeartbeat]]
                ));
            } catch (ConfigWriterException $e) {
                $error = $e->getMessage();
                $step7Repost = $cronHeartbeat;
                break;
            }

            header('Location: ?step=8' . ($isUpdate ? '&update=1' : ''));
            exit;
    }
}

// --- Render page ---
renderPage($step, $error, $isUpdate, $step6Repost, $step7Repost);

// ============================================================================
// Functions
// ============================================================================

/**
 * What is wrong with a monitor's check URL, or null when nothing is.
 *
 * The alarm channel is the one setting on this wizard whose failure is
 * *completely* silent (ADR-0038 rule 6). A mistyped SMTP host produces failed
 * rows somebody can see; a mistyped check URL produces a monitor that was never
 * pinged and a club that believes it is being watched — and the first time that
 * is discovered is the outage the alarm existed to catch. So the shape is
 * checked before it is written, in the one place the operator is still looking
 * at the field.
 *
 * Deliberately shallow: a scheme and a host, nothing more. The wizard cannot
 * know whether the check exists, and refusing anything but a known vendor's
 * URL would lock out the self-hosted Uptime Kuma that ADR-0038 explicitly
 * allows for.
 */
function installerHeartbeatUrlError(string $url): ?string
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return 'That does not look like a check URL. Paste the monitor\'s full ping URL, '
            . 'starting with https:// — for example https://hc-ping.com/<uuid> — or leave '
            . 'the field empty to run without an alarm.';
    }

    return null;
}

/**
 * Mint a new URL-trigger secret and write it to `config.php` (#744).
 *
 * This is the *only* place a cron secret is generated after step 2, and since
 * #744 the only place at all: the admin panel used to mint one too (#473),
 * store its hash, and supersede this file entirely — which left the wizard
 * printing scheduler instructions for a secret the application no longer
 * accepted, and the panel reporting "not configured" over an installation
 * whose `config.php` had one from the first minute. One writer, one reader.
 *
 * Two things have to happen together for the returned secret to be true:
 *
 * 1. `config.php` gets the new value — through `ConfigWriter`, so the rest of
 *    the file survives untouched, the same way every other step writes.
 * 2. `mail_config.cron_secret_hash` is cleared. Only an installation that used
 *    the removed panel rotation has one, and while it is there it *wins* —
 *    handing an operator a secret that a stale row silently overrides is the
 *    exact failure this whole change is about.
 *
 * In that order, deliberately. If the write succeeds and the clear does not,
 * nothing has broken: the scheduler that was working carries on working, and
 * the caller is told the new secret is not live yet. The reverse order would
 * retire a working credential and then fail to publish its replacement.
 *
 * @return array{secret: ?string, error: ?string} `secret` null means nothing
 *         was written and `error` says why; a secret *with* an error means the
 *         file was written but an older panel-rotated secret is still in force.
 */
function installerRotateCronSecret(string $configFile, string $configTarget): array
{
    // Same scheme as step 2 and as the removed CronSecret::generate(): 256
    // bits, hex. Nothing here reads the old value — a rotation that needs to
    // know the secret it replaces is a rotation that can leak it.
    $secret = bin2hex(random_bytes(32));

    try {
        $writer = new ConfigWriter(INSTALLER_CONFIG_TEMPLATE);
        $writer->writeTo($configTarget, ConfigWriter::merge(
            ConfigWriter::read($configFile),
            ['cron' => ['secret' => $secret]]
        ));
    } catch (ConfigWriterException $e) {
        return ['secret' => null, 'error' => $e->getMessage()];
    }

    $config = require $configFile;

    try {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['db']['host'] ?? 'localhost',
                (int) ($config['db']['port'] ?? 3306),
                $config['db']['name'] ?? ''
            ),
            $config['db']['user'] ?? '',
            $config['db']['pass'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . Utc::SQL_OFFSET . "'",
            ]
        );
        $pdo->exec('UPDATE mail_config SET cron_secret_hash = NULL, cron_secret_rotated_at = NULL WHERE id = 1');
    } catch (\PDOException $e) {
        return [
            'secret' => $secret,
            'error' => 'The new secret is in config.php, but an older secret rotated from the admin panel '
                . 'could not be retired, so the URL trigger still checks that one: ' . $e->getMessage(),
        ];
    }

    return ['secret' => $secret, 'error' => null];
}

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

function renderPage(
    string $step,
    ?string $error,
    bool $isUpdate,
    ?array $step6Repost = null,
    ?string $step7Repost = null
): void
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
                <?php for ($i = 1; $i <= 8; $i++): ?>
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
                    renderStep5($isUpdate);
                    break;
                case '6':
                    renderStep6($isUpdate, $error, $step6Repost);
                    break;
                case '7':
                    renderStep7($isUpdate, $step7Repost);
                    break;
                case '8':
                    renderStep8();
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
    // Europe/Berlin unless this installation already stated something — the
    // same default the application falls back to, so the pre-selection never
    // moves a working install's clock. It is offered as a choice rather than
    // assumed: confirming it here is what makes the club's zone `configured`
    // instead of `default`, and silences a dashboard warning that exists
    // precisely because nothing on any screen looks wrong when the clock is.
    $timeZoneDefault = 'Europe/Berlin';
    $configFile = DataDirectory::configPath(__DIR__);
    if (file_exists($configFile)) {
        $config = require $configFile;
        if (isset($config['db'])) {
            $dbDefaults = array_merge($dbDefaults, $config['db']);
        }
        $stored = trim((string) ($config['app']['timezone'] ?? ''));
        if ($stored !== '') {
            $timeZoneDefault = $stored;
        }
    }
    // Listed without the backwards-compatible aliases, so the menu offers one
    // name per zone; a stored alias is still accepted on submit and is added
    // below so a re-run does not silently re-point it.
    $zones = DateTimeZone::listIdentifiers();
    if (!in_array($timeZoneDefault, $zones, true)) {
        $zones[] = $timeZoneDefault;
        sort($zones);
    }
    ?>
    <h2>Step 2: Database and the club's clock</h2>
    <p>
        Enter your MySQL/MariaDB database credentials, and the time zone this
        club keeps its books in.
    </p>
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

        <hr>

        <label for="app_timezone">
            Club time zone
            <select name="app_timezone" id="app_timezone" required>
                <?php foreach ($zones as $zone): ?>
                    <option value="<?php echo htmlspecialchars($zone, ENT_QUOTES); ?>"<?php
                        echo $zone === $timeZoneDefault ? ' selected' : ''; ?>><?php
                        echo htmlspecialchars($zone); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="hint">
            The clock your club's books are stated in. Every time is stored in
            UTC and converted back into this zone on every surface — the admin
            panel, the CSV exports, the day and hour buckets behind the reports,
            and the mails, which have no browser to do it for them. Pick the
            zone the <em>clubhouse</em> is in, not the one you are sitting in
            now: a wrong clock is invisible, because an hour that is two hours
            out looks exactly like an hour that is not.
        </p>

        <div class="btn-row">
            <button type="button" class="btn btn-secondary" id="testBtn">Test Connection</button>
            <span id="testResult"></span>
        </div>

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
            Password <small>(minimum 8 characters, with at least one lowercase letter, one uppercase letter, and one digit)</small>
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

/**
 * Step 7: the scheduler (#405), its secret (#744) and its alarm (#743).
 *
 * A prerequisite step rather than a suggestion. The drain is the only thing
 * that sends announcement emails, and until a run has been observed the admin
 * panel carries a banner and refuses to finalize a direct-debit settlement — so
 * this page exists to make the setup a step of the installation instead of a
 * paragraph in a manual somebody reads afterwards.
 *
 * It does not block. The first scheduled tick can be up to fifteen minutes
 * away, and holding the wizard on a spinner for that is not acceptable; the
 * outcome is recorded either way and repeated on the completion page.
 *
 * **And the check that survives the wizard.** The Check button below answers
 * one question once: has a run been seen *today*. What answers it every day
 * afterwards is `cron.heartbeat_url`, and until #743 the installer wrote every
 * other value of this section — the secret among them — and left that one to a
 * club hand-editing `config.php` on a live site. Which is the shape of the
 * failure it guards: nothing in this application reports a scheduler that
 * stopped, because the queue is the only sending path, so the report would go
 * out through the thing that died. It is asked for *here*, beside the job it
 * watches, rather than on the mail screen, because a monitor for a scheduler
 * nobody has scheduled yet is a check that is red from the minute it is made.
 *
 * **And the secret both URL triggers need.** Same argument, one screen later:
 * the two `curl` lines above are useless without it, and until #744 the only
 * way to see it was to open `config.php` over FTP — while a second button, in
 * the admin panel, could mint a *different* one that silently superseded this
 * file (#473), leaving the wizard printing instructions for a secret the
 * application no longer accepted. That is now the panel's former feature and
 * this screen's job, because this is where the secret is quoted and where an
 * operator is already pasting things into a hosting form. Generating one
 * prints it exactly once: `config.php` keeps the only copy afterwards, and
 * this page will not repeat it on a refresh.
 */
function renderStep7(bool $isUpdate, ?string $repost = null): void
{
    $cronCommand = 'php ' . rtrim(__DIR__, '/') . '/backend/bin/cron.php';

    // The URL trigger only exists where `cron.secret` does — absent it the
    // route answers 404, and printing the URL would be instructions for
    // something that is not there. Step 2 writes one unconditionally, so on a
    // fresh install this is present; an upgrade from before ADR-0038 may not
    // have it yet.
    $configFile = DataDirectory::configPath(__DIR__);
    $config = [];
    $cronSecret = null;
    $appUrl = null;
    if (file_exists($configFile)) {
        $config = require $configFile;
        $cronSecret = $config['cron']['secret'] ?? null;
        $appUrl = $config['app']['url'] ?? null;
    }
    $drainUrl = ($cronSecret && $appUrl) ? rtrim($appUrl, '/') . '/api/cron/drain' : null;

    // The backup's own entrypoint (ADR-0049). A second job rather than a branch
    // inside the first, because it is separately observed — and printed *here*,
    // beside the drain, because the two together are the whole of what a club
    // has to put into its panel. A volunteer who sets up one scheduled job in a
    // sitting and is told about the second in a manual sets up one job; the
    // epic's own risk table names "the backup cron is never added" as the thing
    // most likely to go wrong.
    $backupCommand = 'php ' . rtrim(__DIR__, '/') . '/backend/bin/backup.php';
    $backupUrl = ($cronSecret && $appUrl) ? rtrim($appUrl, '/') . '/api/cron/backup' : null;

    // A refused URL is shown back as it was typed, so the operator can see the
    // typo rather than the value it failed to replace. Otherwise: whatever is
    // configured, pre-filled — this one is not a secret, and an operator who
    // cannot see the current monitor cannot tell a working alarm from one
    // pointing at a check that was deleted last year.
    $heartbeatValue = $repost ?? (string) ($config['cron']['heartbeat_url'] ?? '');
    $updateParam = $isUpdate ? '&update=1' : '';

    // Shown once, then gone from the session — the same contract the panel's
    // rotate dialog used to carry, for the same reason: `config.php` is the
    // only copy, and this page will not print it again on a refresh.
    $issuedSecret = $_SESSION['installer_cron_secret'] ?? null;
    $issuedWarning = $_SESSION['installer_cron_secret_warning'] ?? null;
    unset($_SESSION['installer_cron_secret'], $_SESSION['installer_cron_secret_warning']);
    ?>
    <h2>Step 7: Schedule the two background jobs</h2>
    <p>Club Bar announces every direct debit by email at least seven days before it is collected. Those emails
    are queued when you finalize a collection and sent by a scheduled task — so <strong>until this task runs,
    Club Bar will not let you finalize a collection</strong>.</p>
    <p>The second job writes the nightly encrypted backup. It does not block anything, and nothing reminds you
    about it later — so it is worth pasting both while you are in the panel.</p>

    <h3 style="margin: 20px 0 8px; font-size: 16px; color: #374151;">Add this to your hosting panel&rsquo;s cron</h3>
    <p>Run it every <strong>15 minutes</strong>:</p>
    <pre id="cronCommand"><?php echo htmlspecialchars($cronCommand); ?></pre>

    <?php if ($drainUrl !== null): ?>
        <p><small>No CLI cron on your tariff? Schedule a URL fetch instead, sending the secret from
        <code>config.php</code> as a header:</small></p>
        <pre><?php echo htmlspecialchars("curl -sS -H 'X-Cron-Secret: <secret>' " . $drainUrl); ?></pre>
        <p><small>The header form keeps the secret out of your webserver&rsquo;s access log. Where a panel cannot
        send headers, <code><?php echo htmlspecialchars($drainUrl); ?>?secret=&lt;secret&gt;</code> also works,
        and is a degraded fallback.</small></p>
    <?php endif; ?>

    <h3 style="margin: 28px 0 8px; font-size: 16px; color: #374151;">And this one, for the nightly backup</h3>
    <p>Run it once a day, at a quiet hour (<code>0 3 * * *</code>):</p>
    <pre id="backupCommand"><?php echo htmlspecialchars($backupCommand); ?></pre>

    <?php if ($backupUrl !== null): ?>
        <p><small>Again, a URL fetch where your tariff has no CLI cron — the same secret:</small></p>
        <pre><?php echo htmlspecialchars("curl -sS -H 'X-Cron-Secret: <secret>' " . $backupUrl); ?></pre>
    <?php endif; ?>

    <p><small><strong>This job writes nothing yet, and that is expected.</strong> A backup is sealed to keys
    generated on your own machine, so it cannot be set up from here: put at least one public key into
    <code>backup.recipient_public_keys</code> in <code>config.php</code> and the nightly archives start —
    configuring a key is what switches backups on. Until then this job says so and exits quietly. The
    deployment guide walks through generating the keypairs.</small></p>

    <h3 style="margin: 28px 0 8px; font-size: 16px; color: #374151;">The secret those two URLs need</h3>
    <p><small>It is <code>cron.secret</code> in <code>config.php</code><?php if ($cronSecret !== null): ?>, written when
    you set up the database<?php endif; ?> — the URL trigger checks that and nothing else. Read it out of the file, or
    generate a new one here and copy it straight into your scheduler. <strong>Generating replaces the old value
    immediately</strong>, so a URL fetch you have already scheduled stops working until you paste the new secret into it.
    A job that uses the CLI command above is unaffected: it needs no secret at all.</small></p>

    <?php if ($issuedSecret !== null): ?>
        <p class="check-warn"><small><strong>Copy this now — it is not shown again.</strong> The installer prints it
        once; after that it is only in <code>config.php</code>.</small></p>
        <pre id="cronSecretValue"><?php echo htmlspecialchars($issuedSecret); ?></pre>
        <?php if ($issuedWarning !== null): ?>
            <div class="error"><?php echo htmlspecialchars($issuedWarning); ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="POST" action="?step=7<?= $updateParam ?>">
        <input type="hidden" name="step" value="7">
        <input type="hidden" name="action" value="rotate_cron_secret">
        <button type="submit" class="btn btn-secondary"><?= $cronSecret === null ? 'Generate a secret' : 'Generate a new secret' ?></button>
    </form>

    <p style="margin-top: 20px;">Once you have saved them, wait for the first drain run and check here. The first
    tick can be up to 15 minutes away — you can finish the installation now and check again from the admin panel,
    which shows the same instructions until a run has been seen.</p>
    <button type="button" class="btn btn-secondary" id="cronCheckBtn">Check</button>
    <p id="cronCheckResult"></p>

    <h3 style="margin: 28px 0 8px; font-size: 16px; color: #374151;">Get told when the mail job stops</h3>
    <p>The check above answers for today. A scheduled job that dies six months from now announces nothing, breaks
    nothing visibly, and reports nothing &mdash; Club Bar cannot email you about it, because email is the thing
    that stopped. So the drain reports to a monitor <em>outside</em> this installation, which raises the alarm
    when a report fails to arrive.</p>
    <p><small>Create a check on any push monitor &mdash; healthchecks.io, Uptime Kuma, Cronitor, Better Stack
    &mdash; and paste its ping URL. Recommended settings: <strong>period 1 day, grace 1&ndash;2 hours</strong>.
    The announcement is queued at finalize and collected at least seven days later, so a one-day alarm still
    leaves six days to react, while a tighter one fires on every single missed tick.</small></p>

    <form method="POST" action="?step=7<?= $updateParam ?>">
        <input type="hidden" name="step" value="7">

        <label for="cron_heartbeat_url">Mail monitor URL (optional)</label>
        <input type="text" id="cron_heartbeat_url" name="cron_heartbeat_url"
               autocomplete="off" spellcheck="false"
               value="<?= htmlspecialchars($heartbeatValue, ENT_QUOTES) ?>"
               placeholder="https://hc-ping.com/&hellip;">
        <p class="hint">
            Each run pings <code>&lt;url&gt;/start</code> when it begins and <code>&lt;url&gt;</code> when it
            finishes; an unusable transport, or a message left sitting for three ticks, pings
            <code>&lt;url&gt;/fail</code>. A single rejected address does not &mdash; an alarm that fires on
            every typo&rsquo;d address is one that gets switched off. The ping carries counts only: never an
            address, never a name. Clear the field to switch the alarm off again.
        </p>

        <div class="actions">
            <button type="submit" class="btn">Save and finish</button>
            <a class="btn-link" href="?step=8<?= $updateParam ?>">Finish without a monitor</a>
        </div>
    </form>
    <?php
}

/**
 * Step 5 — the mail transport, and why it is here rather than in the panel.
 *
 * Everything about a mail that is *not* secret — sender name, reply-to, footer,
 * header style, drain batch size and budget — is configured under Settings →
 * Mail, in the database, by whoever runs the club. `mail.dsn` is the one piece
 * that is not: it carries an SMTP password, so it lives in `config.php` beside
 * the database password (ADR-0038).
 *
 * That split left a gap this screen closes. The installer wrote six of the
 * eight sections and omitted `mail` and `backup` — the two a club configures
 * *later* — so the answer to "how do I switch mail on?" was "hand-edit a PHP
 * file on a live site". The same question, and the same answer, that #710 came
 * from.
 *
 * Skippable: mail off is a legitimate state. Nothing is sent, nothing throws,
 * and the security self-check reports the transport as unconfigured rather than
 * pretending it works.
 */
function renderStep5(bool $isUpdate): void
{
    // Resolved here rather than passed in: renderPage() does not have it, and a
    // screen that reads config.php is the only thing that needs it. Same as the
    // schedule screen below.
    $stored = trim((string) (ConfigWriter::read(DataDirectory::configPath(__DIR__))['mail']['dsn'] ?? ''));
    $updateParam = $isUpdate ? '&update=1' : '';

    // Shown redacted rather than not at all. Unlike the backup client secret,
    // a DSN's useful half — scheme, host, port, user — is not the secret half,
    // and an operator who cannot see what is configured cannot tell a working
    // transport from a typo they are about to re-enter.
    $summary = null;
    if ($stored !== '') {
        require_once __DIR__ . '/backend/vendor/autoload.php';

        try {
            $summary = App\Shared\Mail\MailDsn::parse($stored)->redacted();
        } catch (\Throwable) {
            $summary = App\Shared\Mail\MailDsn::redactValue($stored) . ' — which this build cannot parse';
        }
    }
    ?>
    <h2>Step 5: Sending mail</h2>

    <p>
        Club Bar sends Deckel statements, credential mails and notices to the
        board. <strong>This step is optional</strong> — leave it empty and no
        mail is sent, which is a normal state, not a failure. You can come back
        to this screen at any time.
    </p>

    <p>
        One line selects the transport. Everything else about a mail — the
        sender name, the reply-to, the footer, the logo — is configured later
        under <em>Settings → Mail</em> in the admin panel. Only this line is
        here, because it carries a password.
    </p>

    <table>
        <tr>
            <td><code>smtp://user:password@mail.example.org:587</code></td>
            <td>the club's own mailbox — what most clubs want</td>
        </tr>
        <tr>
            <td><code>native://default</code></td>
            <td>hand off to the host's local mail server</td>
        </tr>
        <tr>
            <td><code>null://null</code></td>
            <td>configured, and silently discards — for a test install</td>
        </tr>
    </table>

    <?php if ($summary !== null): ?>
    <p class="hint">Currently configured: <code><?= htmlspecialchars($summary, ENT_QUOTES) ?></code></p>
    <?php endif; ?>

    <form method="POST" action="?step=5<?= $updateParam ?>">
        <input type="hidden" name="step" value="5">

        <label for="mail_dsn">Mail DSN</label>
        <input type="text" id="mail_dsn" name="mail_dsn"
               autocomplete="off" spellcheck="false"
               placeholder="<?= $stored !== ''
                   ? 'stored — leave blank to keep it'
                   : 'smtp://user:password@mail.example.org:587' ?>">
        <p class="hint">
            Never shown back in full: the password in it does not belong in a
            page a browser may cache or a password manager may offer to save.
            Leave it blank to keep what is stored.
            <?php if ($stored !== ''): ?>
            To turn mail off entirely, tick the box below.
            <?php endif; ?>
        </p>

        <?php if ($stored !== ''): ?>
        <label style="font-weight:normal">
            <input type="checkbox" name="mail_clear" value="1">
            Remove the stored transport and stop sending mail
        </label>
        <?php endif; ?>

        <p class="hint">
            Sending also needs a scheduler: the queue is drained by cron and by
            nothing else. Step 7 shows the command for this installation.
        </p>

        <div class="actions">
            <button type="submit" class="btn">Save and continue</button>
            <a class="btn-link" href="?step=6<?= $updateParam ?>">Skip for now</a>
        </div>
    </form>
    <?php
}

/**
 * Step 6 — Backups (ADR-0049), and the reason this screen exists.
 *
 * Before #710 the installer wrote six of the eight config sections, and the two
 * it omitted were `mail` and `backup` — exactly the two a club configures
 * *later*. So "how do I add backup credentials after install?" had one answer:
 * hand-edit `config.php`, a file where a missing comma is a fatal error on a
 * live site, with no template in front of you.
 *
 * Reached during a fresh install, and afterwards through `?update=1` — which is
 * the half that matters, because a club sets backups up in the week it thinks
 * about backups, not in the hour it installs.
 *
 * **Skippable on purpose.** Configuring a recipient key is what switches
 * backups on (ADR-0049 decision 2); leaving this screen empty leaves them off,
 * which is a legitimate state and not a nightly failure.
 *
 * The keypair is generated **offline**, from a local copy of
 * `tools/keypair-generator.html` — never from this server, which refuses to
 * serve that file at all (#733). The private half is shown once, in that
 * browser, and never posted back. That is the whole security property of
 * this feature: the server produces archives it structurally cannot read,
 * which is only true if the private half never reaches it — including via a
 * server-hosted copy of the tool that made it.
 *
 * Recipient rows post as `recipient_label[]` / `recipient_key[]` — repeating
 * label/key pairs assembled into `label:key` below, rather than one freeform
 * textarea the operator hand-assembles that syntax into (#735). A row that
 * fails validation is attributed by index (`$step6Repost['errorRowIndex']`
 * at the call site) and every row, plus the DSN/expiry/heartbeat fields, is
 * preserved on the re-render — a malformed second key must not cost the
 * operator a correctly-pasted first one.
 */
function renderStep6(bool $isUpdate, ?string $error = null, ?array $repost = null): void
{
    require_once __DIR__ . '/backend/vendor/autoload.php';

    $existing = ConfigWriter::read(DataDirectory::configPath(__DIR__));
    $backup = $existing['backup'] ?? [];
    $updateParam = $isUpdate ? '&update=1' : '';

    $errorRowIndex = $repost['errorRowIndex'] ?? null;
    // A fingerprint is only meaningful for a key that is actually saved — on a
    // failed POST nothing has been written yet, so there is nothing to show
    // beside a row still being corrected.
    $fingerprints = [];

    if ($repost !== null) {
        $rows = $repost['rows'];
        $dsnValue = $repost['dsn'];
        $expiresValue = $repost['expires'];
        $heartbeatValue = $repost['heartbeat'];
    } else {
        $rows = [];
        foreach ((array) ($backup['recipient_public_keys'] ?? []) as $line) {
            [$label, $key] = array_pad(explode(':', (string) $line, 2), 2, '');
            $rows[] = ['label' => $label, 'key' => $key];
            if (preg_match('/^[0-9a-f]{64}$/', $key) === 1) {
                $fingerprints[array_key_last($rows)] =
                    (new App\Modules\Backups\Domain\BackupRecipient($label, $key))->fingerprint();
            }
        }
        $dsnValue = (string) ($backup['dsn'] ?? '');
        $expiresValue = (string) ($backup['client_secret_expires_at'] ?? '');
        $heartbeatValue = (string) ($backup['heartbeat_url'] ?? '');
    }

    // Two rows by default, even with nothing saved yet — the recommendation to
    // hold two keys belongs in the shape of the form, not only in a paragraph
    // above it (#735). Still submits fine with just one filled in.
    while (count($rows) < 2) {
        $rows[] = ['label' => '', 'key' => ''];
    }
    ?>
    <h2>Backups</h2>

    <p>
        Club Bar can write a nightly encrypted archive of the database and push it
        to storage the club owns. <strong>This step is optional</strong> — leave it
        empty and no backups are written, which is a normal state, not a failure.
        You can come back to this screen at any time.
    </p>

    <h3>1. Who can open an archive</h3>

    <p>
        Archives are sealed to one or more public keys. The matching
        <strong>private</strong> halves never touch this server — that is what
        makes an archive something the server can produce and cannot read.
    </p>

    <p>
        Make <strong>two</strong> keypairs — one for whoever holds the server, one
        for a second board member. The realistic failure in a club is not a broken
        cipher; it is one volunteer leaving with the only key.
    </p>

    <p class="hint">
        Generate them <strong>offline</strong>, from your own copy of the
        downloaded package — never from this server. Extract
        <code>tools/keypair-generator.html</code> and open it directly in your
        browser (a <code>file://</code> page, no server involved); this server
        refuses to serve it, because a compromised host could otherwise hand out
        a modified generator that steals the private half it just showed you.
        Paste the <strong>hex</strong> output under <em>Backup archive keys</em>
        below — the base64 boxes above it on that page are the IBAN keypair the
        admin panel registers, and the two are not interchangeable.
    </p>

    <form method="POST" action="?step=6<?= $updateParam ?>" id="backupForm">
        <input type="hidden" name="step" value="6">

        <div id="recipient-rows">
            <?php foreach ($rows as $i => $row): ?>
            <div class="recipient-row<?= $errorRowIndex === $i ? ' recipient-row-error' : '' ?>">
                <div class="recipient-row-fields">
                    <div class="recipient-field recipient-field-label">
                        <label for="recipient_label_<?= $i ?>">Label</label>
                        <input type="text" id="recipient_label_<?= $i ?>" name="recipient_label[]"
                               value="<?= htmlspecialchars($row['label'], ENT_QUOTES) ?>"
                               placeholder="admin" maxlength="64" autocomplete="off">
                    </div>
                    <div class="recipient-field recipient-field-key">
                        <label for="recipient_key_<?= $i ?>">Public key (hex)</label>
                        <input type="text" id="recipient_key_<?= $i ?>" name="recipient_key[]"
                               class="recipient-key-input"
                               value="<?= htmlspecialchars($row['key'], ENT_QUOTES) ?>"
                               placeholder="64 hex characters" autocomplete="off" spellcheck="false">
                    </div>
                    <button type="button" class="recipient-remove" aria-label="Remove this recipient"
                            <?= count($rows) <= 1 ? 'hidden' : '' ?>>&times;</button>
                </div>
                <?php if ($errorRowIndex === $i && $error): ?>
                    <p class="error-inline" data-role="feedback"><?= htmlspecialchars($error) ?></p>
                <?php elseif (isset($fingerprints[$i])): ?>
                    <p class="recipient-key-feedback muted" data-role="feedback">
                        Fingerprint: <code><?= htmlspecialchars($fingerprints[$i]) ?></code>
                    </p>
                <?php else: ?>
                    <p class="recipient-key-feedback muted" data-role="feedback"></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="hint">
            Label: letters, digits, hyphens and underscores, up to 64 characters —
            it is what the offline decryptor prints to tell a holder which
            envelope in the safe to fetch. Key: the 64-character hex string from
            the generator's <em>Backup archive keys</em> section.
        </p>

        <p>
            <button type="button" id="add-recipient-row" class="btn-secondary">Add another recipient</button>
        </p>

        <h3>2. Where archives are pushed <span style="font-weight:normal">(optional)</span></h3>

        <p>
            Leave empty and archives stay on this webspace. That undoes a mistake
            from an hour ago and nothing else: one lost hosting account takes the
            database and its backups together. To push them off the host, run
            <code>scripts/setup-msgraph-backup.ps1</code> and paste what it prints.
            The procedure is in <code>docs/m365-backup-target.md</code>.
        </p>

        <label for="backup_dsn">Backup DSN</label>
        <input type="text" id="backup_dsn" name="backup_dsn"
               value="<?= htmlspecialchars($dsnValue, ENT_QUOTES) ?>"
               placeholder="msgraph://tenant/client@drive/driveid/clubbar">

        <label for="backup_client_secret">Client secret</label>
        <input type="password" id="backup_client_secret" name="backup_client_secret"
               autocomplete="new-password"
               placeholder="<?= ($backup['client_secret'] ?? '') !== ''
                   ? 'stored — leave blank to keep it'
                   : '' ?>">
        <p class="hint">
            Never shown back: a live credential does not belong in a page that a
            browser may cache or a password manager may offer to save. Leave it
            blank to keep the secret already stored.
        </p>

        <label for="backup_secret_expires_at">
            Client secret expires on — <strong>the most likely cause of a silent
            backup failure</strong>
        </label>
        <input type="date" id="backup_secret_expires_at" name="backup_secret_expires_at"
               value="<?= htmlspecialchars($expiresValue, ENT_QUOTES) ?>">
        <p class="hint">
            Microsoft warns nobody when a client secret expires. The nightly job
            keeps writing and sealing its archive; only the half that gets it off
            this server stops. With this date set, the run warns 90, 30 and 7 days
            ahead. Put a calendar reminder in as well.
        </p>

        <label for="backup_heartbeat_url">Backup monitor URL (optional)</label>
        <input type="text" id="backup_heartbeat_url" name="backup_heartbeat_url"
               value="<?= htmlspecialchars($heartbeatValue, ENT_QUOTES) ?>"
               placeholder="https://hc-ping.com/…">

        <div class="actions">
            <button type="submit" class="btn">Save and continue</button>
            <a class="btn-link" href="?step=7<?= $updateParam ?>">Skip for now</a>
        </div>
    </form>
    <?php
}

function renderStep8(): void
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

    <h3 style="margin: 20px 0 8px; font-size: 16px; color: #374151;">Scheduled mail drain</h3>
    <?php if (!empty($_SESSION['scheduler_verified'])): ?>
        <p class="check-ok"><small>A scheduled run has been observed. Announcement emails will go out, and
        collections can be finalized.</small></p>
    <?php else: ?>
        <p class="check-warn"><small><strong>Not yet verified.</strong> No run of the mail drain has been seen on
        this installation. That is expected if you have only just added the cron job — the first tick can be up
        to 15 minutes away. Until one has been recorded, the admin panel shows the setup instructions and
        finalizing a collection is refused, because the announcement it promises could not be sent.</small></p>
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
input[type="number"],
select {
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
input:focus,
select:focus {
    outline: none;
    border-color: #1a56db;
    box-shadow: 0 0 0 3px rgba(26,86,219,0.1);
}
/* Explanatory text under a field. Used since the mail step and never styled,
   so it read as body copy and competed with the question above it. */
.hint {
    margin-top: -8px;
    margin-bottom: 16px;
    font-size: 13px;
    color: #6b7280;
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
pre {
    background: #f3f4f6;
    padding: 10px 12px;
    border-radius: 4px;
    font-size: 13px;
    /* A command line is meant to be selected and pasted whole; wrapping keeps
       the tail of a long document-root path on screen instead of behind a
       horizontal scrollbar. */
    white-space: pre-wrap;
    word-break: break-all;
    margin: 8px 0;
}
.reset-link {
    text-align: center;
    color: #9ca3af;
    margin-top: 4px;
    margin-bottom: 0;
}
.reset-link a { color: #9ca3af; }
.muted { color: #6b7280; }
.recipient-row {
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 12px;
}
.recipient-row-error {
    border-color: #dc2626;
    background: #fef2f2;
}
.recipient-row-fields {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.recipient-field { flex: 1 1 200px; }
.recipient-field label { margin-bottom: 4px; }
.recipient-field input { margin-top: 0; }
.recipient-remove {
    flex: 0 0 auto;
    background: none;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    width: 38px;
    height: 38px;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    color: #6b7280;
}
.recipient-remove:hover { color: #dc2626; border-color: #dc2626; }
.recipient-key-feedback {
    font-size: 12px;
    margin: 6px 0 0;
    min-height: 14px;
}
#add-recipient-row { margin-bottom: 4px; }
@media (max-width: 640px) {
    .container { margin: 20px auto; }
    .card { padding: 20px; }
    h1 { font-size: 24px; }
}
CSS;
}
