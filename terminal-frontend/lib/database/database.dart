import 'dart:io';
import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

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
class RuderbarDatabase extends _$RuderbarDatabase {
  RuderbarDatabase() : super(_openConnection());

  /// Test constructor - uses in-memory database
  RuderbarDatabase.forTesting(super.executor);

  @override
  int get schemaVersion => 5;

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
        },
      );

  static QueryExecutor _openConnection() {
    // Initialize sqlite3 for FFI (macOS/Linux)
    if (Platform.isWindows || Platform.isLinux || Platform.isMacOS) {
      sqfliteFfiInit();
    }

    // Use LazyDatabase for async path resolution
    return LazyDatabase(() async {
      // Get the app support directory for persistent storage
      final appDir = await getApplicationSupportDirectory();
      final dbPath = p.join(appDir.path, 'ruderbar_terminal.db');
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
