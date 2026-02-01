import 'package:flutter/foundation.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/services/products_service.dart';

class ProductsProvider extends ChangeNotifier {
  final ProductsService _service;

  List<CategoriesCacheData> _categories = [];
  List<ProductsCacheData> _products = [];
  bool _isLoading = false;
  bool _isSyncing = false;
  String? _lastError;
  Exception? _errorType;

  ProductsProvider({required ProductsService service}) : _service = service;

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
}
