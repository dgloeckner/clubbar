<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Shared\Config\Env;
use App\Shared\Config\AppConfig;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Logging\Logger;
use App\Shared\Security\RuntimeHardening;
use App\Shared\Time\Utc;
use App\ServiceFactory;
use Slim\Factory\AppFactory;

// Before anything reads the clock — the Logger below names its file after the
// current date, and every repository timestamps with date(). On a host whose
// php.ini is set to a local zone, those values would be written as local time
// into columns the API labels as UTC (#365).
Utc::apply();

// Load environment
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    Env::load($envFile);
}

$config = new AppConfig();

// Runtime hardening first: it decides whether the failure of anything below
// this line — the database connection especially, which is outside Slim's error
// handler — reaches the browser as a stack trace (#246, ADR-0031 decision 1).
// It also owns every session directive, set here because PHP refuses them once
// a session has started.
$hardeningWarnings = RuntimeHardening::apply($config);

$logger = new Logger($config->logDir, $config->debug ? 'DEBUG' : 'INFO');

// A directive the host would not allow is a real gap in the deployment, not a
// detail — it belongs in the log where an admin can find it after the fact.
foreach ($hardeningWarnings as $warning) {
    $logger->warning($warning);
}

$pdo = ConnectionFactory::create(
    Env::get('DB_HOST'),
    Env::get('DB_NAME'),
    Env::get('DB_USER'),
    Env::get('DB_PASS'),
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

// Global middleware. Slim 4 runs middleware LIFO: the most recently added one
// is outermost and handles the request first, so this list reads inner-to-outer
// and the routing middleware above ends up innermost.
//
// Error handler goes on before the rest so it stays outside routing and every
// route handler, which is what lets it catch and format their exceptions.
$app->add($factory->getErrorHandler());

// OAS contract validation for terminal API paths — active in test environment
// only. It forwards request-validation failures to the handler and lets a
// response that breaks terminal.yaml fail the request, which is the point.
if (getenv('APP_ENV') === 'test') {
    $app->add($factory->getTerminalOasValidator());
}

$app->add($factory->getJsonBodyParser());
$app->add($factory->getCorsMiddleware());

// Security headers wrap everything, including the responses ErrorHandler builds
// itself — those never reach middleware registered inside it. Slim runs the
// most recently added middleware first, so this stays the last add() in the file.
$app->add($factory->getSecurityHeaders());

// Register routes
$routes = require __DIR__ . '/src/routes.php';
$routes($app);

return $app;
