#!/usr/bin/env php
<?php

/**
 * Import Bundesbank BLZ (Bankleitzahl) data into the bank_codes table.
 *
 * Usage:
 *   php bin/import-bank-codes.php                          # auto-download from Bundesbank
 *   php bin/import-bank-codes.php /tmp/blz_20260309.txt    # import local file
 *   php bin/import-bank-codes.php --url https://...        # download from custom URL
 *
 * Auto-download uses BUNDESBANK_BLZ_URL from .env, or falls back to the
 * built-in default URL. Override when the Bundesbank changes their download path.
 *
 * File format: Fixed-width text (ISO-8859-1), updated quarterly by Deutsche Bundesbank.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Shared\Config\Env;
use App\Shared\Logging\Logger;
use App\Modules\BankCodes\Repositories\BankCodesRepository;
use App\Modules\BankCodes\Services\BankCodeService;

// --- Bootstrap ---
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    Env::load($envFile);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Env::get('DB_HOST'), Env::get('DB_NAME')),
    Env::get('DB_USER'),
    Env::get('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$logDir = Env::get('LOG_DIR', __DIR__ . '/../logs');
$logger = new Logger($logDir, 'INFO', 'bank-import');

$repository = new BankCodesRepository($pdo, $logger);
$service = new BankCodeService($repository, $logger);

// --- Parse arguments ---
$filePath = null;
$url = null;

for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--url' && isset($argv[$i + 1])) {
        $url = $argv[++$i];
    } elseif ($argv[$i] === '--help' || $argv[$i] === '-h') {
        echo "Usage: php bin/import-bank-codes.php [<blz-file>] [--url <url>]\n\n";
        echo "  No arguments    Auto-download from Bundesbank (uses BUNDESBANK_BLZ_URL env or default)\n";
        echo "  <blz-file>      Import from a local fixed-width BLZ text file\n";
        echo "  --url <url>     Download from a custom URL instead of the default\n";
        exit(0);
    } else {
        $filePath = $argv[$i];
    }
}

// --- Import ---
try {
    if ($filePath !== null) {
        if (!file_exists($filePath)) {
            fwrite(STDERR, "Error: File not found: {$filePath}\n");
            exit(1);
        }
        echo "Importing bank codes from: {$filePath}\n";
        $result = $service->importFromFile($filePath);
    } else {
        echo "Downloading BLZ file from Bundesbank...\n";
        $result = $service->downloadAndImport($url);
        echo "  Source: {$result['source']}\n";
    }

    echo "Done!\n";
    echo "  Imported: {$result['imported']}\n";
    echo "  Removed (stale): {$result['removed']}\n";
    echo "  Total in database: {$result['total']}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
