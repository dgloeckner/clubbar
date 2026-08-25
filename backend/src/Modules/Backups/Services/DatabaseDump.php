<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Modules\Backups\Domain\TableClass;
use App\Modules\Backups\Domain\TableClassification;
use PDO;

/**
 * The database, as SQL, produced without `mysqldump`.
 *
 * The reference host has no shell and no client binaries (ADR-0031), so the
 * dump is walked here: the schema from `SHOW CREATE TABLE`, the rows streamed
 * through PDO. Output goes to a callback rather than a string or a file, so the
 * caller decides where it lands — #690 pipes it through gzip into a sealed
 * archive without either side holding the whole database in memory.
 *
 * Three things this does that a naive dumper would not:
 *
 * 1. **One consistent snapshot.** `START TRANSACTION WITH CONSISTENT SNAPSHOT`
 *    (InnoDB) means every table is read as of the same instant. Without it a
 *    settlement written mid-dump appears in `settlements` and not in
 *    `settlement_items`, and the archive is internally inconsistent in a way
 *    nothing detects until it is restored.
 *
 * 2. **Unbuffered row streaming.** A buffered query materialises the whole
 *    result in PHP before the first row is available, which on `transactions`
 *    is the memory limit. Unbuffered means one row at a time — at the cost that
 *    **no other query may run while a result set is open**, which is why every
 *    lookup this class needs (table list, column types) happens up front.
 *
 * 3. **Binary columns are found before they are read**, from
 *    `information_schema`, because PDO hands back a PHP string either way and
 *    guessing from content would misjudge a sealed box that happens to be valid
 *    UTF-8. {@see SqlValueEmitter} then writes those as hex literals.
 *
 * ADR-0049 decision 1. Part of #688, epic #686.
 */
final class DatabaseDump
{
    /** Rows per INSERT statement. Large enough to be fast, small enough to parse back. */
    private const ROWS_PER_INSERT = 100;

    private const BINARY_TYPES = ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Write the dump through $write, and return per-table row counts.
     *
     * @param callable(string): void $write
     * @return array<string, int> table name => rows written
     */
    public function dump(callable $write): array
    {
        $tables = $this->tablesToDump();
        $binaryColumns = $this->binaryColumnsByTable();

        $write($this->header());

        $manifest = [];
        $this->db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        try {
            foreach ($tables as $table => $class) {
                $write($this->structureOf($table));

                $manifest[$table] = $class === TableClass::FULL
                    ? $this->writeRows($table, $binaryColumns[$table] ?? [], $write)
                    : 0;
            }
        } finally {
            // Read-only: there is nothing to keep, and holding the snapshot open
            // past the dump would pin the undo log.
            $this->db->exec('COMMIT');
        }

        $write($this->footer());

        return $manifest;
    }

    /**
     * Every table that contributes to the archive, in a stable order.
     *
     * @return array<string, TableClass>
     */
    private function tablesToDump(): array
    {
        $live = $this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($live);

        $tables = [];
        foreach ($live as $table) {
            // Throws for a table nobody classified — a new migration must state
            // its intent rather than inherit one (TableClassification).
            $class = TableClassification::for($table);

            if ($class !== TableClass::SKIP) {
                $tables[$table] = $class;
            }
        }

        return $tables;
    }

    /**
     * @return array<string, list<string>> table => binary column names
     */
    private function binaryColumnsByTable(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::BINARY_TYPES), '?'));
        $stmt = $this->db->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ({$placeholders})"
        );
        $stmt->execute(self::BINARY_TYPES);

        $byTable = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byTable[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
        }

        return $byTable;
    }

    private function structureOf(string $table): string
    {
        $create = $this->db->query('SHOW CREATE TABLE ' . $this->quoteIdentifier($table))
            ->fetch(PDO::FETCH_NUM)[1];

        return "\n--\n-- Table structure for `{$table}`\n--\n\n"
            . 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table) . ";\n"
            . $create . ";\n";
    }

    /**
     * @param list<string> $binaryColumns
     * @param callable(string): void $write
     */
    private function writeRows(string $table, array $binaryColumns, callable $write): int
    {
        $quoted = $this->quoteIdentifier($table);

        // Unbuffered: from here until the cursor is exhausted this connection
        // cannot be used for anything else. Everything needed was read above.
        $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $rows = null;

        try {
            $rows = $this->db->query("SELECT * FROM {$quoted}");

            $written = 0;
            $batch = [];

            foreach ($rows as $row) {
                $batch[] = $this->rowLiteral($row, $binaryColumns);
                $written++;

                if (count($batch) >= self::ROWS_PER_INSERT) {
                    $write($this->insertStatement($quoted, $batch));
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $write($this->insertStatement($quoted, $batch));
            }
        } finally {
            // An exception from $write would otherwise leave the cursor open,
            // and on an unbuffered connection the next query fails with
            // "Cannot execute queries while other unbuffered queries are
            // active" — which reads as a bug in the following table.
            $rows?->closeCursor();
            $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        return $written;
    }

    /**
     * @param array<string, string|null> $row
     * @param list<string> $binaryColumns
     */
    private function rowLiteral(array $row, array $binaryColumns): string
    {
        $values = [];
        foreach ($row as $column => $value) {
            $values[] = SqlValueEmitter::literal(
                $value === null ? null : (string) $value,
                in_array($column, $binaryColumns, true)
            );
        }

        return '(' . implode(',', $values) . ')';
    }

    /** @param list<string> $batch */
    private function insertStatement(string $quotedTable, array $batch): string
    {
        return "INSERT INTO {$quotedTable} VALUES\n" . implode(",\n", $batch) . ";\n";
    }

    /**
     * The session the archive expects to be restored under.
     *
     * This is not decoration. {@see SqlValueEmitter} escapes with backslashes,
     * so `NO_BACKSLASH_ESCAPES` would silently change every escaped value; and
     * `NO_ZERO_DATE` would reject the `'0000-00-00'` values the dump copies
     * verbatim. Foreign keys go off because the tables are written in name
     * order, not dependency order.
     */
    private function header(): string
    {
        return "-- Club Bar database dump\n"
            . "-- Restore with a client that honours the session settings below.\n"
            . "--\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET @OLD_SQL_MODE = @@SQL_MODE;\n"
            . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';  -- not NO_BACKSLASH_ESCAPES, not NO_ZERO_DATE\n"
            . "SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;\n"
            . "SET FOREIGN_KEY_CHECKS = 0;\n";
    }

    private function footer(): string
    {
        return "\nSET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;\n"
            . "SET SQL_MODE = @OLD_SQL_MODE;\n"
            . "-- Dump complete\n";
    }

    /**
     * Identifiers come from `SHOW TABLES`, not from user input, but a backtick
     * in a table name would still produce broken SQL rather than an error.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
