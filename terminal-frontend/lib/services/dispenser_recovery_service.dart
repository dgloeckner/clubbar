import 'dart:async';
import 'package:logger/logger.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:uuid/uuid.dart';
import 'package:drift/drift.dart';

/// Service for recovering incomplete dispenser operations after app crashes.
///
/// Crash Recovery Flow:
/// 1. Query dispenser_operations table for incomplete operations
/// 2. For each operation, query ESP8266 for final status using dispenserTxId
/// 3. Create missing transactions (one per actually dispensed token)
/// 4. Clean up tracking table
///
/// This ensures that if the app crashes between dispensing and transaction creation,
/// users are correctly charged for the tokens they actually received.
///
/// Periodic Reconciliation:
/// Runs every 60 seconds to detect ESP8266 crashes mid-dispense.
/// Example: User shown "2 tokens", ESP actually dispensed 3, crashes.
/// Recovery detects 3 - 2 = 1 missing transaction, creates it.
class DispenserRecoveryService {
  final ClubBarDatabase _db;
  final DispenserClient _dispenserClient;
  final Logger _logger;
  final Uuid _uuid = const Uuid();
  Timer? _periodicTimer;

  DispenserRecoveryService({
    required ClubBarDatabase database,
    required DispenserClient client,
    Logger? logger,
  })  : _db = database,
        _dispenserClient = client,
        _logger = logger ?? Logger();

  /// Start periodic reconciliation (every 60 seconds).
  ///
  /// Runs continuously to detect ESP8266 crashes mid-dispense.
  void startPeriodicReconciliation() {
    _periodicTimer?.cancel();
    _periodicTimer = Timer.periodic(
      const Duration(seconds: 60),
      (_) => recoverIncompleteDispenses(),
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

  /// Recover all incomplete dispenser operations.
  ///
  /// This method is called on app boot to handle crash recovery.
  /// Resets pollingActive=1 flags first — at startup there can be no legitimately
  /// active dialog sessions, so any stuck pollingActive=1 records are orphaned.
  Future<void> recoverIncompleteDispenses() async {
    await (_db.update(_db.dispenserOperations))
        .write(const DispenserOperationsCompanion(
          pollingActive: Value(0),
        ));

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
        // ESP8266 reports MORE tokens than we created transactions for!
        final missing = esp8266Count - createdCount;

        _logger.i('RECONCILIATION: ESP8266 reports $esp8266Count dispensed, '
            'but only $createdCount transactions exist. '
            'Creating $missing additional transactions.');

        // Create missing transactions (one per missing token)
        for (int i = 0; i < missing; i++) {
          final txnId = _uuid.v4();
          final now = DateTime.now().toUtc().toIso8601String();

          final transaction = TransactionsLocalCompanion(
            id: Value(txnId),
            memberId: Value(op.memberId),
            productId: Value(op.productId),
            amountCents: Value(op.priceCents), // One token's price
            transactionType: Value('purchase'),
            notes: Value('Auto-created by recovery service'),
            createdAt: Value(now),
            synced: Value(0),
            dispenserTxId: Value(op.dispenserTxId),
            dispenserRequested: Value(op.requestedQty),
            dispenserActual: Value(esp8266Count),
          );

          await _db.into(_db.transactionsLocal).insert(transaction);
        }

        // Update tracking record with new count
        await _updateOperationState(
          op.dispenserTxId,
          transactionsCreated: esp8266Count,
          lastKnownState: status.state,
        );
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

  /// Update operation state fields (transactions_created, last_known_state)
  Future<void> _updateOperationState(
    String dispenserTxId, {
    required int transactionsCreated,
    required String lastKnownState,
  }) async {
    await (_db.update(_db.dispenserOperations)
          ..where((t) => t.dispenserTxId.equals(dispenserTxId)))
        .write(DispenserOperationsCompanion(
          transactionsCreated: Value(transactionsCreated),
          lastKnownState: Value(lastKnownState),
        ));
  }

  /// Remove operation from tracking table
  Future<void> _cleanupOperation(String dispenserTxId) async {
    await (_db.delete(_db.dispenserOperations)
          ..where((t) => t.dispenserTxId.equals(dispenserTxId)))
        .go();
  }
}
