import 'package:drift/drift.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/services/dispenser_client.dart';

/// Service for recovering incomplete dispenser transactions after app crash
///
/// On app startup, queries the database for transactions with a dispenser_tx_id
/// but no dispenser_actual count, then polls the ESP8266 to determine if tokens
/// were actually dispensed during the crash.
class DispenserRecoveryService {
  final RuderbarDatabase database;
  final DispenserClient client;

  DispenserRecoveryService({
    required this.database,
    required this.client,
  });

  /// Recover incomplete dispenser transactions
  ///
  /// Queries for transactions where:
  /// - dispenser_tx_id IS NOT NULL (dispense was initiated)
  /// - dispenser_actual IS NULL (completion status unknown)
  /// - synced = 0 (not yet synced to backend)
  ///
  /// For each transaction, queries the ESP8266 for actual status and updates
  /// the local transaction accordingly.
  Future<void> recoverIncompleteDispenses() async {
    // Query for incomplete dispenser transactions
    final incompleteQuery = database.select(database.transactionsLocal)
      ..where((t) =>
          t.dispenserTxId.isNotNull() &
          t.dispenserActual.isNull() &
          t.synced.equals(0));

    final incompleteTxs = await incompleteQuery.get();

    if (incompleteTxs.isEmpty) {
      // No incomplete transactions - nothing to recover
      return;
    }

    // Process each incomplete transaction
    for (final tx in incompleteTxs) {
      final dispenserTxId = tx.dispenserTxId!;

      try {
        // Query ESP8266 for actual state
        final result = await client.getStatus(dispenserTxId);

        if (result.state == 'done') {
          // Dispense completed successfully during crash
          await _markTransactionCompleted(tx.id, result.dispensed);
        } else if (result.state == 'error') {
          // Partial dispense or failure - adjust amount
          await _adjustTransactionForPartialDispense(
            tx.id,
            tx.productId,
            result.dispensed,
          );
        }
        // If state is 'dispensing', leave as-is (will be recovered on next boot)
      } on DispenserNotFoundException {
        // Transaction expired from ESP8266 ring buffer
        // Leave as-is for manual reconciliation
      } catch (e) {
        // ESP8266 unreachable or other error
        // Leave as-is - will retry on next boot
      }
    }
  }

  /// Mark transaction as completed with actual dispensed count
  Future<void> _markTransactionCompleted(String txId, int dispensed) async {
    await (database.update(database.transactionsLocal)
          ..where((t) => t.id.equals(txId)))
        .write(
      TransactionsLocalCompanion(
        dispenserActual: Value(dispensed),
        synced: const Value(1), // Mark as ready to sync
      ),
    );
  }

  /// Adjust transaction amount for partial dispense
  Future<void> _adjustTransactionForPartialDispense(
    String txId,
    String? productId,
    int actualDispensed,
  ) async {
    // Get product price to calculate adjusted amount
    int? pricePerToken;
    if (productId != null) {
      final product = await (database.select(database.productsCache)
            ..where((p) => p.id.equals(productId)))
          .getSingleOrNull();
      pricePerToken = product?.priceCents;
    }

    if (pricePerToken == null) {
      // Cannot calculate adjusted amount without price - mark with actual count only
      await (database.update(database.transactionsLocal)
            ..where((t) => t.id.equals(txId)))
          .write(
        TransactionsLocalCompanion(
          dispenserActual: Value(actualDispensed),
        ),
      );
      return;
    }

    // Calculate adjusted amount based on actual dispensed tokens
    final adjustedAmount = actualDispensed * pricePerToken;

    await (database.update(database.transactionsLocal)
          ..where((t) => t.id.equals(txId)))
        .write(
      TransactionsLocalCompanion(
        amountCents: Value(adjustedAmount),
        dispenserActual: Value(actualDispensed),
      ),
    );
  }
}
