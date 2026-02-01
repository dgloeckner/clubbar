import 'package:drift/drift.dart';

class CategoriesCache extends Table {
  TextColumn get id => text()();
  TextColumn get names => text()(); // JSON: {"de": "...", "en": "..."}
  IntColumn get displayOrder => integer()();
  IntColumn get isActive => integer().withDefault(Constant(1))();
  TextColumn get updatedAt => text()();

  @override
  Set<Column> get primaryKey => {id};
}
