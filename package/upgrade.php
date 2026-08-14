<?php

declare(strict_types=1);

/**
 * Club Bar Upgrade Wizard
 *
 * Provides two interfaces:
 *
 * 1. **Web wizard** (browser) — admin-friendly step-by-step upgrade:
 *    - Key gate (paste upgrade secret from .upgrade-secret file)
 *    - Upload ZIP package (with version validation — no downgrades)
 *    - Extract & clean up stale files
 *    - Run database migrations
 *    - Done
 *
 * 2. **API mode** (CI/CD) — JSON endpoints for automated deployments:
 *    - GET/POST ?key=<secret>&action=extract  → extract uploaded ZIP
 *    - GET/POST ?key=<secret>&action=migrate  → run migrations, self-destruct
 *
 * Security:
 * - .upgrade-secret file holds the one-time key (uploaded by CI or generated on first access)
 * - hash_equals() prevents timing attacks
 * - Self-destructs .upgrade-secret and upgrade.php after successful migration
 */

// Required by path — this script runs before Composer's autoloader exists.
//
// On the very first deploy that carries this require (or any install whose
// backend/src/Shared/ predates it), neither class exists on disk yet: CI
// uploads upgrade.php on its own, ahead of the package it will extract, so
// there is nothing to require. bootstrapSharedClass() pulls each file out of
// the already-uploaded .upgrade-package.zip before requiring it — the same
// ZIP `action=extract` unpacks moments later — so this script can get itself
// off the ground without duplicating either class's logic here.
bootstrapSharedClass(__DIR__, __DIR__ . '/.upgrade-package.zip', 'backend/src/Shared/Config/DataDirectory.php');
bootstrapSharedClass(__DIR__, __DIR__ . '/.upgrade-package.zip', 'backend/src/Shared/Security/FileModes.php');
bootstrapSharedClass(__DIR__, __DIR__ . '/.upgrade-package.zip', 'backend/src/Shared/Time/Utc.php');

require_once __DIR__ . '/backend/src/Shared/Config/DataDirectory.php';
require_once __DIR__ . '/backend/src/Shared/Security/FileModes.php';
// Club Bar keeps every instant in UTC (#365); migrations run from here.
require_once __DIR__ . '/backend/src/Shared/Time/Utc.php';

use App\Shared\Config\DataDirectory;
use App\Shared\Security\FileModes;
use App\Shared\Time\Utc;

// This script never goes through bootstrap.php, so it pins its own clock.
Utc::apply();

function bootstrapSharedClass(string $documentRoot, string $zipFile, string $relativePath): void
{
    $target = $documentRoot . '/' . $relativePath;
    if (file_exists($target) || !file_exists($zipFile)) {
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) === true) {
        $zip->extractTo($documentRoot, $relativePath);
        $zip->close();
    }
}

$secretFile = __DIR__ . '/.upgrade-secret';
// Wherever the installation actually keeps it: the data directory on an
// install that has been moved above the document root, next to index.php on
// one that predates #245.
$configFile = DataDirectory::configPath(__DIR__);
$zipFile    = __DIR__ . '/.upgrade-package.zip';
$scriptPath = __FILE__;

// --- Detect mode: API (has ?action= or ?key= without session) vs Web wizard ---
$apiAction = $_GET['action'] ?? null;
$apiKey    = $_GET['key'] ?? null;

if ($apiAction !== null || ($apiKey !== null && !isset($_GET['step']))) {
    handleApiMode($secretFile, $configFile, $zipFile, $scriptPath);
    exit;
}

