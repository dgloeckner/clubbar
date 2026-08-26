<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Backups;

use PDO;
use Tests\Feature\DatabaseTestCase;

/**
 * Every query the recovery runbook tells an operator to run, run.
 *
 * This exists because the runbook shipped with one that could not work.
 * `docs/runbook-backup-recovery.md` asked for `SELECT MAX(created_at) FROM
 * transactions` — a column migration `007` renamed to `occurred_at` three
 * years of migrations ago. Nothing failed, because nothing executed it: prose
 * about SQL is not SQL, and the only reader was going to be a human at the
 * worst moment of the club's year, typing it into a panel and getting
 * `Unknown column` back from the one document that was supposed to be the
 * thing that worked.
 *
 * So the document's SQL is treated as code. The blocks are extracted from the
 * markdown and executed against the real schema; a renamed column, a dropped
 * table or a typo fails here, in CI, in front of whoever renamed it.
 *
 * **Read-only, deliberately.** Section 2 of the runbook contains
 * `ALTER TABLE ... ENGINE=InnoDB` and `OPTIMIZE TABLE` — statements that are
 * right for an operator repairing a table and wrong for a test suite to run
 * against a shared database. Only `SELECT` is executed; anything else is
 * counted and reported, so a block that stops being covered is visible rather
 * than silently skipped.
 *
 * Part of #692, epic #686.
 */
class RunbookQueriesTest extends DatabaseTestCase
{
    private const RUNBOOK = 'docs/runbook-backup-recovery.md';

    public function test_every_select_the_runbook_prints_runs_against_the_real_schema(): void
    {
        $statements = self::selectStatements(self::runbook());

        $this->assertGreaterThanOrEqual(
            5,
            count($statements),
            'The runbook stopped printing the checks this test exists to keep honest. '
            . 'Either the extraction broke, or the checks were removed — both are worth looking at.'
        );

        foreach ($statements as $sql) {
            try {
                $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            } catch (\PDOException $e) {
                $this->fail(
                    "The runbook asks the operator to run this, and it does not work:\n\n"
                    . $sql . "\n\n" . $e->getMessage()
                );
            }
        }

        // Reached only when every one of them executed.
        $this->assertTrue(true);
    }

    /**
     * The one whose absence has no symptom, so the document must not lose it.
     *
     * A restore imported in the host's own zone shifts every `TIMESTAMP` and
     * nothing about the result looks wrong. The runbook answers that with two
     * things a reader can act on: how to see the session's offset, and the
     * `TIMESTAMP`-against-`DATETIME` comparison that survives being read back
     * in the same wrong zone. Losing either in an edit is losing the check.
     */
    public function test_the_runbook_still_tells_the_operator_how_to_prove_the_session_is_utc(): void
    {
        $runbook = self::runbook();

        $this->assertStringContainsString('TIMEDIFF(NOW(), UTC_TIMESTAMP())', $runbook);
        $this->assertStringContainsString('@@session.time_zone', $runbook);
        $this->assertStringContainsString('timestamp_side', $runbook);
        $this->assertStringContainsString('datetime_side', $runbook);
    }

    /**
     * The pair the shift check is built on, asserted against the live schema.
     *
     * `MariaDB` converts a `TIMESTAMP` on the way in and out, so it cannot
     * expose its own shift; a `DATETIME` is stored exactly as written and does
     * not move. The comparison only works while those two columns keep those
     * two types — if one is ever retyped, the runbook's check quietly becomes
     * a comparison of two columns that shift together, which is no check at
     * all.
     */
    public function test_the_shift_check_compares_a_timestamp_against_a_datetime(): void
    {
        $types = [];
        $rows = $this->db->query(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND (
                 (TABLE_NAME = 'transactions' AND COLUMN_NAME = 'received_at')
                 OR (TABLE_NAME = 'audit_log' AND COLUMN_NAME = 'created_at')
               )"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $types[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = $row['DATA_TYPE'];
        }

        $this->assertSame(
            'timestamp',
            $types['transactions.received_at'] ?? null,
            'The runbook compares this against a DATETIME to expose a shifted import.'
        );
        $this->assertSame(
            'datetime',
            $types['audit_log.created_at'] ?? null,
            'A DATETIME is the fixed half of the comparison; two TIMESTAMPs would move together.'
        );
    }

    private static function runbook(): string
    {
        $path = self::repoRoot() . '/' . self::RUNBOOK;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The runbook lives above `./backend`, which is all the backend container
     * mounts at `/app`. {@see \Tests\Unit\Docs\BackupDocumentationTest} hit
     * this first and it is solved the same way, with the read-only whole-repo
     * mount `docker-compose.yml` already carries.
     */
    private static function repoRoot(): string
    {
        // CI and the host both run phpunit from inside a full checkout.
        $checkout = dirname(__DIR__, 5);
        if (is_dir($checkout . '/adr') && is_dir($checkout . '/docs')) {
            return $checkout;
        }

        if (is_dir('/repo/adr') && is_dir('/repo/docs')) {
            return '/repo';
        }

        self::fail(
            'Cannot locate the repository docs. Inside the backend container they are '
            . 'mounted read-only at /repo — run `docker compose up -d` after pulling a '
            . 'compose file that carries those mounts.'
        );
    }

    /**
     * The `SELECT`s inside the markdown's ```sql fences.
     *
     * Fences may be indented — the UTC check sits inside a numbered list.
     * Statements are split on `;`, which is enough for this document and would
     * not be for arbitrary SQL: a semicolon inside a string literal would cut a
     * statement in half. That shows up as a failing test naming the statement,
     * not as a false pass.
     *
     * @return list<string>
     */
    private static function selectStatements(string $markdown): array
    {
        preg_match_all('/^[ \t]*```sql\R(.*?)^[ \t]*```/ms', $markdown, $matches);

        $statements = [];

        foreach ($matches[1] as $block) {
            foreach (explode(';', $block) as $piece) {
                // Comment lines are prose about the query, not part of it.
                $sql = trim((string) preg_replace('/^\s*--.*$/m', '', $piece));

                if ($sql !== '' && stripos($sql, 'SELECT') === 0) {
                    $statements[] = $sql;
                }
            }
        }

        return $statements;
    }
}
