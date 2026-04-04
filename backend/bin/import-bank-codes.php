#!/usr/bin/env php
<?php

/**
 * Import Bundesbank BLZ (Bankleitzahl) data into the bank_codes table.
 *
 * Usage:
 *   php bin/import-bank-codes.php <path-to-blz-file>
 *   php bin/import-bank-codes.php /tmp/blz_20260309.txt
 *
 * The BLZ file can be downloaded from:
 *   https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/bankleitzahlen
 *
 * File format: Fixed-width text (ISO-8859-1), updated quarterly by Deutsche Bundesbank.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Shared\Config\Env;
use App\Shared\Logging\Logger;
use App\Modules\BankCodes\Repositories\BankCodesRepository;
use App\Modules\BankCodes\Services\BankCodeService;

// --- Parse arguments ---
if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/import-bank-codes.php <path-to-blz-file>\n");
    fwrite(STDERR, "\nDownload the BLZ file from:\n");
    fwrite(STDERR, "  https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/bankleitzahlen\n");
    exit(1);
}

$filePath = $argv[1];

if (!file_exists($filePath)) {
    fwrite(STDERR, "Error: File not found: {$filePath}\n");
    exit(1);
}

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

// --- Import ---
echo "Importing bank codes from: {$filePath}\n";

try {
    $result = $service->importFromFile($filePath);
    echo "Done!\n";
    echo "  Imported: {$result['imported']}\n";
    echo "  Removed (stale): {$result['removed']}\n";
    echo "  Total in database: {$result['total']}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