// --- Web wizard mode ---
// The wizard session carries upgrade_key_verified; over plain HTTP it would
// travel in clear text. Secure is set only when the request actually arrived
// over TLS — otherwise the browser would drop the cookie and the wizard would
// be unusable on a plain-HTTP host.
session_set_cookie_params([
    'path'     => '/',
    'secure'   => upgradeRequestIsHttps(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Handle reset
if (isset($_GET['action']) && $_GET['action'] === 'reset') {
    session_destroy();
    header('Location: upgrade.php');
    exit;
}

// --- Deploy secret gate ---
// If .upgrade-secret doesn't exist yet, generate one (same pattern as install.php)
if (!file_exists($secretFile)) {
    $secret = bin2hex(random_bytes(16));
    file_put_contents($secretFile, $secret);
}

if (empty($_SESSION['upgrade_key_verified'])) {
    $keyError = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade_key'])) {
        $provided = trim($_POST['upgrade_key']);
        $stored   = trim((string) file_get_contents($secretFile));
        if ($stored !== '' && hash_equals($stored, $provided)) {
            $_SESSION['upgrade_key_verified'] = true;
        } else {
            $keyError = 'Invalid upgrade key. Check the contents of .upgrade-secret on your server.';
        }
    }

    if (empty($_SESSION['upgrade_key_verified'])) {
        renderKeyGate($keyError);
        exit;
    }
}

// --- Step routing ---
$step  = $_GET['step'] ?? ($_POST['step'] ?? '1');
$error = null;
$result = null;

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case '1': // Upload ZIP
            if (!isset($_FILES['package']) || $_FILES['package']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize (' . ini_get('upload_max_filesize') . ').',
                    UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE    => 'No file was selected.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk.',
                ];
                $code = $_FILES['package']['error'] ?? UPLOAD_ERR_NO_FILE;
                $error = $uploadErrors[$code] ?? 'Upload failed (error code ' . $code . ').';
                break;
            }

            // Validate it's a ZIP
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['package']['tmp_name']);
            if (!in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])) {
                $error = 'Please upload a .zip file (got: ' . htmlspecialchars($mime) . ').';
                break;
            }

            // Verify it's a valid ZIP
            $testZip = new ZipArchive();
            if ($testZip->open($_FILES['package']['tmp_name']) !== true) {
                $error = 'The uploaded file is not a valid ZIP archive.';
                break;
            }
            $testZip->close();

            // Version check — prevent downgrades
            $currentVersion = readPackageVersion(__DIR__);
            $packageVersion = readZipPackageVersion($_FILES['package']['tmp_name']);
            $versionResult  = compareVersions($currentVersion, $packageVersion);

            if ($versionResult === 'downgrade') {
                $error = 'Downgrade not allowed. Installed version is '
                    . htmlspecialchars($currentVersion)
                    . ', but the uploaded package is '
                    . htmlspecialchars($packageVersion) . '.';
                break;
            }
            if ($versionResult === 'same') {
                $error = 'The uploaded package (' . htmlspecialchars($packageVersion)
                    . ') is the same version as the installed one. No upgrade needed.';
                break;
            }

            // Store version info in session for display
            $_SESSION['version_info'] = [
                'current' => $currentVersion,
                'package' => $packageVersion,
                'result'  => $versionResult,
            ];

            if (!move_uploaded_file($_FILES['package']['tmp_name'], $zipFile)) {
                $error = 'Failed to save uploaded file. Check directory permissions.';
                break;
            }

            header('Location: ?step=2');
            exit;

        case '2': // Extract
            $result = extractPackage($zipFile, __DIR__);
            if ($result['ok']) {
                $_SESSION['extract_result'] = $result;
                header('Location: ?step=3');
                exit;
            } else {
                $error = $result['error'];
            }
            break;

        case '3': // Migrate
            $result = runMigrations($configFile, __DIR__);
            if ($result['ok']) {
                hardenFileModes();
                $_SESSION['migration_result'] = $result;
                // Clean up deploy secret (self-destruct like CI mode)
                @unlink($secretFile);
                header('Location: ?step=4');
                exit;
            } else {
                $error = $result['error'];
            }
            break;

        case '4': // Data placement — always chosen, never applied implicitly
            $placement = $_POST['placement'] ?? '';

            if ($placement === 'relocate') {
                $probe = DataDirectory::probe(__DIR__);
                if (!$probe['outside']) {
                    $error = $probe['reason'];
                    break;
                }
                $moveResult = DataDirectory::relocate(__DIR__, $probe['path']);
            } elseif ($placement === 'revert') {
                $moveResult = DataDirectory::relocate(__DIR__, DataDirectory::inDocumentRoot(__DIR__));
                if ($moveResult['ok']) {
                    DataDirectory::removePointer(__DIR__);
                }
            } else {
                header('Location: ?step=5');
                exit;
            }

            if (!$moveResult['ok']) {
                $error = 'Could not move the data: ' . $moveResult['error']
                    . ' Nothing was deleted — the installation is still running from its previous location.';
                break;
            }

            hardenFileModes();
            $_SESSION['placement_result'] = ['moved' => $moveResult['moved'], 'to' => DataDirectory::resolve(__DIR__)];
            header('Location: ?step=5');
            exit;
    }
}

