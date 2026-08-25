<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use PDO;
use Tests\Feature\DatabaseTestCase;
use Tests\Support\ScratchSchema;

/**
 * The guard on {@see ScratchSchema}, which is the only thing standing between a
 * `tearDown()` and a dropped schema.
 *
 * {@see \Tests\Unit\Support\TempTreeTest} is this file's precedent and its
 * reason: CLAUDE.md's destructive-cleanup rule exists because a cleanup path
 * pointed somewhere its test never created, and the property that stops it
 * happening again is worth a test of its own rather than a comment.
 *
 * The case that matters most is the cheapest one to write — `dropScratchSchema('')`,
 * the unassigned property after a skipped `setUp()`. It must do nothing. Every
 * other assertion here exists so that one cannot regress quietly.
 *
 * Part of #692, epic #686.
 */
class ScratchSchemaTest extends DatabaseTestCase
{
    use ScratchSchema;

    /** @var list<string> */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $name) {
            self::dropScratchSchema($name);
        }
        $this->created = [];

        parent::tearDown();
    }

    /**
     * The property that makes the trait safe to call from a cleanup path.
     *
     * An unassigned `string` property is `''`, and `DROP DATABASE ``` would be
     * a syntax error rather than a catastrophe — but only by luck. This asserts
     * the trait refuses before it builds any SQL at all, and, crucially, that
     * the database it was pointed away from is still there afterwards.
     */
    public function test_dropping_refuses_every_name_it_did_not_generate(): void
    {
        $refused = [
            '',                              // the unassigned property
            'clubbar',                       // the database under test
            'mysql',
            'information_schema',
            'clubbar_scratch_',              // prefix alone, no suffix
            'clubbar_scratch_XYZ',           // not hex
            'clubbar_scratch_abc',           // too short
            'clubbar_scratch_0123456789abc', // too long
            'x_clubbar_scratch_0123456789ab', // not anchored at the start
            'clubbar_scratch_0123456789ab_x', // not anchored at the end
        ];

        foreach ($refused as $name) {
            self::dropScratchSchema($name);
        }

        // The point of the whole file: the schema this suite runs against is
        // still here, with its tables, after every one of those calls.
        $tables = (int) $this->db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        )->fetchColumn();

        $this->assertGreaterThan(
            0,
            $tables,
            'The database under test lost its tables to a refused drop — the guard did not hold.'
        );
    }

    /**
     * Create, use, drop: the whole lifecycle the round-trip depends on.
     */
    public function test_a_scratch_schema_is_created_empty_and_dropped_again(): void
    {
        [$scratch, $name] = self::createScratchSchema($this->db);
        $this->created[] = $name;

        $this->assertMatchesRegularExpression('/^clubbar_scratch_[0-9a-f]{12}$/', $name);

        $this->assertSame(
            0,
            (int) $scratch->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
            )->fetchColumn(),
            'A scratch schema must start empty, or the restore would be compared against leftovers.'
        );

        $this->assertSame(
            $name,
            (string) $scratch->query('SELECT DATABASE()')->fetchColumn(),
            'The returned connection must already be inside the scratch schema.'
        );

        self::dropScratchSchema($name);
        array_pop($this->created);

        $this->assertSame(
            0,
            (int) $this->db->query(
                'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' .
                $this->db->quote($name)
            )->fetchColumn(),
            'The scratch schema outlived its drop.'
        );
    }

    /**
     * The restore compares text, so the scratch schema has to encode text the
     * same way the source does. A `latin1` scratch would mangle every umlaut in
     * the club's member names and the round-trip would report a dumper bug that
     * is really a test-harness bug.
     */
    public function test_the_scratch_schema_inherits_the_source_charset_and_collation(): void
    {
        $source = $this->db->query(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS co
             FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
        )->fetch(PDO::FETCH_ASSOC);

        [$scratch, $name] = self::createScratchSchema($this->db);
        $this->created[] = $name;

        $actual = $scratch->query(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS cs, DEFAULT_COLLATION_NAME AS co
             FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()'
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertSame($source, $actual);
    }
}
