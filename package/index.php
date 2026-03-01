<?php
declare(strict_types=1);

// --- Load config into environment ---
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    header('Location: /install.php');
    exit;
}

$config = require $configFile;

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
$_ENV['API_TOKEN_TTL_DAYS'] = (string) ($config['api_token']['ttl_days'] ?? 90);

// --- Route request ---
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/api/')) {
    $app = require __DIR__ . '/api/bootstrap.php';
    $app->run();
} else {
    // Fallback — .htaccess normally handles SPA routing
    $spaIndex = __DIR__ . '/assets/index.html';
    if (file_exists($spaIndex)) {
        readfile($spaIndex);
    } else {
        http_response_code(404);
        echo 'Admin panel not found. Check that assets/ directory exists.';
    }
}
