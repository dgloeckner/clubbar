import 'dart:io';
import 'package:drift/drift.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'schema/members_cache.dart';
import 'schema/categories_cache.dart';
import 'schema/products_cache.dart';
import 'schema/transactions_local.dart';
import 'schema/sync_state.dart';

part 'database.g.dart';

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
  int get schemaVersion => 1;

  static QueryExecutor _openConnection() {
    // Initialize sqlite3 for FFI (macOS/Linux)
    if (Platform.isWindows || Platform.isLinux || Platform.isMacOS) {
      sqfliteFfiInit();
    }
    
    return databaseFactoryFfi.openDatabase(
      'ruderbar_terminal.db',
      options: OpenDatabaseOptions(
        version: 1,
        onOpen: (db) async {
          await db.execute('PRAGMA foreign_keys = ON');
        },
      ),
    ) as QueryExecutor;
  }
}
