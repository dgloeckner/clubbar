<?php

declare(strict_types=1);

/**
 * Club Bar Deploy Runner
 *
 * Uploaded by CI on each deployment alongside a .deploy-secret file.
 * Runs pending database migrations, returns JSON, then self-destructs.
 *
 * Usage: GET /deploy.php?key=<IONOS_DEPLOY_SECRET>
 * Returns: {"ok": true, "results": [...]} or {"ok": false, "error": "..."}
 *
 * Security:
 * - hash_equals() prevents timing attacks on key comparison
 * - Returns 403 immediately if .deploy-secret is missing or key is wrong
 * - Registers shutdown cleanup ONLY after successful key validation
 * - Self-destructs unconditionally after validation succeeds
 */

header('Content-Type: application/json');

$secretFile = __DIR__ . '/.deploy-secret';
$configFile = __DIR__ . '/config.php';
$scriptPath = __FILE__;

// Validate key before doing anything else
$providedKey = (string) ($_GET['key'] ?? '');

if (!file_exists($secretFile)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No deploy secret on server.']);
    exit;
}

$storedKey = trim((string) file_get_contents($secretFile));

if ($storedKey === '' || !hash_equals($storedKey, $providedKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid deploy key.']);
    exit;
}

// Key is valid — schedule cleanup of both files regardless of what happens next
register_shutdown_function(function () use ($secretFile, $scriptPath): void {
    @unlink($secretFile);
    @unlink($scriptPath);
});

// Load config
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config.php not found. Run the installer first.']);
    exit;
}

$config = require $configFile;

// Load autoloader
$autoload = __DIR__ . '/backend/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'backend/vendor/autoload.php not found.']);
    exit;
}

require $autoload;

// Run migrations
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
            PDO::ATTR_ERRMODE        => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $runner  = new \App\Db\MigrationRunner($pdo);
    $results = $runner->migrate(__DIR__ . '/backend/db/migrations', 'deploy');

    $failed = array_filter($results, fn($r) => ($r['status'] ?? '') === 'FAIL');

    if ($failed) {
        http_response_code(500);
        echo json_encode([
            'ok'      => false,
            'error'   => 'Migration failed.',
            'results' => array_values($results),
        ]);
        exit;
    }

    echo json_encode(['ok' => true, 'results' => array_values($results)]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
