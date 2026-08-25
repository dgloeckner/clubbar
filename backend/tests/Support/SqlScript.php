<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

/**
 * Running a dump the way a restore does, and reading one table's section out of it.
 *
 * ### Why the whole script goes to the server in one piece
 *
 * The obvious implementation splits on `;` and loops. It is also wrong, and
 * wrong in the direction that matters here: a member's note containing `;`
 * followed by a newline splits into two invalid statements, and the test then
 * reports a dumper bug that is really a bug in the test's parser. There is
 * exactly one component that knows where a MariaDB statement ends, and it is
 * MariaDB. So the script is handed over whole and the result sets are drained.
 *
 * PDO_MySQL enables `CLIENT_MULTI_STATEMENTS`, so `query()` accepts the script;
 * with `ERRMODE_EXCEPTION` an error in *any* statement surfaces — but only once
 * the cursor reaches it, which is why {@see executeScript()} walks every rowset
 * to the end instead of stopping at the first. A restore that failed on its
 * last table would otherwise look like a success.
 *
 * Part of #692, epic #686.
 */
trait SqlScript
{
    /**
     * Execute a multi-statement SQL script, failing on the first statement that errors.
     */
    protected static function executeScript(PDO $db, string $sql): void
    {
        $statement = $db->query($sql);

        if ($statement === false) {
            throw new RuntimeException('The restore script was rejected before its first statement ran.');
        }

        // Every rowset, not just the first: an error in statement 400 of 400 is
        // only raised when the cursor gets there. `nextRowset()` returns false
        // at the end and throws on an error, which is the whole contract.
        do {
            // Nothing to read — a dump is DDL and INSERTs — but the rowset has
            // to be consumed before the next one is available.
            $statement->closeCursor();
        } while ($statement->nextRowset());
    }

    /**
     * One table's section of a dump, between the markers the dumper writes.
     *
     * {@see \App\Modules\Backups\Services\DatabaseDump} brackets each table with
     * `-- >>> TABLE x` and `-- <<< TABLE x` precisely so a section is
     * addressable — restoring everything is the wrong remedy for one damaged
     * table, and on shared hosting phpMyAdmin's upload limit can make a
     * whole-archive import impossible anyway.
     *
     * The markers are matched anchored to line starts. A value containing the
     * marker text would fool this, which is a caveat worth stating and not a
     * risk worth engineering around: the extraction is a test and a runbook
     * convenience, never a restore path the application executes.
     *
     * @return string the section including both markers, or `''` when absent
     */
    protected static function tableSection(string $sql, string $table): string
    {
        $open = '-- >>> TABLE ' . $table . "\n";
        $close = '-- <<< TABLE ' . $table . "\n";

        $start = strpos($sql, "\n" . $open);
        if ($start === false) {
            return '';
        }
        $start++;

        $end = strpos($sql, $close, $start);
        if ($end === false) {
            return '';
        }

        return substr($sql, $start, $end - $start + strlen($close));
    }

    /**
     * The dump's session settings, without its data.
     *
     * A section imported on its own still needs them. `SQL_MODE` is what keeps
     * backslash escapes meaning what the emitter meant, and `time_zone` is the
     * one whose absence has no symptom: import a section in the host's own zone
     * and every `TIMESTAMP` in it shifts by that offset, consistently, with
     * nothing about the result looking wrong. This is the preamble the runbook
     * tells an operator to paste in front of a single-table import.
     */
    protected static function sessionPreamble(string $sql): string
    {
        $end = strpos($sql, "\n-- >>> TABLE ");

        if ($end === false) {
            throw new RuntimeException('This does not look like a Club Bar dump: no table markers.');
        }

        // Everything before the first table, minus the `ALTER DATABASE` line,
        // which names no database and would therefore retarget whichever schema
        // the operator happens to be in. Harmless in the round trip and wrong
        // to put in a runbook.
        $preamble = substr($sql, 0, $end);

        return (string) preg_replace('/^ALTER DATABASE .*$\n?/m', '', $preamble);
    }
}
