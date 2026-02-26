import 'package:drift/drift.dart';

class CategoriesCache extends Table {
  TextColumn get id => text()();
  TextColumn get names => text()(); // JSON: {"de": "...", "en": "..."}
  IntColumn get isActive => integer().withDefault(Constant(1))();
  TextColumn get iconName => text().nullable()(); // Backend icon enum: CategoryIcon, CategoryFolderIcon, etc.
  TextColumn get updatedAt => text()();

  @override
  Set<Column> get primaryKey => {id};
}
