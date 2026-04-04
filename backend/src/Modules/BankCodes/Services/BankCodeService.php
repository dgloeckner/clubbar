<?php

declare(strict_types=1);

namespace App\Modules\BankCodes\Services;

use App\Modules\BankCodes\Repositories\BankCodesRepository;
use App\Shared\Logging\Logger;

class BankCodeService
{
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

            // Convert from ISO-8859-1 to UTF-8 (Bundesbank files use Latin-1)
            $line = mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1');

            $merkmal = substr($line, 8, 1);
            // Only import main office entries (Merkmal = 1)
            if ($merkmal !== '1') {
                continue;
            }

            $bankCode = substr($line, 0, 8);
            $bankName = trim(substr($line, 9, 58));
            $postalCode = trim(substr($line, 67, 5));
            $city = trim(substr($line, 72, 35));
            $shortName = trim(substr($line, 107, 27));
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
