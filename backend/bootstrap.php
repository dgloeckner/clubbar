<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Shared\Config\Env;
use App\Shared\Config\AppConfig;
use App\Shared\Logging\Logger;
use App\ServiceFactory;
use Slim\Factory\AppFactory;

// Load environment
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    Env::load($envFile);
}

// Build core dependencies
$config = new AppConfig();
$logger = new Logger($config->logDir, $config->debug ? 'DEBUG' : 'INFO');

// Configure session lifetime and cookie parameters globally before any session_start()
ini_set('session.gc_maxlifetime', (string) $config->sessionMaxAge);
session_set_cookie_params([
    'lifetime' => $config->sessionMaxAge,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST'), Env::get('DB_NAME')),
    Env::get('DB_USER'),
    Env::get('DB_PASS'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

// Wire the service factory (DI container)
$factory = new ServiceFactory($pdo, $config, $logger);

// Create Slim app with our container
AppFactory::setContainer($factory);
$app = AppFactory::create();

// Optional base path for shared hosting (set by package front controller)
if (!empty($GLOBALS['__SLIM_BASE_PATH'])) {
    $app->setBasePath($GLOBALS['__SLIM_BASE_PATH']);
}

// Add routing middleware first
$app->addRoutingMiddleware();

// Global middleware (outer to inner execution order)
// Error handler MUST be the outermost middleware to catch routing errors
$app->add($factory->getErrorHandler());

// OAS contract validation for terminal API paths — active in test environment only.
// Slim 4 adds middleware in FIFO order (first added = outermost = first to handle request).
// Placing this AFTER getErrorHandler() means it executes inside the error handler:
// ErrorHandler → TerminalOasValidator → JsonBodyParser → CorsMiddleware → route handler
// So validation exceptions are caught and formatted by ErrorHandler.
if (getenv('APP_ENV') === 'test') {
    $app->add($factory->getTerminalOasValidator());
}

$app->add($factory->getJsonBodyParser());
$app->add($factory->getCorsMiddleware());

// Register routes
$routes = require __DIR__ . '/src/routes.php';
$routes($app);

return $app;
