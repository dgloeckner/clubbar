import 'dart:io';
import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as p;

import 'schema/members_cache.dart';
import 'schema/categories_cache.dart';
import 'schema/products_cache.dart';
import 'schema/transactions_local.dart';
import 'schema/sync_state.dart';
import 'schema/dispenser_config.dart';
import 'schema/dispenser_operations.dart';

part 'database.g.dart';

/// Add a column only if it doesn't already exist in the table.
Future<void> _addColumnIfNotExists(
    Migrator m, String table, String column, String type) async {
  final db = m.database;
  final result = await db.customSelect(
    'PRAGMA table_info($table)',
  ).get();
  final hasColumn = result.any((row) => row.read<String>('name') == column);
  if (!hasColumn) {
    await db.customStatement(
        'ALTER TABLE "$table" ADD COLUMN "$column" $type NULL');
  }
}

@DriftDatabase(tables: [
  MembersCache,
  CategoriesCache,
  ProductsCache,
  TransactionsLocal,
  SyncState,
  DispenserConfig,
  DispenserOperations,
])
class ClubBarDatabase extends _$ClubBarDatabase {
  ClubBarDatabase() : super(_openConnection());

  /// Test constructor - uses in-memory database
  ClubBarDatabase.forTesting(super.executor);

  @override
  int get schemaVersion => 12;

