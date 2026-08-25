<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Domain;

use App\Modules\Backups\Domain\TableClass;
use App\Modules\Backups\Domain\TableClassification;
use App\Modules\Backups\Domain\UnclassifiedTableException;
use PHPUnit\Framework\TestCase;

/**
 * What belongs in a backup, decided per table and never by default.
 *
 * The rule this file exists to hold: **a table nobody classified is an error,
 * not a guess.** Defaulting to "back it up" would quietly grow the archive with
 * whatever the next migration adds; defaulting to "skip it" would quietly lose
 * it. Either way the mistake is invisible until a restore, which is the worst
 * possible moment to find out. So `for()` throws, and the schema test in
 * tests/Feature is what makes that throw reachable by a real migration rather
 * than only by a typo.
 *
 * Part of #688, epic #686.
 */
class TableClassificationTest extends TestCase
{
    public function test_business_data_is_backed_up_in_full(): void
    {
        $this->assertSame(TableClass::FULL, TableClassification::for('members'));
        $this->assertSame(TableClass::FULL, TableClassification::for('transactions'));
        $this->assertSame(TableClass::FULL, TableClassification::for('mandates'));
        $this->assertSame(TableClass::FULL, TableClassification::for('audit_log'));
    }

    /**
     * The migration ledger decides what the runner believes is applied. Restore
     * without it and the next upgrade replays every migration against a
     * populated database.
     */
    public function test_the_migration_ledger_is_backed_up_in_full(): void
    {
        $this->assertSame(TableClass::FULL, TableClassification::for('_migrations'));
    }

    /**
     * Roughly 20k rows of German bank codes, identical in every installation
     * and reimportable from backend/bin/import-bank-codes.php. Carrying them in
     * every nightly archive would dominate its size for no recovery value.
     */
    public function test_bulk_reference_data_keeps_its_schema_but_not_its_rows(): void
    {
        $this->assertSame(TableClass::SCHEMA_ONLY, TableClassification::for('bank_codes'));
    }

    /**
     * Rate-limit counters and IP sightings describe the last few hours of
     * traffic. Restoring them restores nothing anyone wants.
     */
    public function test_ephemeral_tables_are_skipped_entirely(): void
    {
        $this->assertSame(TableClass::SKIP, TableClassification::for('login_attempts'));
        $this->assertSame(TableClass::SKIP, TableClassification::for('terminal_auth_attempts'));
        $this->assertSame(TableClass::SKIP, TableClassification::for('terminal_ip_sightings'));
    }

    public function test_an_unclassified_table_is_refused_rather_than_guessed(): void
    {
        $this->expectException(UnclassifiedTableException::class);
        $this->expectExceptionMessageMatches('/loyalty_points/');

        TableClassification::for('loyalty_points');
    }

    /**
     * A dropped table left behind in the map is the mirror-image mistake: it
     * never fires, so nothing tells you the map has drifted. `sessions` and
     * `mandate_documents` are the live examples — both were created by an early
     * migration and dropped later (044 and 023).
     */
    public function test_the_map_carries_no_table_the_schema_no_longer_has(): void
    {
        $classified = TableClassification::tables();

        $this->assertNotContains('sessions', $classified);
        $this->assertNotContains('mandate_documents', $classified);
    }

    public function test_every_classified_table_is_named_only_once(): void
    {
        $classified = TableClassification::tables();

        $this->assertSame(
            array_values(array_unique($classified)),
            array_values($classified),
            'A table listed twice means two answers to what belongs in a backup.'
        );
    }
}
