import 'package:drift/drift.dart';

class CategoriesCache extends Table {
  TextColumn get id => text()();
  TextColumn get names => text()(); // JSON: {"de": "...", "en": "..."}
  IntColumn get isActive => integer().withDefault(Constant(1))();
  TextColumn get iconName => text().nullable()(); // Canonical kebab-case icon name (e.g., "beer-pils", "sauna-cabin")
  TextColumn get updatedAt => text()();

  /// Server tombstone (ISO 8601). Set means the category was deleted in the admin
  /// panel; it and all its products are hidden from the purchase UI.
  ///
  /// The row itself is never removed — see the same field on `ProductsCache`.
  /// Products reference the category, and those products are in turn referenced
  /// by local transactions.
  TextColumn get deletedAt => text().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}
