import 'package:drift/drift.dart';
import 'categories_cache.dart';

class ProductsCache extends Table {
  TextColumn get id => text()();
  TextColumn get categoryId => text().references(CategoriesCache, #id)();
  TextColumn get names => text()(); // JSON: {"de": "...", "en": "..."}
  TextColumn get descriptions => text().nullable()(); // JSON
  IntColumn get priceCents => integer()();
  IntColumn get isActive => integer().withDefault(Constant(1))();
  TextColumn get iconName => text().nullable()(); // Backend icon enum: PilsIcon, WeizenIcon, etc.
  TextColumn get updatedAt => text()();

  @override
  Set<Column> get primaryKey => {id};
}
