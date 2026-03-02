import 'package:flutter/foundation.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/services/products_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';

class ProductsProvider extends ChangeNotifier {
  final ProductsService _service;
  final ConfigService _config;

  List<CategoriesCacheData> _categories = [];
  List<ProductsCacheData> _products = [];
  final bool _isLoading = false;
  bool _isSyncing = false;
  String? _lastError;
  Exception? _errorType;

  ProductsProvider({
    required ProductsService service,
    required ConfigService config,
  })  : _service = service,
        _config = config;

  List<CategoriesCacheData> get categories => _categories;
  List<ProductsCacheData> get products => _products;
  bool get isLoading => _isLoading;
  bool get isSyncing => _isSyncing;
  String? get lastError => _lastError;
  Exception? get errorType => _errorType;

  /// Refresh products from service
  Future<void> refreshProducts() async {
    _isSyncing = true;
    notifyListeners();

    try {
      final result = await _service.getActiveCategoriesWithProducts();
      _categories = result.map((item) => item.$1).toList();
      _products = result.expand((item) => item.$2).toList();
      _lastError = null;
      _errorType = null;
    } catch (e) {
      _lastError = 'Failed to refresh products: $e';
      _errorType = e as Exception?;
    } finally {
      _isSyncing = false;
      notifyListeners();
    }
  }

  /// Get translated product name
  String getTranslatedName(ProductsCacheData product, String language) {
    return _service.getTranslatedName(product, language);
  }

  /// Clear cached products
  Future<void> clearCache() async {
    _categories = [];
    _products = [];
    notifyListeners();
  }

  /// Get visible products for a category
  ///
  /// Filters products by:
  /// - Category ID
  /// - Active status
  /// - Dispenser requirement (hides products requiring dispenser when dispenser is disabled)
  List<ProductsCacheData> getVisibleProducts(String categoryId) {
    final dispenserEnabled = _config.dispenserEnabled;

    return _products
        .where((p) => p.categoryId == categoryId)
        .where((p) => p.isActive == 1)
        .where((p) {
      // Hide dispenser products if dispenser not configured
      if (p.requiresDispenser == 1 && !dispenserEnabled) {
        return false;
      }
      return true;
    }).toList();
  }
}
