import 'dart:convert';
import 'package:drift/drift.dart' hide Column;
import '../database/database.dart';
import '../models/product_dto.dart';
import '../models/category_dto.dart';

class ProductsRepository {
  final RuderbarDatabase _db;

  ProductsRepository(this._db);

  /// Get all active categories with their active products
  Future<List<(CategoriesCacheData, List<ProductsCacheData>)>> getActiveCategoriesWithProducts() async {
    final categories = await (_db.select(_db.categoriesCache)
          ..where((c) => c.isActive.equals(1))
          ..orderBy([(c) => OrderingTerm(expression: c.displayOrder)]))
        .get();

    final result = <(CategoriesCacheData, List<ProductsCacheData>)>[];

    for (final category in categories) {
      final products = await (_db.select(_db.productsCache)
            ..where((p) => p.categoryId.equals(category.id) & p.isActive.equals(1)))
          .get();

      if (products.isNotEmpty) {
        result.add((category, products));
      }
    }

    return result;
  }

  /// Get product by ID
  Future<ProductsCacheData?> getProduct(String productId) async {
    return (_db.select(_db.productsCache)
          ..where((p) => p.id.equals(productId)))
        .getSingleOrNull();
  }

  /// Extract translated name from JSON based on language
  String getTranslatedName(String jsonNames, String language) {
    try {
      final names = jsonDecode(jsonNames) as Map<String, dynamic>;
      return names[language]?.toString() ?? names['de']?.toString() ?? 'Unknown';
    } catch (e) {
      return 'Unknown';
    }
  }

  /// Upsert categories from sync response
  Future<void> upsertCategories(List<CategoryDTO> categories) async {
    for (final dto in categories) {
      await _db.into(_db.categoriesCache).insertOnConflictUpdate(
        CategoriesCacheCompanion(
          id: Value(dto.id),
          names: Value(jsonEncode(dto.names)),
          displayOrder: Value(dto.displayOrder),
          isActive: Value(dto.isActive ? 1 : 0),
          iconName: Value(dto.iconName),
          updatedAt: Value(dto.updatedAt),
        ),
      );
    }
  }

  /// Upsert products from sync response
  Future<void> upsertProducts(List<ProductDTO> products) async {
    for (final dto in products) {
      await _db.into(_db.productsCache).insertOnConflictUpdate(
        ProductsCacheCompanion(
          id: Value(dto.id),
          categoryId: Value(dto.categoryId),
          names: Value(jsonEncode(dto.names)),
          descriptions: Value(dto.descriptions != null ? jsonEncode(dto.descriptions) : null),
          priceCents: Value(dto.priceCents),
          isActive: Value(dto.isActive ? 1 : 0),
          iconName: Value(dto.iconName),
          updatedAt: Value(dto.updatedAt),
        ),
      );
    }
  }

  /// Clear all product/category cache
  Future<void> clearCache() async {
    await _db.delete(_db.productsCache).go();
    await _db.delete(_db.categoriesCache).go();
  }
}
