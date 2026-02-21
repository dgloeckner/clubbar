import 'package:drift/drift.dart';
import '../database/database.dart';
import 'members_repository.dart';

class TransactionsRepository {
  final RuderbarDatabase _db;

  TransactionsRepository(this._db);

  /// Get all unsynced transactions (for sync service)
  Future<List<TransactionsLocalData>> getUnsyncedTransactions() async {
    return (_db.select(_db.transactionsLocal)
          ..where((t) => t.synced.equals(0)))
        .get();
  }

  /// Get transaction by ID
  Future<TransactionsLocalData?> getTransaction(String transactionId) async {
    return (_db.select(_db.transactionsLocal)
          ..where((t) => t.id.equals(transactionId)))
        .getSingleOrNull();
  }

  /// Get all transactions for a member
  Future<List<TransactionsLocalData>> getTransactionsByMember(String memberId) async {
    return (_db.select(_db.transactionsLocal)
          ..where((t) => t.memberId.equals(memberId))
          ..orderBy([(t) => OrderingTerm(expression: t.createdAt, mode: OrderingMode.desc)]))
        .get();
  }

  /// Get transaction count (total transactions recorded)
  Future<int> getTransactionCount() async {
    final countQuery = _db.select(_db.transactionsLocal);
    return countQuery.get().then((txns) => txns.length);
  }

  /// Insert local transaction
  Future<void> insertTransaction(TransactionsLocalData transaction) async {
    await _db.into(_db.transactionsLocal).insert(
      TransactionsLocalCompanion(
        id: Value(transaction.id),
        memberId: Value(transaction.memberId),
        productId: Value(transaction.productId),
        amountCents: Value(transaction.amountCents),
        transactionType: Value(transaction.transactionType),
        notes: Value(transaction.notes),
        createdAt: Value(transaction.createdAt),
        synced: Value(0), // Always start unsynced
      ),
    );
  }

  /// Mark transactions as synced
  Future<void> markAsSynced(List<String> transactionIds) async {
    if (transactionIds.isEmpty) return;

    for (final txnId in transactionIds) {
      await (_db.update(_db.transactionsLocal)
            ..where((t) => t.id.equals(txnId)))
          .write(const TransactionsLocalCompanion(synced: Value(1)));
    }
  }

  /// Get total amount for a member (sum of amountCents)
  Future<int> getTotalAmountForMember(String memberId) async {
    final transactions = await getTransactionsByMember(memberId);
    return transactions.fold<int>(0, (sum, txn) => sum + txn.amountCents);
  }

  /// Get sum of amountCents for unsynced transactions for a specific member
  Future<int> getUnsyncedAmountForMember(String memberId) async {
    final transactions = await (_db.select(_db.transactionsLocal)
          ..where((t) =>
              t.memberId.equals(memberId) & t.synced.equals(0)))
        .get();
    return transactions.fold<int>(0, (sum, txn) => sum + txn.amountCents);
  }

  /// Clear all transactions (for logout or reset)
  Future<void> clearCache() async {
    await _db.delete(_db.transactionsLocal).go();
  }

  /// Atomically mark transactions as synced and update member balances.
  /// Runs in a single SQLite transaction for consistency.
  Future<void> completeSyncAtomically({
    required List<String> acceptedIds,
    required Map<String, int> memberBalances,
    required MembersRepository membersRepo,
  }) async {
    await _db.transaction(() async {
      // Mark accepted transactions as synced
      for (final txnId in acceptedIds) {
        await (_db.update(_db.transactionsLocal)
              ..where((t) => t.id.equals(txnId)))
            .write(const TransactionsLocalCompanion(synced: Value(1)));
      }

      // Update member balances from backend response
      for (final entry in memberBalances.entries) {
        await membersRepo.updateMemberBalance(entry.key, entry.value);
      }
    });
  }

  /// Delete transaction by ID
  Future<void> deleteTransaction(String transactionId) async {
    await (_db.delete(_db.transactionsLocal)
          ..where((t) => t.id.equals(transactionId)))
        .go();
  }

  /// Insert transaction using companion (supports all optional fields)
  Future<void> insertTransactionCompanion(TransactionsLocalCompanion transaction) async {
    await _db.into(_db.transactionsLocal).insert(transaction);
  }

  /// Update transaction fields by ID
  Future<void> updateTransaction(String transactionId, Map<String, dynamic> updates) async {
    final companion = TransactionsLocalCompanion(
      dispenserActual: updates.containsKey('dispenser_actual') 
          ? Value(updates['dispenser_actual'] as int?) 
          : Value.absent(),
      amountCents: updates.containsKey('amount_cents') 
          ? Value(updates['amount_cents'] as int) 
          : Value.absent(),
    );

    await (_db.update(_db.transactionsLocal)
          ..where((t) => t.id.equals(transactionId)))
        .write(companion);
  }

  /// Get incomplete dispenser transactions (for crash recovery)
  /// Returns transactions that have dispenser_tx_id but dispenser_actual is null
  Future<List<TransactionsLocalData>> getIncompleteDispenserTransactions() async {
    return (_db.select(_db.transactionsLocal)
          ..where((t) =>
              t.dispenserTxId.isNotNull() &
              t.dispenserActual.isNull()))
        .get();
  }

  /// Sum of abs(amountCents) for all transactions belonging to a session.
  /// This is the actual billed amount for the checkout session.
  Future<int> getSessionTotal(String sessionId) async {
    final rows = await (_db.select(_db.transactionsLocal)
          ..where((t) => t.sessionId.equals(sessionId)))
        .get();
    return rows.fold<int>(0, (sum, t) => sum + t.amountCents.abs());
  }

  /// Returns the dispenser row for a session (the row that has a dispenserTxId),
  /// or null if the session had no dispenser items.
  Future<TransactionsLocalData?> getSessionDispenserInfo(String sessionId) async {
    return (_db.select(_db.transactionsLocal)
          ..where((t) =>
              t.sessionId.equals(sessionId) & t.dispenserTxId.isNotNull())
          ..limit(1))
        .getSingleOrNull();
  }
}