// --- Render ---
renderPage($step, $error);

// ============================================================================
// API Mode (CI/CD — JSON responses)
// ============================================================================

/**
 * True when the browser reached this script over TLS — directly, or through a
 * reverse proxy that terminated it (common on shared hosting).
 */
function upgradeRequestIsHttps(): bool
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

function handleApiMode(string $secretFile, string $configFile, string $zipFile, string $scriptPath): void
{
    header('Content-Type: application/json');

    $providedKey = (string) ($_GET['key'] ?? '');
    $action      = (string) ($_GET['action'] ?? 'migrate');

    // Validate key
    if (!file_exists($secretFile)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No deploy secret on server.']);
        return;
    }

    $storedKey = trim((string) file_get_contents($secretFile));
    if ($storedKey === '' || !hash_equals($storedKey, $providedKey)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid upgrade key.']);
        return;
    }

    // Schedule cleanup only for migrate (extract still needs the secret)
    if ($action !== 'extract') {
        register_shutdown_function(function () use ($secretFile, $scriptPath): void {
            @unlink($secretFile);
            @unlink($scriptPath);
        });
    }

    if ($action === 'extract') {
        // Version check (skip with ?force=1 for CI)
        if (empty($_GET['force'])) {
            $baseDir = dirname($scriptPath);
            $currentVersion = readPackageVersion($baseDir);
            $packageVersion = readZipPackageVersion($zipFile);
            $versionResult  = compareVersions($currentVersion, $packageVersion);

            if ($versionResult === 'downgrade') {
                http_response_code(409);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Downgrade not allowed.',
                    'current_version' => $currentVersion,
                    'package_version' => $packageVersion,
                ]);
                return;
            }
        }

        $result = extractPackage($zipFile, dirname($scriptPath));
        http_response_code($result['ok'] ? 200 : 500);
        echo json_encode($result);
        return;
    }

    // Default: migrate
    $result = runMigrations($configFile, dirname($scriptPath));
    if ($result['ok']) {
        hardenFileModes();
    }
    http_response_code($result['ok'] ? 200 : 500);
    echo json_encode($result);
}

/**
 * Bring an existing installation's file modes down to what this host tolerates
 * (#248, ADR-0031 decision 4).
 *
 * The upgrade is the only moment this can happen for an installation that was
 * unpacked from a package built before decision 4 landed: the `0777` on
 * `storage/`, `logs/` and the document root is inside the *installation*, not
 * inside the new ZIP, and extraction deliberately leaves the first two alone so
 * a member's mandate survives the upgrade. Silent by design — a host that
 * refuses is reported by the security self-check in the admin panel (#247),
 * where there is somewhere to say it and a remedy to attach; refusing to finish
 * an upgrade over a permission bit would be the worse trade.
 */
function hardenFileModes(): void
{
    FileModes::hardenData(
        DataDirectory::resolve(__DIR__),
        DataDirectory::configPath(__DIR__),
        DataDirectory::SUBDIRECTORIES,
        __DIR__
    );
}

// ============================================================================
// Core Logic (shared between API and wizard)
// ============================================================================

