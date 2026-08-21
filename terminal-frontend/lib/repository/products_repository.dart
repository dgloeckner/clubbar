import 'dart:convert';
import 'package:drift/drift.dart' hide Column;
import '../database/database.dart';
import '../generated/terminal.swagger.dart';

class ProductsRepository {
  final ClubBarDatabase _db;

  ProductsRepository(this._db);

  /// Get all active categories with their active products, sorted lexicographically by name
  ///
  /// Deleted rows are excluded here rather than removed from the cache: they are
  /// foreign-key targets of rows the terminal keeps indefinitely. See
  /// [ProductsCache.deletedAt].
  Future<List<(CategoriesCacheData, List<ProductsCacheData>)>> getActiveCategoriesWithProducts() async {
    final categories = await (_db.select(_db.categoriesCache)
          ..where((c) => c.isActive.equals(1) & c.deletedAt.isNull()))
        .get();

    final result = <(CategoriesCacheData, List<ProductsCacheData>)>[];

    for (final category in categories) {
      final products = await (_db.select(_db.productsCache)
            ..where((p) =>
                p.categoryId.equals(category.id) &
                p.isActive.equals(1) &
                p.deletedAt.isNull()))
          .get();

      if (products.isNotEmpty) {
        result.add((category, products));
      }
    }

    // Sort lexicographically by German name (fallback to English)
    result.sort((a, b) {
      final namesA = jsonDecode(a.$1.names) as Map<String, dynamic>;
      final namesB = jsonDecode(b.$1.names) as Map<String, dynamic>;
      final nameA = ((namesA['de'] ?? namesA['en'] ?? '') as String).toLowerCase();
      final nameB = ((namesB['de'] ?? namesB['en'] ?? '') as String).toLowerCase();
      return nameA.compareTo(nameB);
    });

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
  ///
  /// A tombstone arrives in the same delta as any other change and carries every
  /// field, so writing [Category.deletedAt] through is all the deletion handling
  /// there is — there is no separate delete path.
  Future<void> upsertCategories(List<Category> categories) async {
    for (final dto in categories) {
      await _db.into(_db.categoriesCache).insertOnConflictUpdate(
        CategoriesCacheCompanion(
          id: Value(dto.id),
          names: Value(jsonEncode(dto.names)),
          isActive: Value(dto.isActive ? 1 : 0),
          iconName: Value(dto.iconName),
          updatedAt: Value(dto.updatedAt.toIso8601String()),
          deletedAt: Value(dto.deletedAt?.toIso8601String()),
        ),
      );
    }
  }

  /// Upsert products from sync response
  ///
  /// See [upsertCategories] on why a tombstone needs no delete path.
  Future<void> upsertProducts(List<Product> products) async {
    for (final dto in products) {
      await _db.into(_db.productsCache).insertOnConflictUpdate(
        ProductsCacheCompanion(
          id: Value(dto.id),
          categoryId: Value(dto.categoryId),
          names: Value(jsonEncode(dto.names)),
          descriptions: Value(dto.descriptions != null ? jsonEncode(dto.descriptions) : null),
          priceCents: Value(dto.priceCents),
          isActive: Value(dto.isActive ? 1 : 0),
          requiresDispenser: Value(dto.requiresDispenser == true ? 1 : 0),
          // Null stays null: unrestricted, not "zero" (ADR-0045).
          minAge: Value(dto.minAge),
          iconName: Value(dto.iconName),
          updatedAt: Value(dto.updatedAt.toIso8601String()),
          deletedAt: Value(dto.deletedAt?.toIso8601String()),
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
