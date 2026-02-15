import 'package:uuid/uuid.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';
import 'package:drift/drift.dart';

class CartService {
  final RuderbarDatabase _db;
  final TransactionsRepository _repository;
  static const _uuid = Uuid();

  CartService({
    required RuderbarDatabase database,
    required TransactionsRepository repository,
  })  : _db = database,
        _repository = repository;

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

  // ============================================================================
  // DISPENSER TRANSACTION METHODS (Crash Recovery Support)
  // ============================================================================

  /// Create tracking record BEFORE dispensing starts (for crash recovery).
  ///
  /// This record will be used to recover incomplete operations if the app crashes
  /// between dispensing and transaction creation.
  ///
  /// Returns tuple: (success, errorMessage)
  Future<(bool, String?)> createDispenserOperation({
    required String dispenserTxId,
    required String memberId,
    required String productId,
    required int priceCents,
    required int requestedQty,
  }) async {
    try {
      final now = DateTime.now().toUtc().toIso8601String();

      final operation = DispenserOperationsCompanion(
        dispenserTxId: Value(dispenserTxId),
        memberId: Value(memberId),
        productId: Value(productId),
        priceCents: Value(priceCents),
        requestedQty: Value(requestedQty),
        createdAt: Value(now),
      );

      await _db.into(_db.dispenserOperations).insert(operation);
      return (true, null);
    } catch (e) {
      return (false, 'Failed to create dispenser operation: $e');
    }
  }

  /// Create transactions from dispense result AFTER dispensing completes.
  ///
  /// Creates one transaction per actually dispensed token. All transactions share
  /// the same dispenserTxId for grouping.
  ///
  /// Returns tuple: (firstTransactionId, errorMessage)
  Future<(String?, String?)> createTransactionsFromDispenseResult({
    required String dispenserTxId,
    required String memberId,
    required String productId,
    required int priceCents,
    required int requestedQty,
    required int actualDispensed,
  }) async {
    try {
      final now = DateTime.now().toUtc().toIso8601String();
      String? firstTxnId;

      // Create one transaction per dispensed token
      for (int i = 0; i < actualDispensed; i++) {
        final txnId = _uuid.v4();
        firstTxnId ??= txnId;

        final transaction = TransactionsLocalCompanion(
          id: Value(txnId),
          memberId: Value(memberId),
          productId: Value(productId),
          amountCents: Value(priceCents), // One token's price
          transactionType: Value('purchase'),
          notes: Value(null),
          createdAt: Value(now),
          synced: Value(0),
          dispenserTxId: Value(dispenserTxId),
          dispenserRequested: Value(requestedQty),
          dispenserActual: Value(actualDispensed),
        );

        await _db.into(_db.transactionsLocal).insert(transaction);
      }

      return (firstTxnId, null);
    } catch (e) {
      return (null, 'Failed to create transactions from dispense result: $e');
    }
  }

  /// Update dispenser operation state without cleaning up.
  ///
  /// Used after creating transactions to track reconciliation status, and during
  /// polling to update ESP8266 state for recovery service monitoring.
  ///
  /// Returns tuple: (success, errorMessage)
  Future<(bool, String?)> updateDispenserOperationState({
    required String dispenserTxId,
    String? state,
    int? transactionsCreated,
    int? lastKnownDispensed,
    int? pollingActive,
    String? lastPolledAt,
  }) async {
    try {
      final companion = DispenserOperationsCompanion(
        lastKnownState: state != null ? Value(state) : Value.absent(),
        transactionsCreated: transactionsCreated != null
            ? Value(transactionsCreated)
            : Value.absent(),
        lastKnownDispensed: lastKnownDispensed != null
            ? Value(lastKnownDispensed)
            : Value.absent(),
        pollingActive: pollingActive != null
            ? Value(pollingActive)
            : Value.absent(),
        lastPolledAt: lastPolledAt != null
            ? Value(lastPolledAt)
            : Value.absent(),
      );

      await (_db.update(_db.dispenserOperations)
            ..where((t) => t.dispenserTxId.equals(dispenserTxId)))
          .write(companion);

      return (true, null);
    } catch (e) {
      return (false, 'Failed to update dispenser operation state: $e');
    }
  }

  /// Clean up dispenser operation tracking record AFTER transactions are created.
  ///
  /// Returns tuple: (success, errorMessage)
  Future<(bool, String?)> cleanupDispenserOperation(
      String dispenserTxId) async {
    try {
      await (_db.delete(_db.dispenserOperations)
            ..where((t) => t.dispenserTxId.equals(dispenserTxId)))
          .go();
      return (true, null);
    } catch (e) {
      return (false, 'Failed to cleanup dispenser operation: $e');
    }
  }
}
