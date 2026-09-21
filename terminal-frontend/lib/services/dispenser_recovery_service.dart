import 'dart:async';
import 'package:logger/logger.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:drift/drift.dart';

/// Service for recovering incomplete dispenser operations after app crashes.
///
/// Crash Recovery Flow:
/// 1. Query dispenser_operations table for incomplete operations
/// 2. For each operation, query ESP8266 for final status using dispenserTxId
/// 3. Bill the tokens it reports, through [CartService.billDispensedTokens]
/// 4. Clean up tracking table
///
/// This ensures that if the app crashes between dispensing and transaction creation,
/// users are correctly charged for the tokens they actually received.
///
/// Periodic Reconciliation:
/// Runs every 60 seconds to detect ESP8266 crashes mid-dispense.
/// Example: User shown "2 tokens", ESP actually dispensed 3, crashes.
/// Recovery asks for 3 tokens to be billed; the two already there are left
/// untouched and the third is written.
///
/// **This service never mints a transaction id of its own.** It bills through
/// the same method checkout uses, on ids derived from the dispense, so running
/// it twice — or running it while a dialog is finishing — cannot bill a token
/// a second time (#945). [recoverAtStartup] and [reconcile] differ in exactly
/// one thing: only the former clears `pollingActive`.
class DispenserRecoveryService {
  final ClubBarDatabase _db;
  final DispenserClient _dispenserClient;
  final CartService _cartService;
  final Logger _logger;
  Timer? _periodicTimer;

  /// How long a tracking row the dispenser has never acknowledged must exist
  /// before a 404 is read as "the request never arrived" (#947).
  ///
  /// Two minutes is not a tuning knob: it has to outlast the longest a POST
  /// can still be on its way to the device — the session's own request phase
  /// is capped at 30 s including retries — with room to spare, because the
  /// cost of waiting is one more 60-second tick and the cost of being early is
  /// deleting a dispense that then bills nothing.
  static const Duration unacknowledgedGrace = Duration(minutes: 2);

  DispenserRecoveryService({
    required ClubBarDatabase database,
    required DispenserClient client,
    required CartService cartService,
    Logger? logger,
  })  : _db = database,
        _dispenserClient = client,
        _cartService = cartService,
        _logger = logger ?? Logger();

  /// Start periodic reconciliation (every 60 seconds).
  ///
  /// Runs continuously to detect ESP8266 crashes mid-dispense.
  void startPeriodicReconciliation() {
    _periodicTimer?.cancel();
    _periodicTimer = Timer.periodic(
      const Duration(seconds: 60),
      (_) => reconcile(),
    );
  }

  /// Stop periodic reconciliation.
  void stopPeriodicReconciliation() {
    _periodicTimer?.cancel();
    _periodicTimer = null;
  }

  /// Dispose and stop timers.
  void dispose() {
    stopPeriodicReconciliation();
  }

  /// Recover incomplete operations **at app boot**.
  ///
  /// Clears `pollingActive` on every row first: at startup there can be no
  /// legitimately active dialog session, so any row still flagged is orphaned
  /// by whatever killed the app.
  ///
  /// This is deliberately **not** what the 60-second tick calls. It used to be
  /// (#945): once a minute the flag a live dialog had set in `initState` was
  /// wiped, so the `pollingActive == 1` guard below never fired for a dialog
  /// older than a minute, and the tick billed tokens the dialog was about to
  /// bill as well.
  Future<void> recoverAtStartup() async {
    await (_db.update(_db.dispenserOperations))
        .write(const DispenserOperationsCompanion(
          pollingActive: Value(0),
        ));

    await _runAndLog();
  }

  /// One pass of the periodic reconciliation (every 60 s while the app runs).
  ///
  /// Touches no flag it does not own — a dialog's `pollingActive` in
  /// particular. Since [CartService.billDispensedTokens] is idempotent this is
  /// no longer what stands between the member and a double bill; it is what
  /// keeps two components off the dispenser's handful of TCP slots at the same
  /// time, and a guard that silently does nothing is a trap for the next
  /// reader.
  Future<void> reconcile() => _runAndLog();

  Future<void> _runAndLog() async {
    final (successCount, failureCount, errors) = await _recoverAll();

    if (successCount > 0 || failureCount > 0) {
      _logger.i('Dispenser recovery: $successCount succeeded, $failureCount failed');
      for (final error in errors) {
        _logger.w('Dispenser recovery error: $error');
      }
    }
  }

