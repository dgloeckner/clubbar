<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Modules\Backups\Domain\DumpResult;
use App\Modules\Backups\Domain\NonUtcDumpSessionException;
use App\Modules\Backups\Domain\TableClass;
use App\Modules\Backups\Domain\TableClassification;
use App\Modules\Backups\Domain\UnclassifiedTableException;
use App\Modules\Backups\Domain\UnclassifiedTablePolicy;
use App\Shared\Time\Utc;
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
 * Things this does that a naive dumper would not:
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
 * 4. **Base tables only.** `SHOW TABLES` includes views, so sourcing the list
 *    from it would make the first view added anywhere in the schema take the
 *    whole nightly backup down, from an unrelated migration.
 *
 * 5. **Both sessions are pinned to UTC** — this one and the one that restores.
 *    See {@see header()}.
 *
 * 6. **The archive is addressable per table**: terminated markers around each
 *    section, and every `INSERT` names its columns. Restoring everything is the
 *    wrong remedy for one lost table, and on shared hosting phpMyAdmin's upload
 *    limit can make a whole-archive import impossible anyway.
 *
 * ADR-0049 decision 1. Part of #688 and #699, epic #686.
 */
final class DatabaseDump
{
    /** Rows per INSERT statement. Large enough to be fast, small enough to parse back. */
    private const ROWS_PER_INSERT = 100;

    private const BINARY_TYPES = ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'];

    public function __construct(
        private readonly PDO $db,
        private readonly UnclassifiedTablePolicy $unclassified = UnclassifiedTablePolicy::THROW,
    ) {
    }

    /**
     * Write the dump through $write, and report what it wrote.
     *
     * @param callable(string): void $write
     */
    public function dump(callable $write): DumpResult
    {
        $this->assertReadingInUtc();

        [$tables, $unclassifiedTables] = $this->tablesToDump();
        $binaryColumns = $this->binaryColumnsByTable();
        $columns = $this->columnsByTable();

        $write($this->header());

        $manifest = [];
        $this->db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        try {
            foreach ($tables as $table => $class) {
                $write("\n-- >>> TABLE {$table}\n");
                $write($this->structureOf($table));

                $manifest[$table] = $class === TableClass::FULL
                    ? $this->writeRows($table, $columns[$table] ?? [], $binaryColumns[$table] ?? [], $write)
                    : 0;

                $write("-- <<< TABLE {$table}\n");
            }
        } finally {
            // Read-only: there is nothing to keep, and holding the snapshot open
            // past the dump would pin the undo log.
            $this->db->exec('COMMIT');
        }

        $write($this->footer());

        return new DumpResult($manifest, $unclassifiedTables);
    }

    /**
     * A dump read in the wrong zone is wrong before the archive's header can help.
     *
     * A `TIMESTAMP` is rendered to text in the session zone, so a dump taken on
     * a connection at `Europe/Berlin` writes literals two hours off the instant
     * they hold — internally consistent, and therefore invisible to every later
     * check. {@see \App\Shared\Database\ConnectionFactory} pins the offset for
     * every connection the application makes; this class accepts any PDO, so it
     * verifies rather than trusting.
     *
     * The check is the offset, not the name: a session can read UTC as `UTC`,
     * as `+00:00` or as `SYSTEM` on a UTC-configured server, and all three are
     * correct.
     */
    private function assertReadingInUtc(): void
    {
        $offset = (string) $this->db->query('SELECT TIMEDIFF(NOW(), UTC_TIMESTAMP())')->fetchColumn();

        if (str_starts_with($offset, '00:00:00') === false) {
            throw NonUtcDumpSessionException::offsetIs($offset);
        }
    }

    /**
     * Every table that contributes to the archive, in a stable order.
     *
     * Base tables only, and from `information_schema` rather than `SHOW TABLES`,
     * which also returns views. The class already makes an `information_schema`
     * trip for column types, so this costs nothing.
     *
     * @return array{array<string, TableClass>, list<string>} classified, then the guessed ones
     */
    private function tablesToDump(): array
    {
        $live = $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);

        $tables = [];
        $guessed = [];

        foreach ($live as $table) {
            try {
                $class = TableClassification::for($table);
            } catch (UnclassifiedTableException $e) {
                // THROW in CI, where a human is present and stopping costs
                // nothing. At 03:00 the run continues and reports: refusing the
                // whole night's backup over one unrecognised name would be the
                // control-that-looks-like-protection failure ADR-0049 opens
                // with, and an unrecognised table is likelier to be business
                // data than ephemera.
                if ($this->unclassified === UnclassifiedTablePolicy::THROW) {
                    throw $e;
                }

                $guessed[] = $table;
                $class = TableClass::FULL;
            }

            if ($class !== TableClass::SKIP) {
                $tables[$table] = $class;
            }
        }