function readPackageVersion(string $dir): ?string
{
    // Try package.json first (new format)
    $metaFile = $dir . '/package.json';
    if (file_exists($metaFile)) {
        $meta = json_decode((string) file_get_contents($metaFile), true);
        if (is_array($meta) && isset($meta['version'])) {
            return $meta['version'];
        }
    }
    // Fallback to backend/VERSION
    $versionFile = $dir . '/backend/VERSION';
    if (file_exists($versionFile)) {
        return trim((string) file_get_contents($versionFile));
    }
    return null;
}

function readZipPackageVersion(string $zipFile): ?string
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        return null;
    }

    // Try package.json first
    $metaJson = $zip->getFromName('package.json');
    if ($metaJson !== false) {
        $meta = json_decode($metaJson, true);
        if (is_array($meta) && isset($meta['version'])) {
            $zip->close();
            return $meta['version'];
        }
    }

    // Fallback to backend/VERSION
    $version = $zip->getFromName('backend/VERSION');
    $zip->close();
    return $version !== false ? trim($version) : null;
}

/**
 * Compare versions. Returns:
 *  - 'upgrade' if package is newer
 *  - 'same' if identical
 *  - 'downgrade' if package is older
 *  - 'unknown' if either version is missing or non-comparable (e.g. dev builds)
 */
function compareVersions(?string $current, ?string $package): string
{
    if ($current === null || $package === null) {
        return 'unknown';
    }
    // Dev builds (e.g. "dev-abc123") can't be meaningfully compared
    if (str_starts_with($current, 'dev') || str_starts_with($package, 'dev')) {
        return 'unknown';
    }
    $cmp = version_compare($package, $current);
    if ($cmp > 0) return 'upgrade';
    if ($cmp === 0) return 'same';
    return 'downgrade';
}

function extractPackage(string $zipFile, string $extractDir): array
{
    if (!file_exists($zipFile)) {
        return ['ok' => false, 'error' => '.upgrade-package.zip not found on server.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        return ['ok' => false, 'error' => 'Failed to open ZIP archive.'];
    }

    // These belong to the installation, not to the package. Losing the pointer
    // would leave the application looking for its config in the document root
    // and finding none — an upgrade that ends at the install wizard (#245).
    // `backend/config.php` is listed defensively: no layout writes it today,
    // and the sweep below deletes anything the package does not ship.
    $excluded         = [
        'config.php', 'backend/config.php', DataDirectory::POINTER_FILE,
        '.installer-data', '.upgrade-secret',
    ];
    $preservedPrefixes = ['backend/storage', 'backend/logs'];
    $extracted  = 0;
    $skipped    = 0;
    $packageFiles = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $packageFiles[$name] = true;

        if (in_array($name, $excluded, true)) {
            $skipped++;
            continue;
        }

        $preserve = false;
        foreach ($preservedPrefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $preserve = true;
                break;
            }
        }
        if ($preserve) {
            $skipped++;
            continue;
        }

        $zip->extractTo($extractDir, $name);
        $extracted++;
    }

    $zip->close();
    @unlink($zipFile);

    // Remove stale files not in the package
    $deleted = 0;
    $protectedPrefixes = array_merge($preservedPrefixes, ['python_libs']);
    $protectedFiles = array_merge($excluded, [
        '.upgrade-package.zip', '.upgrade-secret', '.htaccess',
        'upgrade.php', 'install.php', 'config.sample.php',
    ]);

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iter as $fileInfo) {
        $fullPath     = $fileInfo->getPathname();
        $relativePath = ltrim(substr($fullPath, strlen($extractDir)), '/');

        if (in_array($relativePath, $protectedFiles, true)) {
            continue;
        }

        $inProtected = false;
        foreach ($protectedPrefixes as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                $inProtected = true;
                break;
            }
        }
        if ($inProtected) {
            continue;
        }

        if ($fileInfo->isDir()) {
            if (!(new \FilesystemIterator($fullPath))->valid()) {
                @rmdir($fullPath);
            }
        } elseif (!isset($packageFiles[$relativePath])) {
            @unlink($fullPath);
            $deleted++;
        }
    }

    return [
        'ok'        => true,
        'action'    => 'extract',
        'extracted' => $extracted,
        'skipped'   => $skipped,
        'deleted'   => $deleted,
    ];
}

