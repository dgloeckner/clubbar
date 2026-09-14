<?php

declare(strict_types=1);

namespace App\Shared\Sepa;

use PDO;

/**
 * The counter row, drawn from with the portable `LAST_INSERT_ID()` idiom.
 *
 * Not `CREATE SEQUENCE` (MariaDB >= 10.3 only, and `docs/deployment.md` promises
 * MySQL 5.7 — ADR-0038), and not `SELECT … FOR UPDATE` (two statements where one
 * does). See migration 069 for the full reasoning.
 */
final class MandateReferenceCounterRepository implements MandateReferenceCounter
{
    public function __construct(private PDO $db) {}

    public function next(): int
    {
        // One statement, so no window for a second connection to read the same
        // value: InnoDB holds the row's write lock until this transaction ends.
        // `LAST_INSERT_ID(expr)` stores the new value for *this connection*
        // only, which is what makes the read below safe under concurrency.
        $update = $this->db->prepare(
            'UPDATE mandate_reference_counter SET value = LAST_INSERT_ID(value + 1) WHERE id = 1'
        );
        $update->execute();

        // A missing counter row is the one failure that must not be quiet:
        // `LAST_INSERT_ID()` after an UPDATE that matched nothing still answers
        // — with whatever this connection last stored, which would mint a
        // duplicate reference rather than an error.
        if ($update->rowCount() < 1) {
            throw new \RuntimeException(
                'mandate_reference_counter has no row id=1; migration 069 did not run or the row was deleted'
            );
        }

        return (int) $this->db->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }
}
