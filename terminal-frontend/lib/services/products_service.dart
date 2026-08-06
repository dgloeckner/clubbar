import 'dart:convert';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';

class ProductsService {
  final ProductsRepository _repository;

  ProductsService({required ProductsRepository repository})
      : _repository = repository;

  /// Get all active categories with their products
  Future<List<(CategoriesCacheData, List<ProductsCacheData>)>>
      getActiveCategoriesWithProducts() async {
    return _repository.getActiveCategoriesWithProducts();
  }

  /// Get single product by ID
  Future<ProductsCacheData?> getProduct(String id) async {
    return _repository.getProduct(id);
  }

  /// Get product name translated to language (German fallback)
  String getTranslatedName(ProductsCacheData product, String language) {
    try {
      final translations = jsonDecode(product.names) as Map<String, dynamic>;

      // Try requested language first
      if (translations.containsKey(language)) {
        return translations[language] as String;
      }

      // Fall back to German
      if (translations.containsKey('de')) {
        return translations['de'] as String;
      }

      // No translations available
      return '';
    } catch (e) {
      return '';
    }
  }

  /// The order products are shown in on the grid.
  ///
  /// Sorted by the German name — the default language — rather than by the
  /// name the current member happens to read. Tile position is a property of
  /// the product, not of the UI language: sorting by the localized name meant
  /// every language switch reshuffled the grid under members who had learned
  /// where their drink sits (issue #33). Ties fall back to the product id so
  /// the order is fully determined.
  List<ProductsCacheData> sortForDisplay(List<ProductsCacheData> products) {
    return List<ProductsCacheData>.from(products)
      ..sort((a, b) {
        final byName = getTranslatedName(a, _defaultLanguage)
            .toLowerCase()
            .compareTo(getTranslatedName(b, _defaultLanguage).toLowerCase());
        return byName != 0 ? byName : a.id.compareTo(b.id);
      });
  }

  static const String _defaultLanguage = 'de';

  /// Refresh products from repository
  Future<List<(CategoriesCacheData, List<ProductsCacheData>)>>
      refreshProducts() async {
    return _repository.getActiveCategoriesWithProducts();
  }
}
