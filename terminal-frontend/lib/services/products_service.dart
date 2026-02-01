import 'dart:convert';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/repository/products_repository.dart';

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

  /// Refresh products from repository
  Future<List<(CategoriesCacheData, List<ProductsCacheData>)>>
      refreshProducts() async {
    return _repository.getActiveCategoriesWithProducts();
  }
}
