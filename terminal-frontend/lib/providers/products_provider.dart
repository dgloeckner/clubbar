import 'package:flutter/foundation.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/error_signal.dart';
import 'package:clubbar_terminal/services/products_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/dispenser_health_service.dart';

class ProductsProvider extends ChangeNotifier with ErrorSignal {
  final ProductsService _service;
  final ConfigService _config;

  /// Live dispenser health, when a dispenser is configured at all.
  ///
  /// Issue #31: without this the grid only knew whether a dispenser *exists*,
  /// not whether it works, so a jammed machine still sold tokens right up to
  /// the checkout dialog.
  final DispenserHealthService? _dispenserHealth;

  List<CategoriesCacheData> _categories = [];
  List<ProductsCacheData> _products = [];
  final bool _isLoading = false;
  bool _isSyncing = false;

  /// Session price freeze: a snapshot taken when a member session starts, so
  /// a mid-session background sync cannot repaint the grid with a different
  /// price (or availability) than what's already tappable in front of the
  /// member. See ADR-0027 Amendment 4.
  bool _frozen = false;
  List<CategoriesCacheData> _frozenCategories = [];
  List<ProductsCacheData> _frozenProducts = [];

  ProductsProvider({
    required ProductsService service,
    required ConfigService config,
    DispenserHealthService? dispenserHealth,
  })  : _service = service,
        _config = config,
        _dispenserHealth = dispenserHealth {
    _dispenserHealth?.addListener(_onDispenserHealthChanged);
  }

  void _onDispenserHealthChanged() => notifyListeners();

  @override
  void dispose() {
    _dispenserHealth?.removeListener(_onDispenserHealthChanged);
    super.dispose();
  }

  List<CategoriesCacheData> get categories =>
      _frozen ? _frozenCategories : _categories;
  List<ProductsCacheData> get products =>
      _frozen ? _frozenProducts : _products;
  bool get isLoading => _isLoading;
  bool get isSyncing => _isSyncing;

  /// Snapshot the current catalogue so a background sync during an active
  /// member session cannot change what's displayed/tappable. Called only by
  /// [SessionController.startSession] (ADR-0027 owns the session lifecycle).
  void freezeForSession() {
    _frozenCategories = List.unmodifiable(_categories);
    _frozenProducts = List.unmodifiable(_products);
    _frozen = true;
  }

  /// Resume showing live data. Called only by
  /// [SessionController.endSession].
  void unfreezeForSession() {
    _frozen = false;
    _frozenCategories = [];
    _frozenProducts = [];
  }

  /// Refresh products from service
  Future<void> refreshProducts() async {
    _isSyncing = true;
    notifyListeners();

    try {
      final result = await _service.getActiveCategoriesWithProducts();
      _categories = result.map((item) => item.$1).toList();
      _products = result.expand((item) => item.$2).toList();
      resetError();
    } catch (e, stackTrace) {
      emitError(TerminalErrorKey.productsRefreshFailed,
          cause: e, stackTrace: stackTrace);
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

  /// Get visible products for a category, in the order the grid shows them.
  ///
  /// Filters products by:
  /// - Category ID
  /// - Active status
  /// - Dispenser requirement (hides products requiring dispenser when dispenser is disabled)
  ///
  /// The order is language-independent (see [ProductsService.sortForDisplay]),
  /// so tile positions survive a language switch (issue #33).
  List<ProductsCacheData> getVisibleProducts(String categoryId) {
    final dispenserEnabled = _config.dispenserEnabled;

    final visible = products
        .where((p) => p.categoryId == categoryId)
        .where((p) => p.isActive == 1)
        .where((p) {
      // Hide dispenser products if dispenser not configured
      if (p.requiresDispenser == 1 && !dispenserEnabled) {
        return false;
      }
      return true;
    }).toList();

    return _service.sortForDisplay(visible);
  }

  /// Whether [product] can actually be bought right now.
  ///
  /// Only dispenser-gated products can answer `false`: they need a configured
  /// dispenser that is currently healthy. A product the member cannot have is
  /// shown disabled rather than hidden — a token that vanishes from the grid
  /// reads as "discontinued", which it is not (issue #31).
  ///
  /// Health that has not been polled yet counts as available: the terminal
  /// should not open with every token greyed out, and the checkout dispenser
  /// dialog is still the backstop.
  bool isProductAvailable(ProductsCacheData product) {
    if (product.requiresDispenser != 1) return true;
    if (!_config.dispenserEnabled) return false;

    final health = _dispenserHealth?.currentHealth;
    if (health == null) return true;

    return !health.isUnavailable;
  }
}
