<?php
declare(strict_types=1);

// --- Locate the data directory ---
// config.php, storage/ (scanned SEPA mandates) and logs/ live wherever the
// installer could put them: above the document root on a host with a writable
// parent, inside it otherwise. The pointer file names the choice; without one
// this resolves to the in-document-root layout every older install has
// (#245, ADR-0031 decision 2).
require_once __DIR__ . '/backend/src/Shared/Config/DataDirectory.php';
// The config.php → $_ENV mapping, shared with backend/bin/cron.php: a CLI cron
// reads the same file with no front controller in front of it, and two copies
// of the mapping would drift on the first key somebody adds (ADR-0038, #403).
require_once __DIR__ . '/backend/src/Shared/Config/ConfigFile.php';
// CSP/HSTS from code (#250, #383, ADR-0031 decision 1): applied unconditionally,
// before routing, so the SPA branch below — which never reaches
// backend/bootstrap.php — carries both headers the same as the API branch does.
require_once __DIR__ . '/backend/src/Shared/Security/RuntimeHardening.php';
\App\Shared\Security\RuntimeHardening::applySecurityHeaders();

$dataDir    = \App\Shared\Config\DataDirectory::resolve(__DIR__);
$configFile = \App\Shared\Config\DataDirectory::configPath(__DIR__);

if (!file_exists($configFile)) {
    header('Location: /install.php');
    exit;
}

$config = require $configFile;

// Map config array to environment variables (Env class reads $_ENV)
\App\Shared\Config\ConfigFile::applyToEnvironment($config, $dataDir);

// --- Route request ---
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/api/')) {
    $app = require __DIR__ . '/backend/bootstrap.php';
    $app->run();
} else {
    // Fallback — serve SPA for client-side routing
    $spaIndex = __DIR__ . '/spa.html';
    if (file_exists($spaIndex)) {
        readfile($spaIndex);
    } else {
        http_response_code(404);
        echo 'Admin panel not found. Check that spa.html exists.';
    }
}