/**
 * Backfill security.iban_fingerprint_key into an existing config.php.
 *
 * A mangled config bricks the whole install, so the rewrite is atomic:
 * write a sibling temp file, require it back to prove it parses to the same
 * structure, then rename over the original and re-narrow the mode. Returns an
 * error message, or null when the key exists or was written successfully.
 */
function ensureIbanFingerprintKey(string $configFile, array &$config): ?string
{
    if (!empty($config['security']['iban_fingerprint_key'])) {
        return null;
    }

    if (!is_writable($configFile) || !is_writable(dirname($configFile))) {
        return 'config.php needs a new security key (iban_fingerprint_key) but is not writable. '
            . 'Make it writable for this upgrade, or add the key manually: openssl rand -hex 32';
    }

    $config['security']['iban_fingerprint_key'] = bin2hex(random_bytes(32));

    $tmpFile = $configFile . '.tmp';
    $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";

    if (file_put_contents($tmpFile, $content) === false) {
        return 'Failed to write the updated config.php (temp file). Check directory permissions.';
    }

    $reread = @require $tmpFile;
    if (!is_array($reread) || ($reread['security']['iban_fingerprint_key'] ?? null) !== $config['security']['iban_fingerprint_key']) {
        @unlink($tmpFile);
        return 'The rewritten config.php failed verification; the original was left untouched.';
    }

    if (!rename($tmpFile, $configFile)) {
        @unlink($tmpFile);
        return 'Failed to replace config.php with the updated version. The original was left untouched.';
    }

    \App\Shared\Security\FileModes::narrowConfigFile($configFile);

    return null;
}

