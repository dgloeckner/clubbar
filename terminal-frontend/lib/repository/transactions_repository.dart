import 'package:drift/drift.dart';
import '../database/database.dart';

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

  /// Clear all transactions (for logout or reset)
  Future<void> clearCache() async {
    await _db.delete(_db.transactionsLocal).go();
  }

  /// Delete transaction by ID
  Future<void> deleteTransaction(String transactionId) async {
    await (_db.delete(_db.transactionsLocal)
          ..where((t) => t.id.equals(transactionId)))
        .go();
  }
}