        return [$tables, $guessed];
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

    /**
     * Every column, in ordinal order, so an INSERT can name what it fills.
     *
     * @return array<string, list<string>> table => column names
     */
    private function columnsByTable(): array
    {
        $rows = $this->db->query(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION'
        )->fetchAll(PDO::FETCH_ASSOC);

        $byTable = [];
        foreach ($rows as $row) {
            $byTable[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
        }

        return $byTable;
    }

    private function structureOf(string $table): string
    {
        $create = $this->db->query('SHOW CREATE TABLE ' . $this->quoteIdentifier($table))
            ->fetch(PDO::FETCH_NUM)[1];

        return "--\n-- Table structure for `{$table}`\n--\n\n"
            . 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table) . ";\n"
            . $create . ";\n";
    }

    /**
     * @param list<string> $columns
     * @param list<string> $binaryColumns
     * @param callable(string): void $write
     */
    private function writeRows(string $table, array $columns, array $binaryColumns, callable $write): int
    {
        $quoted = $this->quoteIdentifier($table);
        $columnList = implode(',', array_map($this->quoteIdentifier(...), $columns));

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
                    $write($this->insertStatement($quoted, $columnList, $batch));
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $write($this->insertStatement($quoted, $columnList, $batch));
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

    /**
     * Named columns, so one table's section loads into a schema that has since
     * gained a nullable column instead of failing on arity. At
     * {@see ROWS_PER_INSERT} rows per statement the extra bytes are noise, and
     * a single-table restore is the commoner event than a whole one.
     *
     * @param list<string> $batch
     */
    private function insertStatement(string $quotedTable, string $columnList, array $batch): string
    {
        return "INSERT INTO {$quotedTable} ({$columnList}) VALUES\n" . implode(",\n", $batch) . ";\n";
    }

    /**
     * The session the archive expects to be restored under.
     *
     * This is not decoration. {@see SqlValueEmitter} escapes with backslashes,
     * so `NO_BACKSLASH_ESCAPES` would silently change every escaped value; and
     * `NO_ZERO_DATE` would reject the `'0000-00-00'` values the dump copies
     * verbatim. Foreign keys go off because the tables are written in name
     * order, not dependency order.
     *
     * `time_zone` is the one whose absence has no symptom. `TIMESTAMP` columns —
     * the majority of this schema's date columns, against a minority of
     * `DATETIME` — are converted by the session zone, so an archive imported
     * through the host's phpMyAdmin in the host's own zone shifts every one of
     * them by that offset: settlement dates, announcement distances, audit
     * timestamps and the ADR-0038 seven-day window all moving together,
     * consistently, with nothing about the result looking wrong. Every other
     * path in this project pins UTC already, which is exactly why forgetting it
     * here was easy.
     *
     * The `ALTER DATABASE` names no database on purpose: unnamed, it applies to
     * whichever schema the restore is running in. The archive recreates its
     * tables but never the schema they land in, so without it the database's own
     * defaults are whatever created it.
     */
    private function header(): string
    {
        $schema = $this->db->query(
            'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA
             WHERE SCHEMA_NAME = DATABASE()'
        )->fetch(PDO::FETCH_ASSOC);

        return "-- Club Bar database dump\n"
            . "-- Restore with a client that honours the session settings below.\n"
            . "--\n\n"
            . "SET NAMES utf8mb4;\n"
            . "SET @OLD_SQL_MODE = @@SQL_MODE;\n"
            . "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';  -- not NO_BACKSLASH_ESCAPES, not NO_ZERO_DATE\n"
            . "SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;\n"
            . "SET FOREIGN_KEY_CHECKS = 0;\n"
            . "SET @OLD_TIME_ZONE = @@TIME_ZONE;\n"
            . "SET time_zone = '" . Utc::SQL_OFFSET . "';  -- TIMESTAMP is converted by the session zone\n"
            . sprintf(
                "ALTER DATABASE CHARACTER SET %s COLLATE %s;\n",
                $schema['DEFAULT_CHARACTER_SET_NAME'],
                $schema['DEFAULT_COLLATION_NAME']
            );
    }

    private function footer(): string
    {
        return "\nSET time_zone = @OLD_TIME_ZONE;\n"
            . "SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;\n"
            . "SET SQL_MODE = @OLD_SQL_MODE;\n"
            . "-- Dump complete\n";
    }

    /**
     * Identifiers come from `information_schema`, not from user input, but a
     * backtick in a table name would still produce broken SQL rather than an
     * error.
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
