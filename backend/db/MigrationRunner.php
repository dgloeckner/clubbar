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

    /**
     * @param array<string,mixed> $context Passed verbatim to every `.php` migration's
     *                                     callable, alongside the PDO connection. `.sql`
     *                                     migrations have no use for it.
     */
    public function migrate(string $dir, string $appliedBy = 'installer', array $context = []): array
    {
        $applied = $this->db->query('SELECT file, checksum FROM _migrations')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        $files = self::migrationFiles($dir);

        // A real install always ships migration files, so finding none here is
        // never "nothing pending" — it means $dir itself is wrong (e.g. resolved
        // against a relocated data directory instead of the code that was
        // actually unpacked). Silently reporting DONE in that case hides a
        // broken deploy instead of failing it.
        if ($files === false || $files === []) {
            $this->log[] = [
                'status' => 'FAIL',
                'file' => null,
                'message' => "No migration files found in {$dir}. Refusing to report success.",
            ];
            return $this->log;
        }

        // Note: MySQL/MariaDB implicitly commits on DDL statements (CREATE TABLE,
        // ALTER TABLE, etc.), so wrapping migrations in a transaction is not possible.
        // Each migration is applied individually instead.
        foreach ($files as $file) {
            $name     = basename($file);
            $source   = file_get_contents($file);
            $checksum = hash('sha256', $source);

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
                if (str_ends_with($name, '.php')) {
                    $migration = require $file;
                    if (!is_callable($migration)) {
                        throw new \RuntimeException("{$name} must return a callable(PDO, array): void.");
                    }
                    $migration($this->db, $context);
                } else {
                    $this->db->exec($source);
                }

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

        $files = self::migrationFiles($dir);

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

    /**
     * `.sql` files run their contents directly; `.php` files return a
     * `callable(PDO $pdo, array $context): void` for migrations that need
     * more than a `db->exec()` call — e.g. removing files from disk
     * alongside a schema change. Both are ordered together by filename, so
     * the leading number still decides run order regardless of extension.
     *
     * @return list<string>
     */
    private static function migrationFiles(string $dir): array
    {
        $files = [...glob($dir . '/*.sql'), ...glob($dir . '/*.php')];
        sort($files);
        return $files;
    }
}
