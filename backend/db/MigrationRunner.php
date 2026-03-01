<?php

declare(strict_types=1);

namespace App\Db;

use PDO;

class MigrationRunner
{
    private PDO $db;
    private array $log = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS _migrations (
            file VARCHAR(255) PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            applied_by VARCHAR(255)
        )');
    }

    public function migrate(string $dir, string $appliedBy = 'installer'): array
    {
        $applied = $this->db->query('SELECT file, checksum FROM _migrations')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $files = glob($dir . '/*.sql');
        sort($files);

        // Note: MySQL/MariaDB implicitly commits on DDL statements (CREATE TABLE,
        // ALTER TABLE, etc.), so wrapping migrations in a transaction is not possible.
        // Each migration is applied individually instead.
        foreach ($files as $file) {
            $name     = basename($file);
            $sql      = file_get_contents($file);
            $checksum = hash('sha256', $sql);

            if (isset($applied[$name])) {
                if ($applied[$name] !== $checksum) {
                    $this->log[] = [
                        'status' => 'FAIL',
                        'file' => $name,
                        'message' => "INTEGRITY VIOLATION: {$name} has been modified after application. " .
                            "Expected checksum {$applied[$name]}, got {$checksum}.",
                    ];
                    return $this->log;
                }
                $this->log[] = ['status' => 'SKIP', 'file' => $name, 'reason' => 'already applied'];
                continue;
            }

            try {
                $this->db->exec($sql);

                $stmt = $this->db->prepare('INSERT INTO _migrations (file, checksum, applied_by) VALUES (?, ?, ?)');
                $stmt->execute([$name, $checksum, $appliedBy]);

                $this->log[] = ['status' => 'OK', 'file' => $name];
            } catch (\Throwable $e) {
                $this->log[] = ['status' => 'FAIL', 'file' => $name, 'message' => $e->getMessage()];
                return $this->log;
            }
        }

        $this->log[] = ['status' => 'DONE', 'message' => 'All migrations applied'];
        return $this->log;
    }

    public function status(string $dir): array
    {
        $applied = $this->db->query('SELECT file, applied_at FROM _migrations ORDER BY file')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $files = glob($dir . '/*.sql');
        sort($files);

        $status = [];
        foreach ($files as $file) {
            $name = basename($file);
            $status[] = [
                'file'    => $name,
                'applied' => isset($applied[$name]),
                'date'    => $applied[$name] ?? null,
            ];
        }
        return $status;
    }
}
