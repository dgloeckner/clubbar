<?php

declare(strict_types=1);

namespace App\Modules\BankCodes\Services;

use App\Modules\BankCodes\Repositories\BankCodesRepository;
use App\Shared\Logging\Logger;

class BankCodeService
{
    /**
     * Stable Bundesbank resource blob ID for the BLZ TXT download.
     * The full URL is: https://www.bundesbank.de/resource/blob/602632/<hash>/mL/blz-<date>-txt-data.txt
     * Since the hash/date change quarterly, we use the download landing page which
     * redirects to the current file. Override via BUNDESBANK_BLZ_URL env var.
     */
    private const DEFAULT_BLZ_URL = 'https://www.bundesbank.de/resource/blob/602632/m/blz-aktuell-txt-data.txt';

    public function __construct(
        private BankCodesRepository $repository,
        private Logger $logger,
    ) {}

    /**
     * Extract BLZ (Bankleitzahl) from a German IBAN.
     * German IBAN format: DE + 2 check digits + 8 digit BLZ + 10 digit account number.
     * Returns null for non-German or invalid IBANs.
     */
    public static function extractBlz(?string $iban): ?string
    {
        if ($iban === null || strlen($iban) < 12) {
            return null;
        }
        $iban = strtoupper(str_replace(' ', '', $iban));
        if (!str_starts_with($iban, 'DE')) {
            return null;
        }
        return substr($iban, 4, 8);
    }

    /**
     * Look up bank name for an IBAN. Returns null for non-German IBANs or unknown BLZ.
     */
    public function getBankNameForIban(?string $iban): ?string
    {
        $blz = self::extractBlz($iban);
        if ($blz === null) {
            return null;
        }
        $row = $this->repository->findByBankCode($blz);
        return $row['bank_name'] ?? null;
    }

    /**
     * Everything known about the bank behind an IBAN.
     *
     * Returns `null` for anything that is not a German IBAN — the caller has to
     * distinguish "we do not look this country up" from "we looked and found
     * nothing", which is a populated record with null fields.
     *
     * @return array{bank_code: string, bank_name: ?string, short_name: ?string, bic: ?string, postal_code: ?string, city: ?string}|null
     */
    public function lookupByIban(?string $iban): ?array
    {
        $blz = self::extractBlz($iban);
        if ($blz === null) {
            return null;
        }

        $row = $this->repository->findByBankCode($blz);

        return [
            'bank_code' => $blz,
            'bank_name' => $row['bank_name'] ?? null,
            'short_name' => $row['short_name'] ?? null,
            'bic' => $row['bic'] ?? null,
            'postal_code' => $row['postal_code'] ?? null,
            'city' => $row['city'] ?? null,
        ];
    }

    /**
     * Batch lookup: map of IBAN → bank_name for a list of IBANs.
     * Efficient: extracts unique BLZs, does a single DB query.
     *
     * @param string[] $ibans
     * @return array<string, string|null> IBAN → bank_name
     */
    public function getBankNamesForIbans(array $ibans): array
    {
        $blzToIbans = [];
        foreach ($ibans as $iban) {
            if ($iban === null) {
                continue;
            }
            $blz = self::extractBlz($iban);
            if ($blz !== null) {
                $blzToIbans[$blz][] = $iban;
            }
        }

        if (empty($blzToIbans)) {
            return [];
        }

        $bankCodeRows = $this->repository->findByBankCodes(array_keys($blzToIbans));

        $result = [];
        foreach ($blzToIbans as $blz => $ibanList) {
            $bankName = $bankCodeRows[$blz]['bank_name'] ?? null;
            foreach ($ibanList as $iban) {
                $result[$iban] = $bankName;
            }
        }
        return $result;
    }