  /// Internal method: recover all incomplete dispenser operations.
  ///
  /// Returns tuple: (successCount, failureCount, errorMessages)
  Future<(int, int, List<String>)> _recoverAll() async {
    int successCount = 0;
    int failureCount = 0;
    final List<String> errors = [];

    try {
      // Find all incomplete operations
      final incompleteOps = await _getIncompleteOperations();

      if (incompleteOps.isEmpty) {
        return (0, 0, <String>[]); // Nothing to recover
      }

      // Recover each operation
      for (final op in incompleteOps) {
        // CRITICAL: Skip if polling is active
        if (op.pollingActive == 1) {
          continue; // Dialog still open, don't interfere
        }

        // Skip permanently failed operations - they need manual reconciliation,
        // not automatic retry. Record is kept for audit; visible in status modal.
        if (op.lastKnownState == 'not_found') {
          continue;
        }

        // CRITICAL: Skip if recently polled (within 30 seconds)
        if (op.lastPolledAt != null) {
          final lastPolled = DateTime.parse(op.lastPolledAt!);
          final now = DateTime.now().toUtc();
          if (now.difference(lastPolled).inSeconds < 30) {
            continue; // Still actively polling, don't interfere
          }
        }

        final result = await _recoverOperation(op);
        if (result.$1) {
          successCount++;
        } else {
          failureCount++;
          errors.add('Operation ${op.dispenserTxId}: ${result.$2}');
        }
      }

      return (successCount, failureCount, errors);
    } catch (e) {
      errors.add('Recovery failed: $e');
      return (successCount, failureCount, errors);
    }
  }

  /// Get all incomplete operations from tracking table
  Future<List<DispenserOperation>> _getIncompleteOperations() async {
    return (_db.select(_db.dispenserOperations)).get();
  }

  /// Recover a single operation
  ///
  /// Returns tuple: (success, errorMessage)
  Future<(bool, String?)> _recoverOperation(DispenserOperation op) async {
    try {
      // Query ESP8266 for final status
      final DispenseResult status;
      try {
        status = await _dispenserClient.getStatus(op.dispenserTxId);
      } on DispenserNotFoundException {
        return await _resolveNotFound(op);
      } on DispenserException catch (e) {
        // Network error or other dispenser issue
        // Don't clean up - we'll retry on next app start
        return (false, 'Dispenser query failed: ${e.message}');
      }

      // Compare ESP8266 count vs already-created transactions
      final esp8266Count = status.dispensed;
      final createdCount = op.transactionsCreated;

      if (esp8266Count > createdCount) {
        _logger.i('RECONCILIATION: ESP8266 reports $esp8266Count dispensed, '
            'but only $createdCount transactions are recorded for '
            '${op.dispenserTxId}. Billing up to $esp8266Count.');
      }

      if (esp8266Count > 0) {
        // Always asked for, not only when the counter looks behind: the
        // counter can lag the rows (a crash between the inserts and the
        // update) as easily as the rows can lag the counter. Billing is
        // idempotent, so asking for all `esp8266Count` rows is both the
        // cheapest and the only correct question — it writes exactly the rows
        // that are missing, and nothing when none are (#945).
        final (_, billingError) =
            await _cartService.billDispensedTokens(op, upTo: esp8266Count);
        if (billingError != null) {
          return (false, 'Billing failed: $billingError');
        }
      }

      // The device answered, so it knows this tx_id. That is the fact a later
      // 404 is read against (#947), and it is latched here rather than only in
      // the dialog: a row whose dialog died before the first answer can still
      // be acknowledged by the very first reconciliation tick that reaches the
      // device.
      await _updateOperationState(
        op.dispenserTxId,
        lastKnownState: status.state,
        lastKnownDispensed: esp8266Count,
        acknowledged: true,
      );

      // A count the device does not vouch for settles nothing, whatever state
      // it carries: `count_reliable: false` is a dispenser saying it lost its
      // tally across a reset and is reporting a **lower bound**. The tokens it
      // is sure of are billed above; the row stays, so the difference is a
      // question for a human instead of a silent discount. Closing it here
      // would undo the same rule checkout follows (#946, #947).
      if (status.countReliable == false) {
        _logger.w('Dispenser ${op.dispenserTxId} reports an unreliable count '
            '($esp8266Count of ${op.requestedQty} tokens) for member '
            '${op.memberId}. Billed the reported lower bound; keeping the '
            'record so the difference can be settled by hand.');
        return (false, 'Dispenser could not vouch for its count - keeping the '
            'record for reconciliation by hand.');
      }

      // Clean up tracking record if ESP8266 state is final
      if (status.state == 'done' || status.state == 'error') {
        await _cleanupOperation(op.dispenserTxId);
        return (true, null);
      }

      // State still "dispensing" - keep tracking record, retry later
      return (false, 'ESP8266 still dispensing, will retry');
    } catch (e) {
      return (false, 'Unexpected error: $e');
    }
  }

