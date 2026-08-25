<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Time\Utc;
use PDO;
use RuntimeException;

/**
 * A throwaway schema to restore an archive into, and a guard on dropping it.
 *
 * #692 has to prove a restore, and a restore that lands anywhere other than an
 * empty schema proves nothing: importing into `clubbar` would compare the
 * database against itself, and the archive's `DROP TABLE IF EXISTS` would take
 * the suite's own fixtures with it on the way.
 *
 * So the round-trip creates a schema, imports into it, compares, and drops it.
 * That last verb is why this class exists rather than three lines in a test.
 *
 * ### The rule this obeys
 *
 * CLAUDE.md's *Destructive Test Cleanup* rule was written after a `tearDown()`
 * pointed at `/` because `setUp()` skipped before assigning the property it
 * deleted from. `DROP DATABASE` is the same shape of mistake with a worse blast
 * radius: an unassigned property is `''`, and the *only* thing between `''` and
 * a dropped production schema is whatever the cleanup checks first.
 *
 * Two properties, both required, and both checked at the drop rather than at
 * the create:
 *
 * 1. **The name is generated here and never read from the server.** There is no
 *    code path that discovers a schema and then drops it, so cleanup can only
 *    ever point at something this class made.
 * 2. **The name must match {@see NAME_PATTERN}.** An empty string does not
 *    match. Neither does `clubbar`, `mysql`, `information_schema`, or anything
 *    an operator would recognise — the prefix and twelve hex characters are not
 *    a name anything else has. A non-matching name is left alone rather than
 *    dropped, exactly as {@see TempTree} leaves a path outside the temp
 *    directory alone.
 *
 * ### Why root
 *
 * `CREATE DATABASE` is not among the privileges `docker/init.sql` grants the
 * `clubbar` user, which is correct — the application must never be able to make
 * a schema. Tests are the one caller that legitimately needs to, so this
 * connects as root, which `docker-compose.yml` and the CI service container
 * both define identically (`root`/`root`). The application's own connection is
 * untouched and stays unprivileged.
 *
 * Part of #692, epic #686.
 */
trait ScratchSchema
{
    /**
     * The only shape of name this trait will create — or drop.
     *
     * Anchored at both ends. The twelve hex characters come from
     * `random_bytes()`, so two concurrently running suites cannot collide and
     * neither can ever name a schema somebody cares about.
     */
    private const NAME_PATTERN = '/^clubbar_scratch_[0-9a-f]{12}$/';

    /**
     * Create an empty schema and return a connection already inside it.
     *
     * The charset and collation are taken from the schema under test rather
     * than from the server's defaults: a restore into a `latin1` schema would
     * mangle every umlaut in the club's member names and the comparison would
     * then be measuring the wrong thing.
     *
     * @return array{0: PDO, 1: string} the connection, and the schema's name
     */
    protected static function createScratchSchema(PDO $source): array
    {
        $name = 'clubbar_scratch_' . bin2hex(random_bytes(6));

        // Belt and braces: the name was just generated to match, so a failure
        // here is a bug in this method rather than something a caller can cause.
        self::assertScratchName($name);

        $charset = $source->query(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS co
             FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
        )->fetch(PDO::FETCH_ASSOC);

        $root = self::rootConnection();
        $root->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s',
            $name,
            $charset['cs'],
            $charset['co']
        ));

        return [self::rootConnection($name), $name];
    }

    /**
     * Drop a scratch schema, and nothing else.
     *
     * A no-op for an empty name, or for any name that is not one this trait
     * generated. That is the whole safety property: `tearDown()` runs after a
     * skipped test, when the name may never have been assigned.
     */
    protected static function dropScratchSchema(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return;
        }

        self::rootConnection()->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $name));
    }

    /**
     * @throws RuntimeException when the name is not one this trait would create
     */
    private static function assertScratchName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new RuntimeException(sprintf(
                'Refusing to touch schema %s: only names this trait generated may be created or '
                . 'dropped (CLAUDE.md, destructive test cleanup).',
                var_export($name, true)
            ));
        }
    }

    /**
     * A root connection, optionally already inside a schema.
     *
     * Pinned to UTC like every other connection in the project — a restore read
     * back through a non-UTC session would shift all 53 `TIMESTAMP` columns and
     * the comparison would fail for a reason that has nothing to do with the
     * dumper (`DatabaseDump::assertReadingInUtc()` makes the same point about
     * the write side).
     */
    private static function rootConnection(?string $schema = null): PDO
    {
        $host = getenv('DB_HOST') ?: 'database';
        $user = getenv('DB_ROOT_USER') ?: 'root';
        $password = getenv('DB_ROOT_PASS') ?: 'root';

        $dsn = sprintf('mysql:host=%s;charset=utf8mb4', $host)
            . ($schema === null ? '' : sprintf(';dbname=%s', $schema));

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // The one place this project deliberately *emulates* prepares, and
            // the reason is the restore. A native prepare is one statement by
            // definition, so `query()` on a connection using them parses only
            // the first line of a dump and rejects the second — which reads as
            // a syntax error in the archive rather than as a limitation of the
            // handle. Emulated, the script reaches the server whole and MariaDB
            // decides where each statement ends, which is the only parser that
            // can be right about a value containing a semicolon.
            //
            // Nothing binds a parameter on this connection: it runs a dump and
            // reads `information_schema`. The reason
            // {@see \App\Shared\Database\ConnectionFactory} turns emulation
            // off — quoted LIMIT/OFFSET integers — cannot arise here.
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => sprintf("SET time_zone = '%s'", Utc::SQL_OFFSET),
        ]);
    }
}
