import 'package:uuid/uuid.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';

class CartService {
  final TransactionsRepository _repository;
  static const _uuid = Uuid();

  CartService({required TransactionsRepository repository})
      : _repository = repository;

  /// Create and persist transactions from cart items.
  /// Creates one transaction per cart line item (each with its product_id),
  /// as required by the backend API.
  /// Returns tuple: (firstTransactionId, errorMessage)
  Future<(String?, String?)> createTransaction(
    MembersCacheData member,
    List<CartItem> items,
  ) async {
    try {
      final now = DateTime.now().toUtc().toIso8601String();
      String? firstTxnId;

      for (final item in items) {
        for (var i = 0; i < item.quantity; i++) {
          final txnId = _uuid.v4();
          firstTxnId ??= txnId;

          final transaction = TransactionsLocalData(
            id: txnId,
            memberId: member.id,
            productId: item.productId,
            amountCents: item.priceCents,
            transactionType: 'purchase',
            notes: null,
            createdAt: now,
            synced: 0,
          );

          await _repository.insertTransaction(transaction);
        }
      }

      return (firstTxnId, null);
    } catch (e) {
      return (null, 'Failed to create transaction: $e');
    }
  }

  /// Validate cart before checkout
  /// Returns tuple: (isValid, errorMessage)
  Future<(bool, String?)> validateCartBeforeCheckout(
    MembersCacheData member,
    List<CartItem> items,
  ) async {
    // Check member is active
    if (member.isActive == 0) {
      return (false, 'Member account is inactive');
    }

    // Check cart not empty
    if (items.isEmpty) {
      return (false, 'Cart is empty');
    }

    return (true, null);
  }
}
