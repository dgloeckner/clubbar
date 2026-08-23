import 'package:uuid/uuid.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/utils/age.dart';
import 'package:clubbar_terminal/utils/app_logger.dart';
import 'package:drift/drift.dart';

class CartService {
  final ClubBarDatabase _db;
  final TransactionsRepository _repository;
  /// Where the club's ceiling and warning band come from (ADR-0047).
  ///
  /// Read on every check rather than captured once: a sync landing mid-session
  /// changes the club's policy, and the authority on a checkout must be using
  /// the same numbers the screens are showing.
  final ConfigService _configService;
  static const _uuid = Uuid();

  CartService({
    required ClubBarDatabase database,
    required TransactionsRepository repository,
    required ConfigService configService,
  })  : _db = database,
        _repository = repository,
        _configService = configService;

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

    // Jugendschutz (ADR-0045, UC-T12 E7). Deliberately **before** the credit
    // limit: a member who is both too young and over their limit is told the
    // thing that will not change by paying something off, and a refusal on
    // legal grounds is never dressed up as a money message.
    //
    // Deliberately **after** the empty-cart check, so "you have not chosen
    // anything" keeps its own plain answer.
    if (requiredAgeBlocking(member, items) != null) {
      return (false, TerminalErrorKey.ageRestricted);
    }

    // Credit limit (UC-T11 E3, UC-T12). The cart screen already disables the
    // button above the limit; this is the authority, not a duplicate of it —
    // the tab can move under the member's feet (a sync landing mid-session)
    // between rendering the screen and tapping Buy.
    final limitCheck = await checkCreditLimit(member, items);
    if (limitCheck.blocksCheckout) {
      return (false, TerminalErrorKey.balanceLimitExceeded);
    }

    return (true, null);
  }

  /// The highest age in [items] that [member] has not reached, or null if the
  /// cart is fine for them.
  ///
  /// Returns the *age*, not a boolean, because the refusal has to name it: the
  /// member is told what the drink requires, never what they are (ADR-0045
  /// rule 6). The strictest line wins, so a cart holding both a beer and a
  /// spirit refuses with 18 rather than 16.
  ///
  /// Synchronous and pure — the two inputs are already in hand, which is what
  /// makes this safe to call from the product grid as well as from checkout.
  ///
  /// **No fail-open branch.** A member with no cached birth date is anonymized
  /// (rule 3), and an unparseable one is a cache this code will not guess
  /// about; both refuse anything with a limit. Neither can reach an ordinary
  /// member: the field is required at creation, and an anonymized member is
  /// inactive and stopped at the card scan long before a cart exists.
  int? requiredAgeBlocking(MembersCacheData member, List<CartItem> items) {
    final restricted = items
        .map((item) => item.minAge)
        .whereType<int>()
        .toList(growable: false);
    if (restricted.isEmpty) return null;

    final now = DateTime.now();

    int? blocking;
    for (final age in restricted) {
      if (!mayBuyAtAge(member.dateOfBirth, age, now) &&
          (blocking == null || age > blocking)) {
        blocking = age;
      }
    }

    return blocking;
  }

  /// Where [items] would leave [member] relative to **their** credit ceiling.
  ///
  /// Reads the effective tab (including unsynced transactions) so the verdict
  /// holds on an offline terminal, and resolves the ceiling through the one
  /// rule that decides it: the member's own where they have one, the club
  /// default where they do not (ADR-0047 rule 1).
  Future<CreditLimitCheck> checkCreditLimit(
    MembersCacheData member,
    List<CartItem> items,
  ) async {
    return _configService.creditLimitPolicy.evaluate(
      memberLimitCents: member.creditLimitCents,
      currentBalanceCents: await _repository.getEffectiveBalance(member),
      cartTotalCents:
          items.fold<int>(0, (sum, item) => sum + item.lineTotalCents),
    );
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