function runMigrations(string $configFile, string $scriptDir): array
{
    if (!file_exists($configFile)) {
        return ['ok' => false, 'error' => 'config.php not found. Run the installer first.'];
    }

    $config = require $configFile;
    if (!is_array($config) || !isset($config['db'])) {
        return ['ok' => false, 'error' => 'config.php returned unexpected value.'];
    }

    // Anchored to $scriptDir (where the package was unpacked), not to
    // dirname($configFile): once the data directory has been relocated above
    // the document root (ADR-0031 decision 2), config.php lives there instead,
    // and 'backend/db/migrations' next to it does not exist. Using that path
    // silently found zero migration files on every relocated install and
    // reported success without ever applying 017-020.
    $autoload = $scriptDir . '/backend/vendor/autoload.php';
    if (!file_exists($autoload)) {
        return ['ok' => false, 'error' => 'backend/vendor/autoload.php not found.'];
    }

    require_once $autoload;

    // Existing installs predate the IBAN fingerprint key (ADR-0036): install.php
    // only writes config.php on a fresh install, so the upgrade path has to
    // backfill it. This must happen — and be able to fail — BEFORE migrations
    // run: shipping the encryption release with an install that cannot store
    // the key would leave every subsequent IBAN write broken.
    $keyError = ensureIbanFingerprintKey($configFile, $config);
    if ($keyError !== null) {
        return ['ok' => false, 'error' => $keyError];
    }

    try {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['db']['host'],
                (int) ($config['db']['port'] ?? 3306),
                $config['db']['name']
            ),
            $config['db']['user'],
            $config['db']['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // NOW() is resolved by the server in the session zone, so the
                // migrations below would otherwise stamp rows in the host's zone.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '" . Utc::SQL_OFFSET . "'",
            ]
        );

        // The migrations directory is code, not data — anchored to $scriptDir
        // (where the package was unpacked) like the autoload require above,
        // never to dirname($configFile) (#434). storageDir instead uses
        // DataDirectory::resolve(), which is deliberately not that shortcut:
        // it is data, and it moves independently of $scriptDir once relocated.
        $runner  = new \App\Db\MigrationRunner($pdo);
        $results = $runner->migrate(
            $scriptDir . '/backend/db/migrations',
            'deploy',
            ['storageDir' => DataDirectory::resolve($scriptDir) . '/storage'],
        );

        $failed = array_filter($results, fn($r) => ($r['status'] ?? '') === 'FAIL');

        if ($failed) {
            return [
                'ok'      => false,
                'error'   => 'Migration failed.',
                'results' => array_values($results),
            ];
        }

        return ['ok' => true, 'results' => array_values($results)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ============================================================================
// Web Wizard Rendering
// ============================================================================

function renderKeyGate(?string $error): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Club Bar - Upgrade</title>
        <style><?php echo getStyles(); ?></style>
    </head>
    <body>
        <div class="container">
            <h1>Club Bar</h1>
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <div class="card">
                <h2>Upgrade Key Required</h2>
                <p>A upgrade key has been written to <code>.upgrade-secret</code> in your document root.</p>
                <p>Open the file via <strong>FTP</strong>, <strong>cPanel File Manager</strong>, or <strong>SSH</strong> and copy its contents, then paste below.</p>
                <form method="post" action="upgrade.php">
                    <label>
                        Upgrade Key
                        <input type="text" name="upgrade_key" required autofocus autocomplete="off"
                               placeholder="Paste the contents of .upgrade-secret">
                    </label>
                    <button type="submit" class="btn">Verify &amp; Continue</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function renderPage(string $step, ?string $error): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Club Bar - Upgrade</title>
        <style><?php echo getStyles(); ?></style>
    </head>
    <body>
        <div class="container">
            <h1>Club Bar Upgrade</h1>

            <div class="steps">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="step <?php echo $i == (int)$step ? 'active' : ($i < (int)$step ? 'done' : ''); ?>">
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
                case '1': renderStep1(); break;
                case '2': renderStep2(); break;
                case '3': renderStep3(); break;
                case '4': renderStep4(); break;
                case '5': renderStep5(); break;
                default:  renderStep1(); break;
            }
            ?>
            </div>
            <p class="reset-link"><small><a href="?action=reset">Start over</a></small></p>
        </div>
    </body>
    </html>
    <?php
}

function renderStep1(): void
{
    $maxUpload = ini_get('upload_max_filesize');
    $maxPost   = ini_get('post_max_size');
    $hasZip    = extension_loaded('zip');
    ?>
    <h2>Step 1: Upload Package</h2>
    <p>Upload the Club Bar release <code>.zip</code> file you downloaded from GitHub.</p>

    <?php if (!$hasZip): ?>
        <div class="error">The PHP <code>zip</code> extension is not loaded. Contact your hosting provider to enable it.</div>
    <?php else: ?>
        <form method="post" action="?step=1" enctype="multipart/form-data" id="uploadForm">
            <input type="hidden" name="step" value="1">
            <div class="upload-area" id="dropZone">
                <div class="upload-icon">&#128230;</div>
                <p><strong>Drag &amp; drop</strong> your .zip file here, or click to browse</p>
                <p class="upload-hint">Max upload size: <?php echo htmlspecialchars($maxUpload); ?> (upload_max_filesize), <?php echo htmlspecialchars($maxPost); ?> (post_max_size)</p>
                <input type="file" name="package" accept=".zip" required id="fileInput" class="file-input">
            </div>
            <div id="fileInfo" class="file-info" style="display:none"></div>
            <button type="submit" class="btn" id="uploadBtn" disabled>Upload &amp; Continue</button>
        </form>
        <script>
        (function() {
            var dropZone = document.getElementById('dropZone');
            var fileInput = document.getElementById('fileInput');
            var fileInfo = document.getElementById('fileInfo');
            var uploadBtn = document.getElementById('uploadBtn');
            var form = document.getElementById('uploadForm');

            dropZone.addEventListener('click', function() { fileInput.click(); });

            dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                dropZone.classList.add('drag-over');
            });
            dropZone.addEventListener('dragleave', function() {
                dropZone.classList.remove('drag-over');
            });
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                dropZone.classList.remove('drag-over');
                if (e.dataTransfer.files.length > 0) {
                    fileInput.files = e.dataTransfer.files;
                    showFile(e.dataTransfer.files[0]);
                }
            });

            fileInput.addEventListener('change', function() {
                if (fileInput.files.length > 0) showFile(fileInput.files[0]);
            });

            function showFile(file) {
                var sizeMB = (file.size / 1024 / 1024).toFixed(1);
                fileInfo.innerHTML = '&#128196; <strong>' + file.name + '</strong> (' + sizeMB + ' MB)';
                fileInfo.style.display = 'block';
                uploadBtn.disabled = false;
            }

            form.addEventListener('submit', function() {
                uploadBtn.disabled = true;
                uploadBtn.textContent = 'Uploading...';
            });
        })();
        </script>
    <?php endif; ?>
    <?php
}

