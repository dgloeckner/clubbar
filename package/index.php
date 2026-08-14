<?php
declare(strict_types=1);

// --- Locate the data directory ---
// config.php, storage/ (scanned SEPA mandates) and logs/ live wherever the
// installer could put them: above the document root on a host with a writable
// parent, inside it otherwise. The pointer file names the choice; without one
// this resolves to the in-document-root layout every older install has
// (#245, ADR-0031 decision 2).
require_once __DIR__ . '/backend/src/Shared/Config/DataDirectory.php';

$dataDir    = \App\Shared\Config\DataDirectory::resolve(__DIR__);
$configFile = \App\Shared\Config\DataDirectory::configPath(__DIR__);

if (!file_exists($configFile)) {
    header('Location: /install.php');
    exit;
}

$config = require $configFile;

// The backend resolves storage/, logs/ and the session directory from this.
$_ENV['DATA_DIR'] = $dataDir;

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
// Optional override. Left unset, AppConfig derives the cookie's Secure flag
// from the app URL and the request scheme — see AppConfig::resolveSessionCookieSecure().
if (isset($config['session']['cookie_secure'])) {
    $_ENV['SESSION_COOKIE_SECURE'] = $config['session']['cookie_secure'] ? 'true' : 'false';
}
// Optional override. Left unset, sessions are written to the data directory's
// storage/sessions instead of the host's shared session directory — see
// RuntimeHardening (#246).
if (!empty($config['session']['save_path'])) {
    $_ENV['SESSION_SAVE_PATH'] = $config['session']['save_path'];
}
$_ENV['API_TOKEN_TTL_DAYS'] = (string) ($config['api_token']['ttl_days'] ?? 365);
$_ENV['TOTP_ENCRYPTION_KEY'] = $config['security']['totp_encryption_key'] ?? '';
$_ENV['IBAN_FINGERPRINT_KEY'] = $config['security']['iban_fingerprint_key'] ?? '';
$_ENV['LLM_PROVIDER']        = $config['llm']['provider'] ?? '';
$_ENV['LLM_API_KEY']         = $config['llm']['api_key'] ?? '';
$_ENV['LLM_MODEL']           = $config['llm']['model'] ?? '';
$_ENV['LLM_THINKING_BUDGET'] = (string) ($config['llm']['thinking_budget'] ?? 0);
$_ENV['GCLOUD_VISION_API']   = $config['vision']['api_key'] ?? '';

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
