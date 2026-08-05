import 'package:uuid/uuid.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/utils/app_logger.dart';
import 'package:drift/drift.dart';

class CartService {
  final ClubBarDatabase _db;
  final TransactionsRepository _repository;
  static const _uuid = Uuid();

  CartService({
    required ClubBarDatabase database,
    required TransactionsRepository repository,
  })  : _db = database,
        _repository = repository;

  /// Create and persist transactions from cart items.
  /// Creates one transaction per cart line item (each with its product_id),
  /// as required by the backend API.
  /// Returns tuple: (firstTransactionId, errorKey)
  Future<(String?, TerminalErrorKey?)> createTransaction(
    MembersCacheData member,
    List<CartItem> items, {
    required String sessionId,
  }) async {
    try {
      final now = DateTime.now().toUtc().toIso8601String();
      String? firstTxnId;

      for (final item in items) {
        for (var i = 0; i < item.quantity; i++) {
          final txnId = _uuid.v4();
          firstTxnId ??= txnId;

          final companion = TransactionsLocalCompanion(
            id: Value(txnId),
            memberId: Value(member.id),
            productId: Value(item.productId),
            amountCents: Value(item.priceCents),
            transactionType: const Value('purchase'),
            notes: const Value(null),
            createdAt: Value(now),
            synced: const Value(0),
            sessionId: Value(sessionId),
            unitPriceCents: Value(item.priceCents),
          );

          await _repository.insertTransactionCompanion(companion);
        }
      }

      return (firstTxnId, null);
    } catch (e, stackTrace) {
      AppLog.instance
          .e('Transaction creation failed', error: e, stackTrace: stackTrace);
      return (null, TerminalErrorKey.transactionCreateFailed);
    }
  }

  /// Validate cart before checkout
  /// Returns tuple: (isValid, errorKey)
  Future<(bool, TerminalErrorKey?)> validateCartBeforeCheckout(
    MembersCacheData member,
    List<CartItem> items,
  ) async {
    // Check member is active
    if (member.isActive == 0) {
      return (false, TerminalErrorKey.accountInactive);
    }

    // Check cart not empty
    if (items.isEmpty) {
      return (false, TerminalErrorKey.cartEmpty);
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
  /// Returns tuple: (success, errorKey)
  Future<(bool, TerminalErrorKey?)> createDispenserOperation({
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
    } catch (e, stackTrace) {
      AppLog.instance.e('Dispenser operation record creation failed',
          error: e, stackTrace: stackTrace);
      return (false, TerminalErrorKey.dispenserOperationFailed);
    }
  }

  /// Create transactions from dispense result AFTER dispensing completes.
  ///
  /// Creates one transaction per actually dispensed token. All transactions share
  /// the same dispenserTxId for grouping.
  ///
  /// Returns tuple: (firstTransactionId, errorKey)
  Future<(String?, TerminalErrorKey?)> createTransactionsFromDispenseResult({
    required String dispenserTxId,
    required String memberId,
    required String productId,
    required int priceCents,
    required int requestedQty,
    required int actualDispensed,
    required String sessionId,
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
          sessionId: Value(sessionId),
          unitPriceCents: Value(priceCents),
        );

        await _db.into(_db.transactionsLocal).insert(transaction);
      }

      return (firstTxnId, null);
    } catch (e, stackTrace) {
      AppLog.instance.e('Transaction creation from dispense result failed',
          error: e, stackTrace: stackTrace);
      return (null, TerminalErrorKey.transactionCreateFailed);
    }
  }

  /// Update dispenser operation state without cleaning up.
  ///
  /// Used after creating transactions to track reconciliation status, and during
  /// polling to update ESP8266 state for recovery service monitoring.
  ///
  /// Returns tuple: (success, errorKey)
  Future<(bool, TerminalErrorKey?)> updateDispenserOperationState({
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
    } catch (e, stackTrace) {
      AppLog.instance.e('Dispenser operation state update failed',
          error: e, stackTrace: stackTrace);
      return (false, TerminalErrorKey.dispenserOperationFailed);
    }
  }

  /// Clean up dispenser operation tracking record AFTER transactions are created.
  ///
  /// Returns tuple: (success, errorKey)
  Future<(bool, TerminalErrorKey?)> cleanupDispenserOperation(
      String dispenserTxId) async {
    try {
      await (_db.delete(_db.dispenserOperations)
            ..where((t) => t.dispenserTxId.equals(dispenserTxId)))
          .go();
      return (true, null);
    } catch (e, stackTrace) {
      AppLog.instance.e('Dispenser operation cleanup failed',
          error: e, stackTrace: stackTrace);
      return (false, TerminalErrorKey.dispenserOperationFailed);
    }
  }
}
