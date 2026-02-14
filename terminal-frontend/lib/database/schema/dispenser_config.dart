import 'package:drift/drift.dart';

/// Dispenser configuration stored in SQLite.
/// Uses key-value pattern similar to SyncState for flexibility.
class DispenserConfig extends Table {
  TextColumn get key => text()();
  TextColumn get value => text()();

  @override
  Set<Column> get primaryKey => {key};
}
