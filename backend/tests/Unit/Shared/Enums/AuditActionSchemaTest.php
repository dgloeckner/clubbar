<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Enums;

use App\Shared\Enums\AuditAction;
use PHPUnit\Framework\TestCase;

/**
 * `audit_log.action` is a MariaDB ENUM, and this is the test that keeps it in
 * step with the PHP enum (#779).
 *
 * Adding a case to {@see AuditAction} without extending the column is not a
 * cosmetic omission. MariaDB in strict mode refuses the INSERT, so the audit
 * write throws — and the writes this codebase cares most about audit *inside*
 * the transaction they describe, deliberately, so that an unaudited approval
 * cannot exist. The forgotten migration therefore surfaces as "approving a
 * registration returns a 500", nowhere near the enum, and only against a real
 * database: every unit test in the suite mocks `AuditService` and stays green.
 *
 * So the check runs here, against the migrations as text, with no database at
 * all: whatever the newest `MODIFY COLUMN action ENUM(...)` lists is what the
 * column will hold, and it has to cover every case the code can write.
 */
final class AuditActionSchemaTest extends TestCase
{
    private const MIGRATIONS_DIR = __DIR__ . '/../../../../db/migrations';

    public function test_every_audit_action_exists_in_the_column_definition(): void
    {
        $declared = $this->columnValues();

        $missing = array_values(array_diff(
            array_map(static fn(AuditAction $a): string => $a->value, AuditAction::cases()),
            $declared,
        ));

        self::assertSame(
            [],
            $missing,
            "AuditAction cases with no value in audit_log's ENUM. A write of one of these is refused by "
            . 'MariaDB, which rolls back the transaction it was audited inside. Add a migration that '
            . "extends the column:\n  " . implode("\n  ", $missing),
        );
    }

    /**
     * The reverse direction, which is a different mistake: a value left in the
     * column after its case was removed. Harmless to the database and a lie to
     * whoever reads the schema to find out what this system records.
     */
    public function test_the_column_declares_nothing_the_code_cannot_write(): void
    {
        $cases = array_map(static fn(AuditAction $a): string => $a->value, AuditAction::cases());

        self::assertSame([], array_values(array_diff($this->columnValues(), $cases)));
    }

    /**
     * The values the newest migration to touch the column leaves it holding.
     *
     * Migrations are applied in filename order and each of these ALTERs
     * *replaces* the whole list, so the last one wins outright — reading only
     * that one is what makes this test agree with the database rather than with
     * the union of everything ever declared.
     *
     * @return list<string>
     */
    private function columnValues(): array
    {
        $files = glob(self::MIGRATIONS_DIR . '/*.sql') ?: [];
        sort($files);

        $latest = null;
        foreach ($files as $file) {
            $sql = (string) file_get_contents($file);
            if (preg_match_all('/MODIFY COLUMN action ENUM\s*\((.*?)\)\s*NOT NULL/s', $sql, $matches)) {
                $latest = end($matches[1]);
            }
        }

        self::assertNotNull($latest, 'No migration defines audit_log.action; the search pattern must be wrong.');

        preg_match_all("/'([a-z0-9_]+)'/", $latest, $values);

        return $values[1];
    }
}