  /// What a `404` from the dispenser means for [op] — the whole of #947.
  ///
  /// `GET /dispense/{tx_id}` answering 404 has always had two readings, and
  /// the terminal used to pick the frightening one every time:
  ///
  /// | Did the device ever answer for this tx_id? | 404 means | What happens |
  /// |---|---|---|
  /// | never | the request never arrived, nothing was dispensed | the row is deleted, nothing is billed, one info line |
  /// | yes | the device accepted it and has since lost it | `not_found`, kept, shown under *manual reconciliation required* |
  ///
  /// The common case is the harmless one: the dispenser was unplugged, the
  /// POST never got there, no token fell. Every such checkout used to leave a
  /// permanent red record, and after a weekend with the machine off the one
  /// row that did need a human was buried in the noise.
  ///
  /// Two things make "never acknowledged → never happened" safe to conclude,
  /// and both are load-bearing:
  ///
  /// * the abandoned POST is **dead, not merely timed out** — the session
  ///   stops at the next opportunity and sends nothing further (#946), so no
  ///   request can still be on its way to the device;
  /// * the verdict waits until the row is older than [unacknowledgedGrace].
  ///   A request in flight while the tick runs would otherwise be declared
  ///   never to have happened moments before it arrives — the one way this
  ///   rule could delete a row that was about to be billed.
  ///
  /// A row inside the grace window is left exactly as it is: no state written,
  /// nothing deleted, asked again on the next tick.
  Future<(bool, String?)> _resolveNotFound(DispenserOperation op) async {
    if (op.acknowledged == 0) {
      final age = _age(op);
      if (age != null && age < unacknowledgedGrace) {
        return (false, 'Dispenser does not know ${op.dispenserTxId} yet and the '
            'request may still be in flight - asking again next tick.');
      }

      await _cleanupOperation(op.dispenserTxId);
      _logger.i('Dispense ${op.dispenserTxId} was never acknowledged by the '
          'dispenser and it does not know the transaction: the request never '
          'arrived. Nothing was dispensed and nothing is billed for member '
          '${op.memberId}; the record is closed.');
      return (true, null);
    }

    // The device did answer for this tx_id once, and now denies knowing it.
    // That is a transaction it accepted and lost — tokens may be on the floor
    // and nobody but a human can say how many.
    //
    // Mark as 'not_found' so the retry loop stops hitting it every 60 seconds.
    // DO NOT delete - record is preserved for manual reconciliation audit.
    await (_db.update(_db.dispenserOperations)
          ..where((t) => t.dispenserTxId.equals(op.dispenserTxId)))
        .write(DispenserOperationsCompanion(
          lastKnownState: const Value('not_found'),
          lastPolledAt: Value(DateTime.now().toUtc().toIso8601String()),
        ));

    _logger.e('CRITICAL: Transaction ${op.dispenserTxId} was acknowledged by '
        'the dispenser and is now unknown to it. Tokens may have been '
        'dispensed but the dispenser lost the transaction. '
        'Manual reconciliation required for member ${op.memberId}.');

    return (false, 'Transaction not found on ESP8266 - MANUAL RECONCILIATION REQUIRED. '
        'Check dispenser logs and verify if tokens were dispensed.');
  }

  /// How long ago [op] was created, or null when its timestamp is unreadable.
  ///
  /// An unreadable one is treated as *no age at all* rather than as infinitely
  /// old, so a garbled row is never deleted on the strength of it.
  Duration? _age(DispenserOperation op) {
    final created = DateTime.tryParse(op.createdAt);
    if (created == null) return null;
    return DateTime.now().toUtc().difference(created.toUtc());
  }

  /// Update the state fields this service owns.
  ///
  /// `transactions_created` is deliberately **not** among them: it is raised
  /// inside [CartService.billDispensedTokens]'s transaction, together with the
  /// rows it counts. Writing it from here again is how the two could drift.
  Future<void> _updateOperationState(
    String dispenserTxId, {
    required String lastKnownState,
    required int lastKnownDispensed,
    bool acknowledged = false,
  }) async {
    await (_db.update(_db.dispenserOperations)
          ..where((t) => t.dispenserTxId.equals(dispenserTxId)))
        .write(DispenserOperationsCompanion(
          lastKnownState: Value(lastKnownState),
          lastKnownDispensed: Value(lastKnownDispensed),
          acknowledged:
              acknowledged ? const Value(1) : const Value.absent(),
        ));
  }

  /// Remove operation from tracking table
  Future<void> _cleanupOperation(String dispenserTxId) async {
    await (_db.delete(_db.dispenserOperations)
          ..where((t) => t.dispenserTxId.equals(dispenserTxId)))
        .go();
  }
}
