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
        // Transaction not found on ESP8266 - CRITICAL ISSUE
        // This could mean:
        // 1. ESP8266 crashed and lost state (no EEPROM persistence)
        // 2. Transaction truly never started (timeout before ESP8266 received it)
        // 3. ESP8266 firmware doesn't implement state persistence
        //
        // Mark as 'not_found' so the retry loop stops hitting it every 60 seconds.
        // DO NOT delete - record is preserved for manual reconciliation audit.
        await (_db.update(_db.dispenserOperations)
              ..where((t) => t.dispenserTxId.equals(op.dispenserTxId)))
            .write(DispenserOperationsCompanion(
              lastKnownState: const Value('not_found'),
              lastPolledAt: Value(DateTime.now().toUtc().toIso8601String()),
            ));

        _logger.e('CRITICAL: Transaction ${op.dispenserTxId} not found on ESP8266. '
            'Tokens may have been dispensed but ESP8266 lost state. '
            'Manual reconciliation required for member ${op.memberId}.');

        return (false, 'Transaction not found on ESP8266 - MANUAL RECONCILIATION REQUIRED. '
            'Check dispenser logs and verify if tokens were dispensed.');
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

      await _updateOperationState(
        op.dispenserTxId,
        lastKnownState: status.state,
        lastKnownDispensed: esp8266Count,
      );

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

  /// Update the state fields this service owns.
  ///
  /// `transactions_created` is deliberately **not** among them: it is raised
  /// inside [CartService.billDispensedTokens]'s transaction, together with the
  /// rows it counts. Writing it from here again is how the two could drift.
  Future<void> _updateOperationState(
    String dispenserTxId, {
    required String lastKnownState,
    required int lastKnownDispensed,
  }) async {
    await (_db.update(_db.dispenserOperations)
          ..where((t) => t.dispenserTxId.equals(dispenserTxId)))
        .write(DispenserOperationsCompanion(
          lastKnownState: Value(lastKnownState),
          lastKnownDispensed: Value(lastKnownDispensed),
        ));
  }

  /// Remove operation from tracking table
  Future<void> _cleanupOperation(String dispenserTxId) async {
    await (_db.delete(_db.dispenserOperations)
          ..where((t) => t.dispenserTxId.equals(dispenserTxId)))
        .go();
  }
}
