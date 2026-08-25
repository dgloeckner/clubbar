#!/usr/bin/env php
<?php

/**
 * Write an encrypted database backup. The scheduled entrypoint, and the
 * preferred one.
 *
 * Usage (from the panel's cron, nightly):
 *   /usr/bin/php /path/to/htdocs/backend/bin/backup.php
 *
 *   php bin/backup.php --force    # ignore the minimum interval; an operator asked
 *   php bin/backup.php --quiet    # say nothing unless something needs attention
 *
 * ## Why this is a second cron and not a branch inside bin/cron.php
 *
 * ADR-0038 decision 3 reads *"one scheduled command, because a second is a job
 * nothing watches."* The load-bearing half is **"nothing watches"**, not "one
 * command" — it was written when the drain was the only scheduled work, and
 * what it rejected was a second *sending* path that would relieve the pressure
 * to fix the real one. A second job is legitimate exactly when it is separately
 * observed, and #693 gives this one its own self-check row and its own failure
 * mail.
 *
 * Separation also buys three things the shared version could not have: each job
 * gets the whole 60 s abort instead of splitting it; the schedules state their
 * real intent (`*&#47;15` for mail, `0 3 * * *` here) rather than asking "am I due?"
 * ninety-six times a day to act once; and a backup dying on a huge `audit_log`
 * cannot delay the mail drain or sit on its lock.
 *
 * ## What it does that a naive entrypoint would not
 *
 *   1. Resolves the **document root** as `dirname(__DIR__, 2)` and asks
 *      `DataDirectory` where the data lives, so it finds `config.php` in both
 *      supported layouts (ADR-0031 decision 2).
 *
 *   2. Takes its **own** non-blocking `flock`, in its own file. Not the
 *      drain's: sharing one would make the two jobs able to block each other,
 *      which is the thing separating them was for.
 *
 *   3. **Pins the database session to UTC explicitly** rather than inheriting
 *      it. `DatabaseDump` refuses a non-UTC connection (#699) because a
 *      `TIMESTAMP` rendered in the host's zone writes the wrong instant into a
 *      statement that looks perfectly correct — this is the caller that has to
 *      satisfy that, and `ConnectionFactory` is what does it.
 *
 *   4. Is **CLI-only**. The package ships this inside the document root behind
 *      `.htaccess` rules the host honours at its discretion (#383), so if those
 *      are ignored this must not become a URL that writes a database dump for
 *      anyone who finds it. The URL trigger exists, it is a route, and it
 *      checks a secret.
 *
 * Exits 0 unless the run could not start at all, for the same reason the drain
 * does: a non-zero exit makes most panels mail the account owner, and "the
 * webspace is nearly full" is not something to wake somebody for at 03:00. It
 * is recorded in the journal beside the archives, in the log, and — per #693 —
 * mailed to the Admin.
 */

declare(strict_types=1);

use App\Modules\Backups\Services\BackupService;
use App\ServiceFactory;
use App\Shared\Config\AppConfig;
use App\Shared\Config\ConfigFile;
use App\Shared\Config\DataDirectory;
use App\Shared\Config\Env;
use App\Shared\Config\PhpRuntime;
use App\Shared\Database\ConnectionFactory;
use App\Shared\Logging\Logger;
use App\Shared\Process\FileLock;
use App\Shared\Time\Utc;

require __DIR__ . '/../vendor/autoload.php';

if (!PhpRuntime::isCli()) {
    http_response_code(404);
    exit(1);
}

// A CLI process never goes through bootstrap.php, so it pins the clock itself.
// Every timestamp this run writes — and the archive's own filename — would
// otherwise carry whatever zone the host's CLI php.ini happens to have (#365).
Utc::apply();

// --- Configuration ---------------------------------------------------------
// backend/bin/backup.php → backend → the document root.
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

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    Env::load($envFile);
}

// --- Arguments -------------------------------------------------------------
$force = false;
$quiet = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];

    if ($arg === '--force' || $arg === '-f') {
        $force = true;
    } elseif ($arg === '--quiet' || $arg === '-q') {
        $quiet = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php bin/backup.php [--force] [--quiet]\n\n";
        echo "  --force   Run even if the last run started less than "
            . BackupService::MINIMUM_INTERVAL_MINUTES . " minutes ago.\n";
        echo "            The interval exists to stop the URL trigger filling the\n";
        echo "            webspace quota, not to space out a schedule.\n";
        echo "  --quiet   Print nothing unless something needs attention\n";
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
    $logger = new Logger($config->logDir, $config->debug ? 'DEBUG' : 'INFO', 'backup');

    // Through ConnectionFactory, which issues `SET time_zone = '+00:00'` as part
    // of connecting — see point 3 above. DatabaseDump refuses anything else.
    $pdo = ConnectionFactory::create(
        Env::get('DB_HOST'),
        Env::get('DB_NAME'),
        Env::get('DB_USER'),
        Env::get('DB_PASS'),
    );

    $factory = new ServiceFactory($pdo, $config, $logger);
} catch (\Throwable $e) {
    // Nothing ran, so nothing is recorded anywhere the admin panel can show it.
    // The one case that earns a non-zero exit.
    fwrite(STDERR, "Error: backup could not start: {$e->getMessage()}\n");
    exit(1);
}

$missing = PhpRuntime::missingExtensions();
if ($missing !== []) {
    fwrite(STDERR, 'Warning: this PHP (' . PHP_VERSION . ', ' . PHP_BINARY . ') is missing: '
        . implode(', ', $missing) . "\n");
}

// Its own lock file, not the drain's. A backup that outruns its schedule must
// not be overtaken by the next tick — on a tariff that allows very few PHP
// processes, two dumps of the same database is the worst possible pair.
$lock = new FileLock(rtrim($config->storageDir, '/') . '/backup.lock');

try {
    if (!$lock->acquire()) {
        $say('Another backup run holds ' . $lock->path() . ' — nothing to do.');
        exit(0);
    }

    $backups = $factory->getBackupService();

    // Configuring a recipient key is the on-switch (ADR-0049 decision 2), so an
    // installation that has not done it is not misconfigured — it has not asked
    // for backups. Say what would switch them on, and exit 0: a nightly failure
    // mail to somebody who never wanted the job teaches them to filter it.
    if (!$backups->isConfigured()) {
        $say(
            'Backups are not configured, so nothing was written. Add a recipient public key '
            . 'to backup.recipient_public_keys in config.php — generate one offline with '
            . 'tools/keypair-generator.html — and this job starts writing archives.'
        );
    } else {
        $outcome = $backups->run('cli', $force);

        $say($outcome->summary);

        if ($outcome->prunedArchives > 0) {
            $say(sprintf('  Pruned %d expired archive(s).', $outcome->prunedArchives));
        }

        // Findings go to STDERR even under --quiet: this is the stream a
        // panel's cron report shows, and it is the only channel that reaches
        // somebody before #693's mail exists.
        foreach ($outcome->findings as $finding) {
            fwrite(STDERR, 'Backup: ' . $finding . "\n");
        }
    }
} catch (\Throwable $e) {
    // BackupService does not throw; the lock can, on a data directory the cron
    // user cannot write.
    $logger->error('Backup entrypoint failed', [
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ]);
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
} finally {
    $lock->release();
}

exit(0);
