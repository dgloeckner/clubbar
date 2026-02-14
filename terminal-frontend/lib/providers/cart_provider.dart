import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';
import 'package:ruderbar_terminal/services/config_service.dart';
import 'package:ruderbar_terminal/services/dispenser_client.dart';
import 'package:ruderbar_terminal/widgets/dispensing_progress_dialog.dart';

class CartProvider extends ChangeNotifier {
  final CartService _service;
  final ConfigService _config;

  List<CartItem> _items = [];
  bool _isLoading = false;
  String? _lastError;
  Exception? _errorType;
  String? _lastTransactionId;

  CartProvider({
    required CartService service,
    required ConfigService config,
  })  : _service = service,
        _config = config;

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
    bool requiresDispenser = false,
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
        requiresDispenser: requiresDispenser,
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

  /// Checkout: validate, dispense tokens if needed, create transactions, clear cart
  Future<void> checkout(
    BuildContext context,
    MembersCacheData member,
  ) async {
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

      // Separate token products from regular products
      final tokenProducts =
          _items.where((item) => item.requiresDispenser).toList();
      final regularProducts =
          _items.where((item) => !item.requiresDispenser).toList();

      // If tokens in cart, dispense first (dispense-first, pay-after)
      if (tokenProducts.isNotEmpty) {
        final dispenserEnabled = _config.dispenserEnabled;

        if (!dispenserEnabled) {
          // Should never happen (filtered in UI), but safety check
          _lastError = 'Dispenser not configured';
          _isLoading = false;
          notifyListeners();
          return;
        }

        // Import the dialog widget to show it
        // (will be imported at top of file)
        final result = await _showDispensingDialog(context, tokenProducts);

        if (result == null) {
          // Dialog was cancelled or error occurred
          _isLoading = false;
          notifyListeners();
          return;
        }

        // Create transactions for actually dispensed tokens
        final actualQuantity = result.dispensed;
        if (actualQuantity > 0) {
          // Create transactions based on actual dispensed count
          // For now, create them all with the same product (first token product)
          final tokenProduct = tokenProducts.first;
          final modifiedTokenItems = [
            CartItem(
              productId: tokenProduct.productId,
              productName: tokenProduct.productName,
              priceCents: tokenProduct.priceCents,
              quantity: actualQuantity,
              language: tokenProduct.language,
              iconName: tokenProduct.iconName,
              requiresDispenser: true,
            ),
          ];

          // Create transactions for tokens
          final (tokenTxnId, tokenError) =
              await _service.createTransaction(member, modifiedTokenItems);

          if (tokenTxnId == null) {
            _lastError = tokenError;
            _isLoading = false;
            notifyListeners();
            return;
          }

          _lastTransactionId = tokenTxnId;
        }
      }

      // Create transactions for regular products
      if (regularProducts.isNotEmpty) {
        final (txnId, createError) =
            await _service.createTransaction(member, regularProducts);

        if (txnId == null) {
          _lastError = createError;
          _isLoading = false;
          notifyListeners();
          return;
        }

        _lastTransactionId ??= txnId;
      }

      // Clear cart on success
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

  /// Show dispensing progress dialog and return result
  Future<DispenseResult?> _showDispensingDialog(
    BuildContext context,
    List<CartItem> tokenProducts,
  ) async {
    DispenseResult? result;
    String? errorMessage;

    // Import will be added at top of file
    await showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) {
        // Need to import DispensingProgressDialog
        // This will be resolved by adding the import at the top
        return DispensingProgressDialog(
          tokenProducts: tokenProducts,
          onComplete: (dispenseResult) {
            result = dispenseResult;
          },
          onError: (error) {
            errorMessage = error.message;
          },
        );
      },
    );

    // If error occurred, show placeholder error
    if (errorMessage != null) {
      _lastError = errorMessage;
      return null;
    }

    return result;
  }

  /// Clear cart
  void clearCart() {
    _items = [];
    _lastError = null;
    notifyListeners();
  }
}
