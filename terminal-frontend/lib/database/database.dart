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
/// Drop every delta-sync cursor, so the next sync of each stream asks the
/// backend for the full set rather than for what changed since last time.
///
/// Used by a migration that adds a column only a sync can fill: without it the
/// column stays NULL on every row already cached, because an unedited row is
/// never part of a delta.
///
/// Both key shapes are removed — `last_*_sync_cursor` is what the sync reads,
/// and `last_*_sync_time` is the ISO timestamp kept beside it for display. A
/// cursor left behind for one stream would leave exactly that stream unfilled.
Future<void> _resetDeltaSyncCursors(Migrator m) async {
  await m.database.customStatement(
    'DELETE FROM "sync_state" WHERE "key" IN ('
    "'last_members_sync_cursor', 'last_members_sync_time', "
    "'last_products_sync_cursor', 'last_products_sync_time', "
    "'last_categories_sync_cursor', 'last_categories_sync_time')",
  );
}

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
  int get schemaVersion => 14;

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
          if (from < 13) {
            // The product's size, in whole millilitres (ADR-0056).
            //
            // Nullable with no default, which is the correct state for every
            // row already in the cache: no product cached before this upgrade
            // is known to have a size, and until the next delta sync delivers
            // one the tile simply draws no badge. Nothing about the sale
            // changes — the size is a label, not a rule, unlike `min_age`.
            //
            // Added in place, beside whatever unsynced transactions this
            // terminal is holding: nothing here rewrites or moves a row in
            // `transactions_local`, so a sale rung before the upgrade is still
            // there to be uploaded after it.
            await _addColumnIfNotExists(
                m, 'products_cache', 'volume_ml', 'INTEGER');
          }
          if (from < 14) {
            // Backfill for every column migrations 10-13 added: the delta
            // cursors are dropped, so the next sync asks for everything.
            //
            // A column added by an upgrade lands NULL on the rows already in
            // the cache, and the comments above each of those migrations say
            // the next delta sync fills it in. It does not. The sync asks for
            // rows changed `since` the stored cursor, and a product nobody has
            // edited since the terminal last synced is not in that answer — so
            // its `volume_ml` stays NULL for as long as the product is left
            // alone. The club sees a size in the admin panel and no badge on
            // the terminal, with a sync that reports itself healthy (#940).
            //
            // Three columns were silently empty this way, and one of them
            // matters beyond cosmetics: `products_cache.min_age` NULL reads as
            // *unrestricted* (ADR-0045), so a product cached before schema 11
            // would be sold to anyone until somebody happened to edit it.
            // `members_cache.date_of_birth` and `credit_limit_cents` fail in
            // the safe direction, and `volume_ml` and `deleted_at` are why this
            // is a full reset rather than a products-only one.
            //
            // Deleting the key is what asks for a full sync: `_syncProducts()`
            // and its siblings read the cursor and send no `since` at all when
            // there is none. The cached rows are kept and overwritten in place
            // by the upsert, so nothing a terminal is holding — least of all an
            // unuploaded sale in `transactions_local` — depends on this.
            //
            // The rule this encodes, for the next migration that adds a synced
            // column: **adding one is not done until the cursor for that
            // stream is dropped in the same migration.**
            await _resetDeltaSyncCursors(m);
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