function renderStep2(): void
{
    $versionInfo = $_SESSION['version_info'] ?? null;
    ?>
    <h2>Step 2: Extract Package</h2>
    <?php if ($versionInfo && $versionInfo['current'] && $versionInfo['package']): ?>
        <div class="version-info">
            <strong><?php echo htmlspecialchars($versionInfo['current']); ?></strong>
            &#8594;
            <strong><?php echo htmlspecialchars($versionInfo['package']); ?></strong>
        </div>
    <?php elseif ($versionInfo && $versionInfo['package']): ?>
        <div class="version-info">
            Installing version <strong><?php echo htmlspecialchars($versionInfo['package']); ?></strong>
        </div>
    <?php endif; ?>
    <p>The package has been uploaded. Click below to extract it and update all files.</p>
    <p class="hint">Your <code>config.php</code>, database, logs, and storage will be preserved.</p>
    <form method="post" action="?step=2">
        <input type="hidden" name="step" value="2">
        <button type="submit" class="btn" id="extractBtn"
                onclick="this.disabled=true;this.textContent='Extracting...';this.form.submit();">
            Extract &amp; Update Files
        </button>
    </form>
    <?php
}

function renderStep3(): void
{
    $result = $_SESSION['extract_result'] ?? null;
    ?>
    <h2>Step 3: Database Migrations</h2>
    <?php if ($result): ?>
        <div class="success">
            Files updated: <strong><?php echo $result['extracted']; ?></strong> extracted,
            <strong><?php echo $result['skipped']; ?></strong> preserved,
            <strong><?php echo $result['deleted']; ?></strong> stale files removed.
        </div>
    <?php endif; ?>
    <p>Click below to run any pending database migrations.</p>
    <form method="post" action="?step=3">
        <input type="hidden" name="step" value="3">
        <button type="submit" class="btn" id="migrateBtn"
                onclick="this.disabled=true;this.textContent='Running migrations...';this.form.submit();">
            Run Migrations
        </button>
    </form>
    <?php
}

/**
 * Where this installation keeps its data — offered, never applied on its own.
 *
 * An upgrade that quietly moved the database credentials and every scanned
 * mandate to a new path would be indistinguishable from a broken one if it went
 * wrong, on a host the person running it cannot inspect. So the move is a
 * button with the destination written on it, and the reverse is another button.
 */
