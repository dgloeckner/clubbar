<?php

/**
 * Reproduces the failure ADR-0031 decision 1 is written for: the database is
 * unreachable, and the exception carrying the connection arguments is raised
 * where no application error handler exists yet.
 *
 * Run in a subprocess by RuntimeHardeningFatalTest, deliberately started with
 * `display_errors=1` on the command line — a host that prints stack traces by
 * default is exactly the situation the hardening has to survive.
 *
 * Usage: php failing-boot.php <mode> <session-save-path>
 *   hardened  — apply the hardening, then fail (production)
 *   debug     — the same with APP_DEBUG=true
 *   bootstrap — let backend/bootstrap.php do it, proving the ordering in the
 *               real file rather than a re-creation of it here
 */

declare(strict_types=1);

$mode = $argv[1] ?? 'hardened';
$savePath = $argv[2] ?? sys_get_temp_dir() . '/clubbar-fixture-sessions';
$backend = dirname(__DIR__, 5);

$_ENV['DB_HOST'] = 'clubbar-database-does-not-exist.invalid';
$_ENV['DB_NAME'] = 'clubbar';
$_ENV['DB_USER'] = 'clubbar_prod';
$_ENV['DB_PASS'] = 'correct-horse-battery-staple';
$_ENV['APP_ENV'] = 'production';
$_ENV['APP_DEBUG'] = $mode === 'debug' ? 'true' : 'false';
$_ENV['SESSION_SAVE_PATH'] = $savePath;

if ($mode === 'bootstrap') {
    require $backend . '/bootstrap.php';
    // Unreachable: the PDO connection above throws. Reaching it means the
    // fixture connected to something, and the test asserts on the exit code.
    exit(0);
}

require $backend . '/vendor/autoload.php';

App\Shared\Security\RuntimeHardening::apply(
    new App\Shared\Config\AppConfig(),
    registerFatalHandler: true,
);

new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], $_ENV['DB_NAME']),
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
);
