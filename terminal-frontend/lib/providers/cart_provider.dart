import 'package:flutter/foundation.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';

class CartProvider extends ChangeNotifier {
  final CartService _service;

  List<CartItem> _items = [];
  bool _isLoading = false;
  String? _lastError;
  Exception? _errorType;
  String? _lastTransactionId;

  CartProvider({required CartService service}) : _service = service;

  List<CartItem> get items => _items;
  int get itemCount => _items.fold(0, (sum, item) => sum + item.quantity);
  int get total => _items.fold(0, (sum, item) => sum + item.lineTotalCents);
  bool get isLoading => _isLoading;
  String? get lastError => _lastError;
  Exception? get errorType => _errorType;
  String? get lastTransactionId => _lastTransactionId;

  /// Add item to cart (accumulates quantity if product already present)
  void addItem(
    String productId,
    String productName,
    int priceCents,
    int quantity,
    String language, {
    String? iconName,
  }) {
    final existingIndex =
        _items.indexWhere((item) => item.productId == productId);

    if (existingIndex >= 0) {
      // Update quantity for existing item
      final existing = _items[existingIndex];
      existing.quantity += quantity;
    } else {
      // Add new item
      _items.add(CartItem(
        productId: productId,
        productName: productName,
        quantity: quantity,
        priceCents: priceCents,
        language: language,
        iconName: iconName,
      ));
    }

    notifyListeners();
  }

  /// Remove item from cart
  void removeItem(String productId) {
    _items.removeWhere((item) => item.productId == productId);
    notifyListeners();
  }

  /// Update item quantity
  void updateQuantity(String productId, int quantity) {
    final index = _items.indexWhere((item) => item.productId == productId);
    if (index >= 0) {
      _items[index].quantity = quantity;
      notifyListeners();
    }
  }

  /// Checkout: validate, create transaction, clear cart
  Future<void> checkout(MembersCacheData member) async {
    _isLoading = true;
    notifyListeners();

    try {
      // Validate cart
      final (valid, error) =
          await _service.validateCartBeforeCheckout(member, _items);

      if (!valid) {
        _lastError = error;
        _isLoading = false;
        notifyListeners();
        return;
      }

      // Create transaction
      final (txnId, createError) =
          await _service.createTransaction(member, _items);

      if (txnId == null) {
        _lastError = createError;
        _isLoading = false;
        notifyListeners();
        return;
      }

      // Store transaction ID and clear cart on success
      _lastTransactionId = txnId;
      _items = [];
      _lastError = null;
      _errorType = null;
    } catch (e) {
      _lastError = 'Checkout failed: $e';
      if (e is Exception) {
        _errorType = e;
      }
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// Clear cart
  void clearCart() {
    _items = [];
    _lastError = null;
    notifyListeners();
  }
}