function renderStep4(): void
{
    $result = $_SESSION['migration_result'] ?? null;
    $migrations = $result['results'] ?? [];

    $current = DataDirectory::resolve(__DIR__);
    $inside  = str_starts_with($current . '/', rtrim(__DIR__, '/') . '/');
    $probe   = DataDirectory::probe(__DIR__);
    ?>
    <h2>Step 4: Data Placement</h2>
    <?php if ($migrations): ?>
        <div class="success"><?php echo count($migrations); ?> migration(s) applied successfully.</div>
    <?php else: ?>
        <div class="success">Database is up to date — no migrations needed.</div>
    <?php endif; ?>

    <p>Your <code>config.php</code>, the scanned SEPA mandates and the logs are currently in:</p>
    <p><code><?php echo htmlspecialchars($current); ?></code></p>

    <?php if ($inside && $probe['outside']): ?>
        <p>This is <strong>inside your document root</strong>. Only the <code>.htaccess</code> rules keep those
        files off the web, and a hosting change can stop them being honoured without warning.</p>
        <p>This server has a writable directory above the document root, so they can be moved out of reach:</p>
        <form method="post" action="?step=4">
            <input type="hidden" name="step" value="4">
            <input type="hidden" name="placement" value="relocate">
            <button type="submit" class="btn"
                    onclick="this.disabled=true;this.textContent='Moving...';this.form.submit();">
                Move to <?php echo htmlspecialchars($probe['path']); ?>
            </button>
        </form>
        <p><small>The files are copied first and only removed once the copies are in place. If anything fails,
        nothing is deleted and the installation keeps running from where it is now.</small></p>
    <?php elseif ($inside): ?>
        <p class="check-warn">This is <strong>inside your document root</strong>, and this hosting account has no
        writable directory above it — <?php echo htmlspecialchars($probe['reason']); ?> The
        <code>.htaccess</code> rules shipped with Club Bar are what keep these files off the web here.</p>
    <?php else: ?>
        <p>This is <strong>outside your document root</strong>, where the webserver cannot reach it. Nothing to do.</p>
        <form method="post" action="?step=4">
            <input type="hidden" name="step" value="4">
            <input type="hidden" name="placement" value="revert">
            <button type="submit" class="btn btn-secondary">Move back into the document root</button>
        </form>
        <p><small>Only needed if something about the new location is not working — it undoes the move exactly.</small></p>
    <?php endif; ?>

    <hr>
    <a href="?step=5" class="btn <?php echo $inside && $probe['outside'] ? 'btn-secondary' : ''; ?>">
        <?php echo $inside && $probe['outside'] ? 'Skip &amp; Finish' : 'Finish'; ?>
    </a>
    <?php
}

function renderStep5(): void
{
    $placement = $_SESSION['placement_result'] ?? null;
    ?>
    <h2>Deploy Complete!</h2>
    <?php if ($placement): ?>
        <div class="success">
            Data moved to <code><?php echo htmlspecialchars($placement['to']); ?></code>
            (<?php echo htmlspecialchars(implode(', ', $placement['moved']) ?: 'nothing to move'); ?>).
        </div>
    <?php endif; ?>
    <p>Club Bar has been updated successfully.</p>
    <a href="/" class="btn">Go to Admin Panel</a>
    <?php
}

// ============================================================================
// Styles (matches install.php design)
// ============================================================================

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
.hint {
    font-size: 13px;
    color: #6b7280;
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
input[type="text"],
input[type="password"] {
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
.btn:hover { background: #1e40af; }
.btn:disabled { opacity: 0.6; cursor: not-allowed; }
.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #dc2626;
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 14px;
}
.success {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #16a34a;
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 14px;
}
.check-warn {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #b45309;
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 14px;
}
.upload-area {
    border: 2px dashed #d1d5db;
    border-radius: 8px;
    padding: 32px 20px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    margin-bottom: 16px;
}
.upload-area:hover, .upload-area.drag-over {
    border-color: #1a56db;
    background: #eff6ff;
}
.upload-icon {
    font-size: 36px;
    margin-bottom: 8px;
}
.upload-hint {
    font-size: 12px;
    color: #9ca3af;
}
.file-input {
    display: none;
}
.file-info {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #16a34a;
    padding: 10px 14px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 14px;
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
.version-info {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1a56db;
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 16px;
    text-align: center;
}
.reset-link a { color: #9ca3af; }
@media (max-width: 640px) {
    .container { margin: 20px auto; }
    .card { padding: 20px; }
    h1 { font-size: 24px; }
}
CSS;
}
