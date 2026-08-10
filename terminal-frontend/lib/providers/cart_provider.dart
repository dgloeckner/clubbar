import 'package:flutter/material.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/error_signal.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/widgets/dispensing_progress_dialog.dart';
import 'package:clubbar_terminal/widgets/dispenser_error_dialog.dart';

class CartProvider extends ChangeNotifier with ErrorSignal {
  final CartService _service;
  final ConfigService _config;
  final SoundService _soundService;

  List<CartItem> _items = [];
  bool _isLoading = false;

  /// Raw dispenser exception, kept only to branch the token-skip flow below.
  /// Deliberately not exposed: members see [lastError]'s key, never this.
  Exception? _errorType;
  String? _lastTransactionId;
  String? _lastSessionId;

  /// What the last successful checkout actually billed, in cents.
  ///
  /// [total] cannot answer this after the fact: [checkout] empties the cart on
  /// success, and on a partial dispense the cart never matched the bill
  /// anyway. The confirmation screen needs the figure to show a receipt when
  /// the session lookup fails (#16).
  int _lastCheckoutTotalCents = 0;

  CartProvider({
    required CartService service,
    required ConfigService config,
    required SoundService soundService,
  })  : _service = service,
        _config = config,
        _soundService = soundService;

  List<CartItem> get items => _items;
  int get itemCount => _items.fold(0, (sum, item) => sum + item.quantity);
  int get total => _items.fold(0, (sum, item) => sum + item.lineTotalCents);
  bool get isLoading => _isLoading;
  String? get lastTransactionId => _lastTransactionId;
  String? get lastSessionId => _lastSessionId;
  int get lastCheckoutTotalCents => _lastCheckoutTotalCents;

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

