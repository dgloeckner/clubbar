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
])
class RuderbarDatabase extends _$RuderbarDatabase {
  RuderbarDatabase() : super(_openConnection());

  @override
  int get schemaVersion => 2;

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

      return NativeDatabase(
        file,
        setup: (db) {
          db.execute('PRAGMA foreign_keys = ON');
        },
      );
    });
  }
}
