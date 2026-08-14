<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Shared\Config\AppConfig;
use App\Shared\Config\Env;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Logging\Logger;
use App\Shared\Time\Utc;
use App\Db\MigrationRunner;
use App\Modules\BankCodes\Repositories\BankCodesRepository;
use App\Modules\BankCodes\Services\BankCodeService;

// This runs before bootstrap.php ever does on a fresh install, and it writes
// timestamps of its own (the lock file below, and whatever the migrations seed).
Utc::apply();

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    Env::load($envFile);
}

// --- Access control ---
$installKey  = Env::get('INSTALL_KEY', '');
$providedKey = $_SERVER['HTTP_X_INSTALL_KEY'] ?? '';

// Hash both values to equal length before comparing.
// This ensures hash_equals() always runs (no short-circuit) and both inputs
// are always 64 bytes (no length timing leak from differing string lengths).
$installHash = hash('sha256', $installKey);
$providedHash = hash('sha256', $providedKey);
$keyMatch = hash_equals($installHash, $providedHash);

// strlen intentional: enforces minimum byte length, not character count
$keyInvalid = ($installKey === '') || (strlen($installKey) < 16) || !$keyMatch;

if ($keyInvalid) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Forbidden']));
}

// --- Installed marker ---
// Written after the first successful migration run. Delete manually on the server
// to re-enable install.php (e.g. to apply new migrations after a deployment).
$installedMarker = __DIR__ . '/../storage/.installed';
if (file_exists($installedMarker)) {
    http_response_code(403);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Already installed. Delete storage/.installed to re-enable.']));
}

// --- Concurrency lock ---
$lockFile = __DIR__ . '/../storage/install.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
    http_response_code(429);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Install locked. Retry later.']));
}
file_put_contents($lockFile, date('c'));

// --- Run ---
$pdo = ConnectionFactory::create(
    Env::get('DB_HOST'),
    Env::get('DB_NAME'),
    Env::get('DB_USER'),
    Env::get('DB_PASS'),
);

$runner = new MigrationRunner($pdo);
$migrationsDir = __DIR__ . '/../db/migrations';
$action = $_GET['action'] ?? 'status';

header('Content-Type: application/json');

switch ($action) {
    case 'status':
        echo json_encode($runner->status($migrationsDir), JSON_PRETTY_PRINT);
        break;

    case 'migrate':
        $result = $runner->migrate(
            $migrationsDir,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ['storageDir' => (new AppConfig())->storageDir],
        );
        echo json_encode($result, JSON_PRETTY_PRINT);
        // Mark installation complete so install.php cannot be re-run without manual intervention
        file_put_contents($installedMarker, date('c'));
        break;

    case 'seed':
        $seedFile = __DIR__ . '/../db/seed.sql';
        if (!file_exists($seedFile)) {
            http_response_code(404);
            echo json_encode(['error' => 'seed.sql not found']);
            break;
        }
        $sql = file_get_contents($seedFile);
        try {
            $pdo->exec($sql);
            echo json_encode(['status' => 'ok', 'message' => 'Seed data applied']);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'import-bank-codes':
        // Import Bundesbank BLZ data.
        // Default: auto-downloads from Bundesbank (override URL via BUNDESBANK_BLZ_URL env var).
        // Alternative: provide file via upload (blz_file), JSON body (file_path), or query param.
        $blzFilePath = null;

        // Check for file upload
        $uploadedFiles = $_FILES['blz_file'] ?? null;
        if ($uploadedFiles && $uploadedFiles['error'] === UPLOAD_ERR_OK) {
            $blzFilePath = $uploadedFiles['tmp_name'];
        }

        // Check for JSON body with file_path
        if ($blzFilePath === null) {
            $body = json_decode(file_get_contents('php://input'), true);
            if (isset($body['file_path']) && file_exists($body['file_path'])) {
                $blzFilePath = $body['file_path'];
            }
        }

        // Check for query parameter
        if ($blzFilePath === null && isset($_GET['file_path']) && file_exists($_GET['file_path'])) {
            $blzFilePath = $_GET['file_path'];
        }

        $logDir = __DIR__ . '/../logs';
        $logger = new Logger($logDir, 'INFO', 'bank-import');
        $repository = new BankCodesRepository($pdo, $logger);
        $service = new BankCodeService($repository, $logger);

        try {
            if ($blzFilePath !== null) {
                // Import from provided file
                $result = $service->importFromFile($blzFilePath);
                $result['source'] = 'file';
            } else {
                // Auto-download from Bundesbank
                $url = $_GET['url'] ?? $body['url'] ?? null;
                $result = $service->downloadAndImport($url);
            }

            echo json_encode([
                'status' => 'ok',
                'source' => $result['source'] ?? 'file',
                'imported' => $result['imported'],
                'removed' => $result['removed'],
                'total' => $result['total'],
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Use ?action=status, ?action=migrate, ?action=seed, or ?action=import-bank-codes']);
}

@unlink($lockFile);
