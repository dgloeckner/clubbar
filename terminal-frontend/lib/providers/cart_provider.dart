import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/models/partial_dispense_info.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';
import 'package:ruderbar_terminal/services/config_service.dart';
import 'package:ruderbar_terminal/services/dispenser_client.dart';
import 'package:ruderbar_terminal/widgets/dispensing_progress_dialog.dart';
import 'package:ruderbar_terminal/widgets/dispenser_error_dialog.dart';

class CartProvider extends ChangeNotifier {
  final CartService _service;
  final ConfigService _config;

  List<CartItem> _items = [];
  bool _isLoading = false;
  String? _lastError;
  Exception? _errorType;
  String? _lastTransactionId;
  PartialDispenseInfo? _lastPartialDispenseInfo;

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
  PartialDispenseInfo? get lastPartialDispenseInfo => _lastPartialDispenseInfo;

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

      // Debug: Log cart items and their dispenser requirements
      print('Checkout: Cart has ${_items.length} items');
      for (var item in _items) {
        print('  - ${item.productName}: requiresDispenser=${item.requiresDispenser}');
      }
      print('Token products: ${tokenProducts.length}, Regular products: ${regularProducts.length}');

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

        // Generate dispenserTxId for crash recovery tracking
        final dispenserClient = DispenserClient(
          baseUrl: _config.dispenserBaseUrl!,
          apiKey: _config.dispenserApiKey!,
        );
        final dispenserTxId = dispenserClient.generateTxId();

        // Get token product details for tracking
        final tokenProduct = tokenProducts.first;
        final requestedQty = tokenProducts.fold(0, (sum, item) => sum + item.quantity);

        // Create tracking record BEFORE dispensing (for crash recovery)
        final (trackingSuccess, trackingError) =
            await _service.createDispenserOperation(
          dispenserTxId: dispenserTxId,
          memberId: member.id,
          productId: tokenProduct.productId,
          priceCents: tokenProduct.priceCents,
          requestedQty: requestedQty,
        );

        if (!trackingSuccess) {
          _lastError = trackingError;
          _isLoading = false;
          notifyListeners();
          return;
        }

        // Show dispensing dialog
        final result = await _showDispensingDialog(
          context,
          tokenProducts,
          dispenserTxId,
        );

        if (result == null) {
          // Dialog was cancelled or error occurred
          // Clean up tracking record
          await _service.cleanupDispenserOperation(dispenserTxId);

          // Check if error was handled (user made choice to skip tokens)
          if (_errorType is DispenserBusyException ||
              _errorType is DispenserNotFoundException) {
            // User chose to continue without tokens - skip token processing
            // Continue to process regular products below
          } else {
            // Other error or user cancelled - abort checkout
            _isLoading = false;
            notifyListeners();
            return;
          }
        } else {
          // Dispensing completed - create transactions
          final actualQuantity = result.dispensed;

          if (actualQuantity > 0) {
            // Create transactions from dispense result (one per token)
            final (tokenTxnId, tokenError) =
                await _service.createTransactionsFromDispenseResult(
              dispenserTxId: dispenserTxId,
              memberId: member.id,
              productId: tokenProduct.productId,
              priceCents: tokenProduct.priceCents,
              requestedQty: requestedQty,
              actualDispensed: actualQuantity,
            );

            if (tokenTxnId == null) {
              _lastError = tokenError;
              _isLoading = false;
              notifyListeners();
              return;
            }

            _lastTransactionId = tokenTxnId;

            // Clean up tracking record
            await _service.cleanupDispenserOperation(dispenserTxId);

            // Track partial dispense info
            final originalTotalCents =
                tokenProducts.fold(0, (sum, item) => sum + item.lineTotalCents);

            if (actualQuantity < requestedQty) {
              // Partial dispense - store info for confirmation screen
              _lastPartialDispenseInfo = PartialDispenseInfo(
                requestedQuantity: requestedQty,
                actualDispensed: actualQuantity,
                originalTotalCents: originalTotalCents,
              );
            } else {
              // Full dispense - clear any previous partial info
              _lastPartialDispenseInfo = null;
            }
          } else {
            // No tokens dispensed - clean up tracking record
            await _service.cleanupDispenserOperation(dispenserTxId);
          }
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
    String dispenserTxId,
  ) async {
    DispenseResult? result;
    DispenserException? errorException;

    await showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) {
        return DispensingProgressDialog(
          dispenserTxId: dispenserTxId,
          tokenProducts: tokenProducts,
          onComplete: (dispenseResult) {
            result = dispenseResult;
          },
          onError: (error) {
            errorException = error;
          },
        );
      },
    );

    // If error occurred, handle it based on error type
    if (errorException != null) {
      if (errorException is DispenserBusyException ||
          errorException is DispenserNotFoundException) {
        // Show error dialog and let user choose
        final errorType = errorException is DispenserBusyException
            ? DispenserErrorType.busy
            : DispenserErrorType.offline;

        if (!context.mounted) return null;

        final userChoice = await DispenserErrorDialog.show(context, errorType);

        if (userChoice) {
          // User chose to continue without tokens
          // Store error type so checkout can skip token processing
          _errorType = errorException;
          _lastError = null; // Clear error since user made informed choice
          return null; // Return null to signal tokens should be skipped
        } else {
          // User chose to cancel checkout
          _lastError = 'Checkout cancelled';
          return null;
        }
      } else {
        // Other dispenser errors - just show error message
        _lastError = errorException!.message;
        return null;
      }
    }

    return result;
  }

  /// Clear cart
  void clearCart() {
    _items = [];
    _lastError = null;
    _lastPartialDispenseInfo = null;
    notifyListeners();
  }
}
