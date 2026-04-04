<?php

declare(strict_types=1);

namespace App\Modules\BankCodes\Repositories;

use PDO;
use App\Shared\Logging\Logger;

class BankCodesRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findByBankCode(string $bankCode): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM bank_codes WHERE bank_code = ?');
        $stmt->execute([$bankCode]);
        return $stmt->fetch() ?: null;
    }

    public function findByBankCodes(array $bankCodes): array
    {
        if (empty($bankCodes)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($bankCodes), '?'));
        $stmt = $this->db->prepare("SELECT bank_code, bank_name, bic FROM bank_codes WHERE bank_code IN ({$placeholders})");
        $stmt->execute(array_values($bankCodes));
        $rows = $stmt->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['bank_code']] = $row;
        }
        return $map;
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM bank_codes')->fetchColumn();
    }

    /**
     * Bulk-import bank codes using REPLACE INTO for upsert semantics.
     * Expects each row to have: bank_code, bank_name, short_name, bic, postal_code, city.
     *
     * @param array[] $rows
     * @return int Number of rows imported
     */
    public function importBatch(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $stmt = $this->db->prepare(
            'REPLACE INTO bank_codes (bank_code, bank_name, short_name, bic, postal_code, city, imported_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );

        $count = 0;
        foreach ($rows as $row) {
            $stmt->execute([
                $row['bank_code'],
                $row['bank_name'],
                $row['short_name'] ?? '',
                $row['bic'] ?? '',
                $row['postal_code'] ?? '',
                $row['city'] ?? '',
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Remove bank codes that are no longer in the Bundesbank file.
     *
     * @param string[] $activeBankCodes All bank codes from the current import
     * @return int Number of rows deleted
     */
    public function removeStale(array $activeBankCodes): int
    {
        if (empty($activeBankCodes)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($activeBankCodes), '?'));
        $stmt = $this->db->prepare("DELETE FROM bank_codes WHERE bank_code NOT IN ({$placeholders})");
        $stmt->execute($activeBankCodes);
        return $stmt->rowCount();
    }
}
