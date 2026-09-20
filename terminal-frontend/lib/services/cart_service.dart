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
    required String sessionId,
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
        sessionId: Value(sessionId),
      );

      await _db.into(_db.dispenserOperations).insert(operation);
      return (true, null);
    } catch (e, stackTrace) {
      AppLog.instance.e('Dispenser operation record creation failed',
          error: e, stackTrace: stackTrace);
      return (false, TerminalErrorKey.dispenserOperationFailed);
    }
  }

  /// The id transaction [index] of dispense [dispenserTxId] has — always, on
  /// every terminal, in every process that ever bills it.
  ///
  /// UUID v5 over [dispenserIdNamespace], so the name `"<tx>:<i>"` maps to one
  /// id and back. That is the whole of the idempotency: the second writer of
  /// token 3 of a dispense writes the row that is already there instead of a
  /// new one with a fresh `v4()` — locally, and on the backend too, because
  /// the client-generated id is what the sync keys on (ADR-0004, ADR-0033).
  static String dispenserTransactionId(String dispenserTxId, int index) =>
      _uuid.v5(dispenserIdNamespace, '$dispenserTxId:$index');

  /// The namespace the ids above live in. A random v4, fixed forever: change
  /// it and every dispense in flight is billed a second time.
  static const String dispenserIdNamespace =
      '0d9a1f3c-6c2e-4d8b-9a47-7f2c1b4e8d10';

  /// Bill the first [upTo] tokens of [op] — the **one** way a dispensed token
  /// becomes money, for checkout and for the recovery service alike (#945).
  ///
  /// Idempotent and atomic, which are two separate promises:
  ///
  /// * **Idempotent**, because row *i* carries [dispenserTransactionId] and is
  ///   inserted with [InsertMode.insertOrIgnore]. Two callers racing, a POST
  ///   retried on the same `tx_id`, a reconciliation tick meeting a live
  ///   dialog, a crash between the inserts and the counter — all of them
  ///   converge on the same `upTo` rows. Before this, each path minted
  ///   `uuid.v4()` and the backend's idempotent sync dutifully accepted both
  ///   sets: 20 tokens dispensed, 40 rows billed.
  /// * **Atomic**, because the inserts and the counter share one
  ///   `_db.transaction`. A crash halfway leaves neither — and even if it
  ///   somehow left the rows without the counter, the next caller would insert
  ///   nothing new.
  ///
  /// `transactionsCreated` is raised to `max(current, upTo)` and never lowered:
  /// it is a high-water mark of what has been billed, not a report of the last
  /// device reading. The rows themselves are the truth; the counter is a
  /// shortcut for reading it.
  ///
  /// The row is written from [op] — `memberId`, `productId`, `priceCents`,
  /// `sessionId` and above all `createdAt`, **the moment of the purchase**,
  /// not the moment of billing. A recovery run days later must not date the
  /// member's drink to the day the dispenser came back.
  ///
  /// Returns tuple: (firstTransactionId, errorKey). The first id is
  /// deterministic too, so a caller that bills again gets the same one back.
  Future<(String?, TerminalErrorKey?)> billDispensedTokens(
    DispenserOperation op, {
    required int upTo,
  }) async {
    if (upTo <= 0) return (null, null);

    try {
      await _db.transaction(() async {
        for (int i = 0; i < upTo; i++) {
          await _db.into(_db.transactionsLocal).insert(
                TransactionsLocalCompanion(
                  id: Value(dispenserTransactionId(op.dispenserTxId, i)),
                  memberId: Value(op.memberId),
                  productId: Value(op.productId),
                  amountCents: Value(op.priceCents), // one token's price
                  transactionType: const Value('purchase'),
                  // No note naming the writer: with one shared row per token,
                  // "created by recovery" would be a claim about whichever
                  // path happened to win the race, and wrong half the time.
                  notes: const Value(null),
                  createdAt: Value(op.createdAt),
                  synced: const Value(0),
                  dispenserTxId: Value(op.dispenserTxId),
                  dispenserRequested: Value(op.requestedQty),
                  dispenserActual: Value(upTo),
                  sessionId: Value(op.sessionId),
                  unitPriceCents: Value(op.priceCents),
                ),
                mode: InsertMode.insertOrIgnore,
              );
        }

        final current = await (_db.select(_db.dispenserOperations)
              ..where((t) => t.dispenserTxId.equals(op.dispenserTxId)))
            .getSingleOrNull();
        if (current != null && current.transactionsCreated >= upTo) return;

        await (_db.update(_db.dispenserOperations)
              ..where((t) => t.dispenserTxId.equals(op.dispenserTxId)))
            .write(DispenserOperationsCompanion(
          transactionsCreated: Value(upTo),
        ));
      });

      return (dispenserTransactionId(op.dispenserTxId, 0), null);
    } catch (e, stackTrace) {
      AppLog.instance.e('Billing dispensed tokens failed',
          error: e, stackTrace: stackTrace);
      return (null, TerminalErrorKey.transactionCreateFailed);
    }
  }

  /// A description of a dispense that is not (or no longer) in the tracking
  /// table, for [billDispensedTokens] to bill from.
  ///
  /// Checkout needs this because the row can legitimately be gone by the time
  /// it bills: a reconciliation tick that met a live dialog bills the tokens
  /// and closes the row. The bill must still be written — it will simply write
  /// the rows the tick already wrote, since the ids come from the dispense and
  /// not from the row.
  static DispenserOperation describeDispense({
    required String dispenserTxId,
    required String memberId,
    required String productId,
    required int priceCents,
    required int requestedQty,
    required String createdAt,
    String? sessionId,
  }) {
    return DispenserOperation(
      dispenserTxId: dispenserTxId,
      memberId: memberId,
      productId: productId,
      priceCents: priceCents,
      requestedQty: requestedQty,
      createdAt: createdAt,
      sessionId: sessionId,
      transactionsCreated: 0,
      lastKnownDispensed: 0,
      pollingActive: 0,
      acknowledged: 0,
    );
  }

  /// The tracking row for [dispenserTxId], or null when it is already closed.
  Future<DispenserOperation?> dispenserOperation(String dispenserTxId) {
    return (_db.select(_db.dispenserOperations)
          ..where((t) => t.dispenserTxId.equals(dispenserTxId)))
        .getSingleOrNull();
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
