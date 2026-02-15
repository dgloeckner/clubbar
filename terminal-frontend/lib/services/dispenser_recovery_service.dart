import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/services/dispenser_client.dart';
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
class DispenserRecoveryService {
  final RuderbarDatabase _db;
  final DispenserClient _dispenserClient;
  final Uuid _uuid = const Uuid();

  DispenserRecoveryService({
    required RuderbarDatabase database,
    required DispenserClient client,
  })  : _db = database,
        _dispenserClient = client;

  /// Recover all incomplete dispenser operations.
  ///
  /// This method is called on app boot to handle crash recovery.
  Future<void> recoverIncompleteDispenses() async {
    final (successCount, failureCount, errors) = await _recoverAll();

    if (successCount > 0 || failureCount > 0) {
      // Log recovery results (would be better with proper logger)
      print('Dispenser recovery: $successCount succeeded, $failureCount failed');
      if (errors.isNotEmpty) {
        for (final error in errors) {
          print('  Error: $error');
        }
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
        return (0, 0, []); // Nothing to recover
      }

      // Recover each operation
      for (final op in incompleteOps) {
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
        // DO NOT clean up tracking record - keep it for manual reconciliation
        // Staff must check dispenser logs and create transaction manually if needed
        print('CRITICAL: Transaction ${op.dispenserTxId} not found on ESP8266. '
            'Tokens may have been dispensed but ESP8266 lost state. '
            'Manual reconciliation required for member ${op.memberId}.');

        // Return failure but DON'T clean up - tracking record preserved for audit
        return (false, 'Transaction not found on ESP8266 - MANUAL RECONCILIATION REQUIRED. '
            'Check dispenser logs and verify if tokens were dispensed.');
      } on DispenserException catch (e) {
        // Network error or other dispenser issue
        // Don't clean up - we'll retry on next app start
        return (false, 'Dispenser query failed: ${e.message}');
      }

      // Create transactions for actually dispensed tokens
      final actualDispensed = status.dispensed;

      if (actualDispensed == 0) {
        // No tokens were dispensed - clean up and return success (no charge)
        await _cleanupOperation(op.dispenserTxId);
        return (true, null);
      }

      // Create one transaction per dispensed token
      for (int i = 0; i < actualDispensed; i++) {
        final txnId = _uuid.v4();
        final now = DateTime.now().toUtc().toIso8601String();

        final transaction = TransactionsLocalCompanion(
          id: Value(txnId),
          memberId: Value(op.memberId),
          productId: Value(op.productId),
          amountCents: Value(op.priceCents), // One token's price
          transactionType: Value('purchase'),
          notes: Value(null),
          createdAt: Value(now),
          synced: Value(0),
          dispenserTxId: Value(op.dispenserTxId),
          dispenserRequested: Value(op.requestedQty),
          dispenserActual: Value(actualDispensed),
        );

        await _db.into(_db.transactionsLocal).insert(transaction);
      }

      // Clean up tracking record
      await _cleanupOperation(op.dispenserTxId);

      return (true, null);
    } catch (e) {
      return (false, 'Unexpected error: $e');
    }
  }

  /// Remove operation from tracking table
  Future<void> _cleanupOperation(String dispenserTxId) async {
    await (_db.delete(_db.dispenserOperations)
          ..where((t) => t.dispenserTxId.equals(dispenserTxId)))
        .go();
  }
}