    /**
     * Download the BLZ file from Bundesbank and import it.
     *
     * URL resolution order:
     *   1. Explicit $url parameter
     *   2. BUNDESBANK_BLZ_URL environment variable
     *   3. Built-in default URL
     *
     * @return array{imported: int, removed: int, total: int, source: string}
     */
    public function downloadAndImport(?string $url = null): array
    {
        $url = $url
            ?? ($_ENV['BUNDESBANK_BLZ_URL'] ?? null)
            ?? ($_SERVER['BUNDESBANK_BLZ_URL'] ?? null)
            ?? self::DEFAULT_BLZ_URL;

        $this->logger->info('Downloading BLZ file', ['url' => $url]);

        $tempFile = tempnam(sys_get_temp_dir(), 'blz_');
        if ($tempFile === false) {
            throw new \RuntimeException('Cannot create temporary file');
        }

        try {
            $this->downloadFile($url, $tempFile);
            $result = $this->importFromFile($tempFile);
            $result['source'] = $url;
            return $result;
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Download a file via cURL with redirect-following and proper error handling.
     */
    private function downloadFile(string $url, string $destPath): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        $fp = fopen($destPath, 'w');
        if ($fp === false) {
            curl_close($ch);
            throw new \RuntimeException("Cannot open temp file for writing: {$destPath}");
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => 'ClubBar/1.0 (BLZ-Import)',
            CURLOPT_FAILONERROR => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$success) {
            @unlink($destPath);
            throw new \RuntimeException("Download failed (HTTP {$httpCode}): {$error}. URL: {$url}");
        }

        $fileSize = filesize($destPath);
        if ($fileSize === false || $fileSize < 1000) {
            @unlink($destPath);
            throw new \RuntimeException(
                "Downloaded file too small ({$fileSize} bytes) — likely not a valid BLZ file. "
                . "The Bundesbank URL may have changed. Set BUNDESBANK_BLZ_URL in .env to the current download URL. "
                . "Find it at: https://www.bundesbank.de/de/aufgaben/unbarer-zahlungsverkehr/serviceangebot/bankleitzahlen"
            );
        }

        $this->logger->info('BLZ file downloaded', ['size' => $fileSize, 'url' => $url]);
    }

    /**
     * Parse a Bundesbank BLZ fixed-width file and import into database.
     *
     * File format (fixed-width, one record per line):
     *   Pos 1-8:    BLZ (Bankleitzahl)
     *   Pos 9:      Merkmal (1 = Hauptstelle, 2 = Nebenstelle)
     *   Pos 10-67:  Bezeichnung (bank name, 58 chars)
     *   Pos 68-72:  PLZ (postal code, 5 chars)
     *   Pos 73-107: Ort (city, 35 chars)
     *   Pos 108-134: Kurzbezeichnung (short name, 27 chars)
     *   Pos 135-139: PAN (5 chars, unused)
     *   Pos 140-150: BIC (11 chars)
     *
     * Only Merkmal=1 (main office) entries are imported.
     *
     * @return array{imported: int, removed: int, total: int}
     */
    public function importFromFile(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException("BLZ file not found or not readable: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open BLZ file: {$filePath}");
        }

        $rows = [];
        $activeBankCodes = [];
        $batchSize = 500;
        $totalImported = 0;

        while (($line = fgets($handle)) !== false) {
            // Skip lines shorter than 150 chars (header/footer)
            if (mb_strlen($line, 'ISO-8859-1') < 140) {
                continue;
            }

            $merkmal = substr($line, 8, 1);
            // Only import main office entries (Merkmal = 1)
            if ($merkmal !== '1') {
                continue;
            }

            // Extract fixed-width fields on the raw ISO-8859-1 bytes (one byte
            // per char), THEN convert to UTF-8 — converting first would shift
            // all offsets after the first umlaut and split multibyte chars.
            $latin1 = fn(string $s) => mb_convert_encoding(trim($s), 'UTF-8', 'ISO-8859-1');

            $bankCode = substr($line, 0, 8);
            $bankName = $latin1(substr($line, 9, 58));
            $postalCode = trim(substr($line, 67, 5));
            $city = $latin1(substr($line, 72, 35));
            $shortName = $latin1(substr($line, 107, 27));
            $bic = trim(substr($line, 139, 11));

            $activeBankCodes[] = $bankCode;
            $rows[] = [
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'short_name' => $shortName,
                'bic' => $bic,
                'postal_code' => $postalCode,
                'city' => $city,
            ];

            if (count($rows) >= $batchSize) {
                $totalImported += $this->repository->importBatch($rows);
                $rows = [];
            }
        }

        fclose($handle);

        // Import remaining rows
        if (!empty($rows)) {
            $totalImported += $this->repository->importBatch($rows);
        }

        // Remove bank codes no longer in the file
        $removed = $this->repository->removeStale($activeBankCodes);
        if ($removed > 0) {
            $this->logger->info('Removed stale bank codes', ['count' => $removed]);
        }

        $this->logger->info('Bank codes imported', [
            'imported' => $totalImported,
            'removed' => $removed,
        ]);

        return [
            'imported' => $totalImported,
            'removed' => $removed,
            'total' => $this->repository->count(),
        ];
    }
}
