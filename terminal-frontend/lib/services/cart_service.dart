import 'package:uuid/uuid.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';

class CartService {
  final TransactionsRepository _repository;
  static const _uuid = Uuid();

  CartService({required TransactionsRepository repository})
      : _repository = repository;

  /// Create and persist transaction from cart items
  /// Returns tuple: (transactionId, errorMessage)
  Future<(String?, String?)> createTransaction(
    MembersCacheData member,
    List<CartItem> items,
  ) async {
    try {
      // Calculate total amount
      final totalCents = items.fold<int>(
        0,
        (sum, item) => sum + item.lineTotalCents,
      );

      // Generate transaction ID
      final txnId = _uuid.v4();

      // Create transaction for the member (sum of all items)
      final transaction = TransactionsLocalData(
        id: txnId,
        memberId: member.id,
        productId: null, // Multi-item transaction, no single product
        amountCents: totalCents,
        transactionType: 'purchase',
        notes: '${items.length} items',
        createdAt: DateTime.now().toIso8601String(),
        synced: 0,
      );

      // Persist to repository
      await _repository.insertTransaction(transaction);

      return (txnId, null);
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
