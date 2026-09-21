<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Enums;

use App\Modules\Notifications\Enums\MailKind;
use PHPUnit\Framework\TestCase;

/**
 * `mail_outbox.kind` is a MariaDB ENUM, and this is the test that keeps it in
 * step with the PHP enum (#956).
 *
 * The sibling of {@see \Tests\Unit\Shared\Enums\AuditActionSchemaTest}, written
 * after the mistake it guards actually happened: {@see MailKind} grew
 * `dispenser_attention`, four exhaustive `match` expressions made the compiler
 * check four things about it, a builder claimed it, and **nothing** checked
 * that the column could hold it. MariaDB in strict mode truncates the value and
 * refuses the INSERT, so the scan that queues the notice throws — and because
 * every scan on the cron tick swallows its own exceptions so the drain keeps
 * running, the whole feature failed as one line in a cron log:
 *
 *     Dispenser attention scan: nothing due (scan failed: … Data truncated …)
 *
 * Every unit test stayed green, because they all mock the outbox. Only a real
 * drain against a real database could see it, which is the definition of a
 * failure worth a cheap test.
 *
 * So the check runs here, against the migrations as text and with no database:
 * whatever the newest `MODIFY COLUMN kind ENUM(...)` lists is what the column
 * will hold, and it has to cover every kind the code can queue.
 */
final class MailKindSchemaTest extends TestCase
{
    private const MIGRATIONS_DIR = __DIR__ . '/../../../../../db/migrations';

    public function test_every_mail_kind_exists_in_the_column_definition(): void
    {
        $declared = $this->columnValues();

        $missing = array_values(array_diff(
            array_map(static fn (MailKind $kind): string => $kind->value, MailKind::cases()),
            $declared,
        ));

        self::assertSame(
            [],
            $missing,
            'MailKind cases with no value in mail_outbox.kind. Queuing one of these is refused by '
            . "MariaDB, and the scan that tried it fails silently. Add a migration that extends the "
            . "column:\n  " . implode("\n  ", $missing),
        );
    }

    /**
     * The reverse direction, which is a different mistake: a value left in the
     * column after its case was removed — harmless to the database and a lie to
     * whoever reads the schema to find out what this system sends.
     *
     * `payment_request` is the precedent that makes this worth asserting: it
     * was removed from both, together, by migration 036.
     */
    public function test_the_column_declares_nothing_the_code_can_never_queue(): void
    {
        $cases = array_map(static fn (MailKind $kind): string => $kind->value, MailKind::cases());

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
            if (preg_match_all('/MODIFY COLUMN kind ENUM\s*\((.*?)\)\s*NOT NULL/s', $sql, $matches)) {
                $latest = end($matches[1]);
            }
        }

        self::assertNotNull($latest, 'No migration defines mail_outbox.kind; the search pattern must be wrong.');

        preg_match_all("/'([a-z0-9_]+)'/", $latest, $values);

        return $values[1];
    }
}
