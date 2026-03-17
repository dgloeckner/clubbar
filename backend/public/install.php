<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Shared\Config\Env;
use App\Db\MigrationRunner;

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
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST'), Env::get('DB_NAME')),
    Env::get('DB_USER'),
    Env::get('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
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
        $result = $runner->migrate($migrationsDir, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
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

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Use ?action=status, ?action=migrate, or ?action=seed']);
}

@unlink($lockFile);
