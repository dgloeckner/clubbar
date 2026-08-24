<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * What belongs in a backup, per table, decided explicitly.
 *
 * **Why this is a hand-maintained map and not a rule.** Any rule that could
 * derive the answer — name prefixes, row counts, "does it have a foreign key" —
 * would be guessing, and the cost of guessing wrong is asymmetric and silent: a
 * table wrongly skipped is missing on the day of a restore, and nothing before
 * that day says so. So each table is named, and a table nobody named raises
 * {@see UnclassifiedTableException} rather than getting a default.
 *
 * That throw is only useful if something reaches it. `TableClassificationTest`
 * covers the typo case; the schema test in tests/Feature is what makes a real
 * migration reach it, by comparing this map against the live database in both
 * directions — a new table missing here, and a dropped table lingering here.
 *
 * ADR-0049 decision 1. Part of #688, epic #686.
 */
final class TableClassification
{
    /**
     * @var array<string, TableClass>
     */
    private const MAP = [
        // The migration ledger. Restoring without it makes the runner believe
        // nothing is applied, and the next upgrade replays every migration
        // against a populated database.
        '_migrations' => TableClass::FULL,

        // Members, money, and the records that must survive ten years (§147 AO).
        'members' => TableClass::FULL,
        'mandates' => TableClass::FULL,
        'transactions' => TableClass::FULL,
        'settlements' => TableClass::FULL,
        'settlement_items' => TableClass::FULL,
        'settlement_announcements' => TableClass::FULL,
        'settlement_reversals' => TableClass::FULL,
        'audit_log' => TableClass::FULL,

        // Products and the bar.
        'products' => TableClass::FULL,
        'categories' => TableClass::FULL,

        // Accounts and access.
        'admin_users' => TableClass::FULL,
        'admin_user_roles' => TableClass::FULL,
        'encryption_keys' => TableClass::FULL,

        // Configuration. Small, and an installation restored without it is not
        // the same installation.
        'instance_config' => TableClass::FULL,
        'sepa_config' => TableClass::FULL,
        'mail_config' => TableClass::FULL,
        'credit_limit_config' => TableClass::FULL,

        // Terminals. `terminal_sync_cursors` is operational state rather than
        // business data, but it belongs in a *consistent* restore: ADR-0035
        // notes that carrying the instance forward is what keeps legitimate
        // disaster recovery from tripping the pairing guard, and ADR-0041 reads
        // a regressed cursor as an anomaly. Dropping the cursors would
        // manufacture both signals.
        'terminals' => TableClass::FULL,
        'terminal_sync_cursors' => TableClass::FULL,
        'terminal_anomalies' => TableClass::FULL,

        // Notifications. The outbox holds the recipient snapshot that proves
        // who was announced to (ADR-0038 rule 5), and cron_heartbeat is the
        // evidence that lifts the install gate (ADR-0038 decision 7) — restore
        // without it and finalize is blocked again.
        'mail_outbox' => TableClass::FULL,
        'cron_heartbeat' => TableClass::FULL,
        'jugendschutz_violation_acks' => TableClass::FULL,

        // The backup's own record (ADR-0049). `backup_runs` is included for a
        // reason worth stating: a restored installation that had lost its run
        // history would no longer know which private keys still open the
        // archives sitting on the remote, and the answer to "may we discard
        // key A?" would have to be guessed. `backup_keys` carries
        // `compromised_at`, which is a blocklist — losing it would silently
        // re-permit a key somebody deliberately retired.
        'backup_runs' => TableClass::FULL,
        'backup_keys' => TableClass::FULL,
        'backup_config' => TableClass::FULL,

        // ~20k rows of German bank codes, identical in every installation and
        // reimportable with backend/bin/import-bank-codes.php. Keeping the rows
        // would dominate the size of every nightly archive for no recovery
        // value; keeping the structure means a restore is still loadable.
        'bank_codes' => TableClass::SCHEMA_ONLY,

        // Rate-limit and sighting counters describing the last few hours.
        // Restoring them restores nothing anyone wants, and they are the
        // fastest-growing tables in the schema.
        'login_attempts' => TableClass::SKIP,
        'terminal_auth_attempts' => TableClass::SKIP,
        'terminal_ip_sightings' => TableClass::SKIP,
    ];

    /**
     * @throws UnclassifiedTableException when the table is not in the map
     */
    public static function for(string $table): TableClass
    {
        return self::MAP[$table] ?? throw UnclassifiedTableException::for($table);
    }

    /**
     * Every table this map decides about.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * @return list<string>
     */
    public static function tablesOfClass(TableClass $class): array
    {
        return array_values(array_keys(self::MAP, $class, true));
    }
}
