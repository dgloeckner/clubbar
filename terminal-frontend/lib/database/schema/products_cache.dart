import 'package:drift/drift.dart';
import 'categories_cache.dart';

class ProductsCache extends Table {
  TextColumn get id => text()();
  TextColumn get categoryId => text().references(CategoriesCache, #id)();
  TextColumn get names => text()(); // JSON: {"de": "...", "en": "..."}
  TextColumn get descriptions => text().nullable()(); // JSON
  IntColumn get priceCents => integer()();
  IntColumn get isActive => integer().withDefault(Constant(1))();
  IntColumn get requiresDispenser => integer().withDefault(Constant(0))(); // 1=requires dispenser, 0=normal product
  TextColumn get iconName => text().nullable()(); // Canonical kebab-case icon name (e.g., "beer-pils")
  TextColumn get updatedAt => text()();

  @override
  Set<Column> get primaryKey => {id};
}
