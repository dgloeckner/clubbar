#!/usr/bin/env php
<?php

/**
 * Drain the mail outbox. The scheduled entrypoint, and the preferred one.
 *
 * Usage (from the panel's cron, every 5–15 minutes):
 *   /usr/bin/php /path/to/htdocs/backend/bin/cron.php
 *
 *   php bin/cron.php --batch 50      # claim 50 messages per round
 *   php bin/cron.php --budget 120    # run for up to 2 minutes
 *   php bin/cron.php --quiet         # say nothing unless something failed
 *
 * Exits 0 unless the run could not start at all. A cron that exits non-zero
 * makes most panels mail the account owner, and "one recipient's mailbox is
 * full" is not something to wake anybody for — per-message failures are
 * recorded on the message and shown to the Kassenwart in the settlement detail
 * (ADR-0038 rule 6).
 *
 * Three things this file does that a naive entrypoint would not:
 *
 *   1. It resolves the **document root** as `dirname(__DIR__, 2)` and asks
 *      `DataDirectory` where the data lives, so it finds `config.php` in both
 *      supported layouts — above the document root where the host has a
 *      writable parent, inside it where it has not (ADR-0031 decision 2). A
 *      cron that only looked next to itself would work on exactly one of them.
 *
 *   2. It takes a **non-blocking `flock`** in the data directory, so a run that
 *      is slower than the cron interval is not overtaken by the next tick. The
 *      outbox claim already prevents double-sending; this prevents piling up
 *      PHP processes on a tariff that allows very few.
 *
 *   3. It records the **CLI PHP version and any missing extensions** in
 *      `cron_heartbeat`. On mass hosting the panel's CLI PHP is frequently not
 *      the web PHP — a different build, a different `php.ini`, a different
 *      extension set — and when a queue will not move that difference is the
 *      first thing to rule out. It should be readable, not mysterious.
 */

declare(strict_types=1);

use App\Modules\Notifications\Enums\DrainSource;
use App\Modules\Notifications\Services\DrainService;
use App\ServiceFactory;
use App\Shared\Config\AppConfig;
use App\Shared\Config\ConfigFile;
use App\Shared\Config\DataDirectory;
use App\Shared\Config\Env;
use App\Shared\Config\PhpRuntime;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Logging\Logger;
use App\Shared\Time\Utc;

require __DIR__ . '/../vendor/autoload.php';

// The package ships this file inside the document root, behind `.htaccess`
// rules the host honours at its discretion — which is the premise ADR-0031 is
// built on. If those rules are ignored, this becomes a URL that drains the
// queue for anyone who finds it. The URL trigger exists, it is a route, and it
// checks a secret; this entrypoint is CLI-only.
if (!PhpRuntime::isCli()) {
    http_response_code(404);
    exit(1);
}

// A CLI process never goes through bootstrap.php, so it pins the clock itself.
// Without this the heartbeat and every `next_attempt_at` would be written in
// whatever zone the host's CLI php.ini happens to carry, into columns the API
// labels as UTC (#365).
Utc::apply();

// --- Configuration ---------------------------------------------------------
// backend/bin/cron.php → backend → the document root.
$documentRoot = dirname(__DIR__, 2);
$configFile = DataDirectory::configPath($documentRoot);

if (is_file($configFile)) {
    $fileConfig = require $configFile;
    if (!is_array($fileConfig)) {
        fwrite(STDERR, "Error: {$configFile} did not return an array.\n");
        exit(1);
    }
    ConfigFile::applyToEnvironment($fileConfig, DataDirectory::resolve($documentRoot));
}

// A development checkout has no config.php and a .env instead. Loaded second so
// that on an installation carrying both — a developer poking at a real
// deployment — the file the web server reads is not the one the cron ignores.
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    Env::load($envFile);
}

// --- Arguments -------------------------------------------------------------
$batchSize = null;
$budgetSeconds = null;
$quiet = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];

    if (($arg === '--batch' || $arg === '-b') && isset($argv[$i + 1])) {
        $batchSize = max(1, (int) $argv[++$i]);
    } elseif (($arg === '--budget' || $arg === '-t') && isset($argv[$i + 1])) {
        $budgetSeconds = max(1, (int) $argv[++$i]);
    } elseif ($arg === '--quiet' || $arg === '-q') {
        $quiet = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php bin/cron.php [--batch <n>] [--budget <seconds>] [--quiet]\n\n";
        echo "  --batch <n>         Messages claimed per round (default "
            . DrainService::DEFAULT_BATCH_SIZE . ")\n";
        echo "  --budget <seconds>  Wall-clock budget for the run (default "
            . DrainService::DEFAULT_BUDGET_SECONDS . ")\n";
        echo "  --quiet             Print nothing unless something failed\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(1);
    }
}

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line . "\n";
    }
};

// --- Run -------------------------------------------------------------------
try {
    $config = new AppConfig();
    $logger = new Logger($config->logDir, $config->debug ? 'DEBUG' : 'INFO', 'cron');

    $pdo = ConnectionFactory::create(
        Env::get('DB_HOST'),
        Env::get('DB_NAME'),
        Env::get('DB_USER'),
        Env::get('DB_PASS'),
    );

    $factory = new ServiceFactory($pdo, $config, $logger);
} catch (\Throwable $e) {
    // Nothing ran, so nothing is recorded anywhere the admin panel can show it.
    // This is the one case that earns a non-zero exit and a mail from the panel.
    fwrite(STDERR, "Error: cron could not start: {$e->getMessage()}\n");
    exit(1);
}

// A PHP the cron picked up that cannot reach an SMTP server is worth saying out
// loud as well as recording — the crontab output is where somebody testing the
// command by hand is looking.
$missing = PhpRuntime::missingExtensions();
if ($missing !== []) {
    fwrite(STDERR, 'Warning: this PHP (' . PHP_VERSION . ', ' . PHP_BINARY . ') is missing: '
        . implode(', ', $missing) . "\n");
}

$lock = DrainService::lockIn($config->storageDir);

try {
    if (!$lock->acquire()) {
        $say('Another drain run holds ' . $lock->path() . ' — nothing to do.');
        exit(0);
    }

    // ADR-0041, before the drain on purpose: a warning raised by this scan is
    // queued into the outbox, and running it first means that warning leaves in
    // the same tick rather than waiting for the next one.
    //
    // `run()` never throws — a detector that could not read a table must not
    // stop the club's announcements from going out — but the belt-and-braces
    // catch stays, because "the drain still runs" is the property that matters
    // here and it should not depend on a promise made in another file.
    try {
        $scan = $factory->getTerminalAnomalyDetector()->run();
        $say('Terminal anomaly scan: ' . $scan->summary());

        if ($scan->opened > 0) {
            fwrite(STDERR, "Terminal anomalies detected: {$scan->summary()}\n");
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "Warning: terminal anomaly scan failed: {$e->getMessage()}\n");
    }

    $result = $factory->getDrainService()->run(DrainSource::CLI, $batchSize, $budgetSeconds);

    $say($result->summary());

    if ($result->failed > 0 || $result->skipped > 0) {
        // Not an error exit: these are recorded per message and visible to the
        // Kassenwart. Printed to STDERR so a panel that mails only stderr says
        // something, and a panel that mails everything is not woken by a
        // successful run.
        fwrite(STDERR, "Some messages could not be delivered: {$result->summary()}\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
} finally {
    $lock->release();
}

exit(0);