    _soundService.play(SoundEvent.productAdd);
    notifyListeners();
  }

  /// Remove item from cart
  void removeItem(String productId) {
    _items.removeWhere((item) => item.productId == productId);
    _soundService.play(SoundEvent.productRemove);
    notifyListeners();
  }

  /// Decrease item quantity by 1. Removes the item if quantity reaches 0.
  void decreaseItem(String productId) {
    final index = _items.indexWhere((item) => item.productId == productId);
    if (index >= 0) {
      if (_items[index].quantity > 1) {
        _items[index].quantity -= 1;
        _soundService.play(SoundEvent.quantityChange);
      } else {
        _items.removeAt(index);
        _soundService.play(SoundEvent.productRemove);
      }
      notifyListeners();
    }
  }

  /// Update item quantity
  void updateQuantity(String productId, int quantity) {
    final index = _items.indexWhere((item) => item.productId == productId);
    if (index >= 0) {
      _items[index].quantity = quantity;
      _soundService.play(SoundEvent.quantityChange);
      notifyListeners();
    }
  }

  /// Checkout: validate, dispense tokens if needed, create transactions, clear cart
  ///
  /// Re-entrant calls are ignored: while a checkout is in flight [isLoading] is
  /// true and any further call returns immediately without touching the cart.
  /// The cart is only emptied after every await has completed, so without this
  /// guard a second call (e.g. a double-tap on the checkout button) would see a
  /// full cart and charge the member twice.
  Future<void> checkout(
    BuildContext context,
    MembersCacheData member,
    String sessionId,
  ) async {
    // Re-entrancy guard: set synchronously before the first await, so a second
    // call can never observe a half-finished checkout.
    if (_isLoading) return;

    // Whatever the last checkout ended on says nothing about this one (#20).
    _resetCheckoutState();

    _isLoading = true;
    notifyListeners();

    try {
      // Validate cart
      final (valid, error) =
          await _service.validateCartBeforeCheckout(member, _items);

      if (!valid) {
        emitError(error ?? TerminalErrorKey.checkoutFailed);
        _isLoading = false;
        _soundService.play(SoundEvent.checkoutError);
        notifyListeners();
        return;
      }

      _lastSessionId = sessionId;
      var billedCents = 0;

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
          emitError(TerminalErrorKey.dispenserNotConfigured);
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
          emitError(trackingError ?? TerminalErrorKey.dispenserOperationFailed);
          _isLoading = false;
          notifyListeners();
          return;
        }

        // Show dispensing dialog
        final result = await showDispensingDialog(
          context,
          tokenProducts,
          dispenserTxId,
        );

        if (result == null) {
          // Dialog was cancelled or error occurred
          // Update tracking state but DON'T cleanup (recovery service will handle)
          await _service.updateDispenserOperationState(
            dispenserTxId: dispenserTxId,
            state: 'cancelled',
            transactionsCreated: 0,
          );

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
              sessionId: sessionId,
            );

            if (tokenTxnId == null) {
              emitError(
                  tokenError ?? TerminalErrorKey.transactionCreateFailed);
              _isLoading = false;
              notifyListeners();
              return;
            }

            _lastTransactionId = tokenTxnId;
            // Only the tokens that came out are charged for.
            billedCents += actualQuantity * tokenProduct.priceCents;

            // Update tracking state with created count
            await _service.updateDispenserOperationState(
              dispenserTxId: dispenserTxId,
              state: result.state,
              transactionsCreated: actualQuantity,
              lastKnownDispensed: actualQuantity,
            );

            // CONDITIONAL CLEANUP: Only if state is final
            if (result.state == 'done') {
              // ESP8266 completed successfully - safe to cleanup
              await _service.cleanupDispenserOperation(dispenserTxId);
            } else if (result.state == 'error' || result.state == 'dispensing') {
              // Keep tracking record for reconciliation to verify
              // Recovery service will query ESP8266 and clean up after verification
              print('Keeping tracking record for reconciliation (state=${result.state})');
            }
          } else {
            // Nothing came out of the dispenser. There is no purchase to
            // record, so this is a failed checkout, not a €0.00 success:
            // release the tracking record, keep the cart so the member can
            // retry, and say why. Falling through here would clear the cart
            // and show the green confirmation screen for nothing (#15).
            // `finally` below clears _isLoading and notifies.
            await _service.cleanupDispenserOperation(dispenserTxId);
            emitError(TerminalErrorKey.dispenserNoTokensDispensed);
            _soundService.play(SoundEvent.checkoutError);
            return;
          }
        }
      }

      // Create transactions for regular products
      if (regularProducts.isNotEmpty) {
        final (txnId, createError) =
            await _service.createTransaction(member, regularProducts, sessionId: sessionId);

        if (txnId == null) {
          emitError(createError ?? TerminalErrorKey.transactionCreateFailed);
          _isLoading = false;
          notifyListeners();
          return;
        }

        _lastTransactionId ??= txnId;
        billedCents += regularProducts.fold(
            0, (sum, item) => sum + item.lineTotalCents);
      }

      // Clear cart on success — record the bill first, it is the only
      // surviving record of the amount for the receipt (#16).
      _lastCheckoutTotalCents = billedCents;
      _items = [];
      _resetCheckoutState();
      _soundService.play(SoundEvent.checkoutSuccess);
    } catch (e, stackTrace) {
      emitError(TerminalErrorKey.checkoutFailed,
          cause: e, stackTrace: stackTrace);
      if (e is Exception) {
        _errorType = e;
      }
      _soundService.play(SoundEvent.checkoutError);
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// Show dispensing progress dialog and return result.
  ///
  /// Overridable so tests can drive the dispense branch of [checkout] without a
  /// widget tree; production code never calls it directly.
  @protected
  @visibleForTesting
  Future<DispenseResult?> showDispensingDialog(
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
          cartService: _service,
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
          resetError(); // Clear error since user made informed choice
          return null; // Return null to signal tokens should be skipped
        } else {
          // User chose to cancel checkout
          emitError(TerminalErrorKey.checkoutCancelled);
          return null;
        }
      } else {
        // Other dispenser errors — the raw message stays in the log.
        emitError(TerminalErrorKey.dispenserUnavailable,
            cause: errorException);
        return null;
      }
    }

    return result;
  }

  /// Every piece of state that belongs to a single checkout attempt.
  ///
  /// Both fields are read to decide what a checkout does — [_errorType] picks
  /// the token-skip branch — so a value left over from an aborted attempt
  /// silently changes the next one (#20). Reset them together, in one place, so
  /// a future field added here cannot be forgotten at one of the call sites.
  void _resetCheckoutState() {
    resetError();
    _errorType = null;
  }

  /// Clear cart
  void clearCart() {
    _items = [];
    _resetCheckoutState();
    _lastSessionId = null;
    _lastCheckoutTotalCents = 0;
    notifyListeners();
  }
}