  @override
  MigrationStrategy get migration => MigrationStrategy(
        onCreate: (Migrator m) async {
          await m.createAll();
        },
        onUpgrade: (Migrator m, int from, int to) async {
          if (from < 2) {
            // Add icon_name column to categories_cache and products_cache.
            // Column may already exist if DB was created after schema change.
            await _addColumnIfNotExists(
                m, 'categories_cache', 'icon_name', 'TEXT');
            await _addColumnIfNotExists(
                m, 'products_cache', 'icon_name', 'TEXT');
          }
          if (from < 3) {
            // Add requires_dispenser to products_cache
            await _addColumnIfNotExists(
                m, 'products_cache', 'requires_dispenser', 'INTEGER NOT NULL DEFAULT 0');

            // Add dispenser metadata fields to transactions_local
            await _addColumnIfNotExists(
                m, 'transactions_local', 'dispenser_tx_id', 'TEXT');
            await _addColumnIfNotExists(
                m, 'transactions_local', 'dispenser_requested', 'INTEGER');
            await _addColumnIfNotExists(
                m, 'transactions_local', 'dispenser_actual', 'INTEGER');

            // Create dispenser_config table
            await m.createTable(dispenserConfig);

            // Initialize default dispenser configuration
            final db = m.database;
            await db.customInsert(
              'INSERT OR IGNORE INTO dispenser_config (key, value) VALUES (?, ?)',
              variables: [Variable.withString('enabled'), Variable.withString('0')],
            );
            await db.customInsert(
              'INSERT OR IGNORE INTO dispenser_config (key, value) VALUES (?, ?)',
              variables: [Variable.withString('base_url'), Variable.withString('')],
            );
            await db.customInsert(
              'INSERT OR IGNORE INTO dispenser_config (key, value) VALUES (?, ?)',
              variables: [Variable.withString('api_key'), Variable.withString('')],
            );
            await db.customInsert(
              'INSERT OR IGNORE INTO dispenser_config (key, value) VALUES (?, ?)',
              variables: [Variable.withString('timeout_ms'), Variable.withString('3000')],
            );
            await db.customInsert(
              'INSERT OR IGNORE INTO dispenser_config (key, value) VALUES (?, ?)',
              variables: [Variable.withString('poll_interval_ms'), Variable.withString('250')],
            );
          }
          if (from < 4) {
            // Create dispenser_operations table for crash recovery
            await m.createTable(dispenserOperations);
          }
          if (from < 5) {
            // Add reconciliation fields to dispenser_operations
            await _addColumnIfNotExists(
                m, 'dispenser_operations', 'transactions_created', 'INTEGER NOT NULL DEFAULT 0');
            await _addColumnIfNotExists(
                m, 'dispenser_operations', 'last_known_state', 'TEXT');
            await _addColumnIfNotExists(
                m, 'dispenser_operations', 'last_known_dispensed', 'INTEGER NOT NULL DEFAULT 0');
            await _addColumnIfNotExists(
                m, 'dispenser_operations', 'last_polled_at', 'TEXT');
            await _addColumnIfNotExists(
                m, 'dispenser_operations', 'polling_active', 'INTEGER NOT NULL DEFAULT 0');
          }
          if (from < 6) {
            await _addColumnIfNotExists(
                m, 'transactions_local', 'session_id', 'TEXT');
            await _addColumnIfNotExists(
                m, 'transactions_local', 'unit_price_cents', 'INTEGER');
          }
          if (from < 7) {
            // Remove display_order column — categories are now sorted lexicographically
            await m.database.customStatement(
                'ALTER TABLE "categories_cache" DROP COLUMN "display_order"');
          }
          if (from < 8) {
            // Canonicalize card UIDs already in the cache (issue #18). Writes
            // are normalized from now on, but a member synced before this
            // upgrade keeps its lower-case UID until the backend touches it
            // again — and would stay unscannable until then.
            //
            // OR IGNORE: card_uid is UNIQUE, so two rows differing only in case
            // would collide. Leaving such a row untouched keeps the migration
            // (and the terminal's startup) alive; the next sync of either
            // member resolves it.
            await m.database.customStatement(
                'UPDATE OR IGNORE "members_cache" SET "card_uid" = UPPER("card_uid") '
                'WHERE "card_uid" IS NOT NULL AND "card_uid" <> UPPER("card_uid")');
          }
          if (from < 9) {
            // Quarantine for permanently rejected sales (issue #152). A row
            // the backend refuses can never be stored by resubmitting it, so
            // it leaves the sync queue and waits here for staff to report.
            await _addColumnIfNotExists(
                m, 'transactions_local', 'quarantined_at', 'TEXT');
            await _addColumnIfNotExists(
                m, 'transactions_local', 'quarantine_reason', 'TEXT');
          }
          if (from < 10) {
            // Tombstones for members, categories and products. The backend has
            // always emitted `deleted_at` for all three; only the OpenAPI spec
            // omitted it for categories and products, so the terminal never saw
            // a deletion and kept selling deleted products forever.
            //
            // Deliberately a flag rather than a physical delete: every one of
            // these rows is a foreign-key target of a row the terminal keeps
            // indefinitely, and `PRAGMA foreign_keys = ON` would refuse the
            // delete and take the whole sync cycle down with it.
            //
            // Existing rows get NULL, which is exactly right — nothing cached
            // before this migration is known to be deleted.
            await _addColumnIfNotExists(m, 'members_cache', 'deleted_at', 'TEXT');
            await _addColumnIfNotExists(
                m, 'categories_cache', 'deleted_at', 'TEXT');
            await _addColumnIfNotExists(
                m, 'products_cache', 'deleted_at', 'TEXT');
          }
          if (from < 11) {
            // Jugendschutz (ADR-0045): the member's birth date and the
            // product's minimum age — the two halves of a check the terminal
            // has to make offline.
            //
            // Both nullable with no default, and that is the correct state for
            // a row cached before this migration: nothing already in the cache
            // is known to carry an age limit, and no member's birth date is
            // known until the next delta sync delivers it. Until then a
            // restricted product simply does not exist locally, and a member
            // with no cached date is refused any product that does — refusing
            // is the safe direction, and one sync cycle fixes it.
            await _addColumnIfNotExists(
                m, 'members_cache', 'date_of_birth', 'TEXT');
            await _addColumnIfNotExists(
                m, 'products_cache', 'min_age', 'INTEGER');
          }
          if (from < 12) {
            // The per-member credit ceiling (ADR-0047).
            //
            // Nullable with no default, and for a cache written before this
            // upgrade that is exactly right: null means "follow the club
            // default", so every member already stored keeps behaving the way
            // they did until a delta sync says otherwise. A `NOT NULL DEFAULT
            // 0` would read as "no ceiling for this member" and quietly grant
            // the whole membership unlimited credit on first launch.
            //
            // The column is added in place, beside the unsynced transactions
            // this terminal may be holding: nothing here rewrites or moves a
            // row in `transactions_local`, so a sale rung before the upgrade is
            // still there to be uploaded after it.
            await _addColumnIfNotExists(
                m, 'members_cache', 'credit_limit_cents', 'INTEGER');
          }
        },
      );

  static QueryExecutor _openConnection() {
    // Use LazyDatabase for async path resolution
    return LazyDatabase(() async {
      // Get the app support directory for persistent storage
      final appDir = await getApplicationSupportDirectory();
      final dbPath = p.join(appDir.path, 'clubbar_terminal.db');
      final file = File(dbPath);

      // Log database location for debugging
      // Note: Consider using logger package for production
      // ignore: avoid_print
      print('📁 Database location: $dbPath');

      return NativeDatabase(
        file,
        setup: (db) {
          db.execute('PRAGMA foreign_keys = ON');
        },
      );
    });
  }
}
