import 'package:drift/drift.dart';
import '../database/database.dart';
import 'members_repository.dart';

class TransactionsRepository {
  final ClubBarDatabase _db;

  TransactionsRepository(this._db);

  /// Get all unsynced transactions (for sync service).
  ///
  /// Quarantined rows are excluded: the backend has refused them permanently,
  /// so re-sending them would loop forever (issue #152).
  Future<List<TransactionsLocalData>> getUnsyncedTransactions() async {
    return (_db.select(_db.transactionsLocal)
          ..where((t) => t.synced.equals(0) & t.quarantinedAt.isNull()))
        .get();
  }

  /// How many sales are queued to sync — the count a pairing-mismatch
  /// warning (ADR-0035) shows staff as "at risk" if this terminal keeps
  /// trusting a backend with a discontinuous history.
  Future<int> getUnsyncedCount() async {
    final rows = await getUnsyncedTransactions();
    return rows.length;
  }

  /// Move permanently rejected sales out of the sync queue, keyed by
  /// transaction id with the rejection code as value.
  ///
  /// The rows are retained — the drink was served, and staff need to be able
  /// to report the sale to an admin.
  Future<void> quarantineTransactions(Map<String, String> reasonsById) async {
    if (reasonsById.isEmpty) return;

    // UTC, like every other instant in this database — `cart_service` writes
    // `occurred_at` as `.toUtc()` and the API labels its columns "Z" (#365).
    // Without it this one column carried the Pi's local time under a name
    // that reads like all the others, which costs nothing while nothing
    // displays it and becomes a two-hour error the moment something does.
    final now = DateTime.now().toUtc().toIso8601String();
    await _db.transaction(() async {
      for (final entry in reasonsById.entries) {
        await (_db.update(_db.transactionsLocal)
              ..where((t) =>
                  t.id.equals(entry.key) & t.quarantinedAt.isNull()))
            .write(TransactionsLocalCompanion(
          quarantinedAt: Value(now),
          quarantineReason: Value(entry.value),
        ));
      }
    });
  }

  /// Quarantined sales, newest first — the failed-sales list staff read from.
  Future<List<TransactionsLocalData>> getQuarantinedTransactions() async {
    return (_db.select(_db.transactionsLocal)
          ..where((t) => t.quarantinedAt.isNotNull())
          ..orderBy([
            (t) => OrderingTerm(
                expression: t.createdAt, mode: OrderingMode.desc)
          ]))
        .get();
  }

  /// How many sales are quarantined — drives the persistent staff warning.
  Future<int> getQuarantinedCount() async {
    final rows = await getQuarantinedTransactions();
    return rows.length;
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

  /// Sum of amountCents for sales this member has bought that the backend has
  /// not confirmed yet.
  ///
  /// "Not confirmed yet" means the same thing here as in
  /// [getUnsyncedTransactions]: still in the sync queue. Quarantined rows are
  /// excluded from both, because a row that has left the queue is no longer
  /// something this terminal can reason about — it will never be resent, so
  /// `synced` stays 0 for the life of this database and the terminal cannot
  /// tell whether the backend has the sale or not.
  ///
  /// Counting them would be wrong in both directions. Before staff act, the
  /// member's tab is inflated forever by money the backend has no record of,
  /// which can push them over their credit limit. After staff re-enter the
  /// sale by hand — the recovery ADR-0033 §4 prescribes — the backend balance
  /// includes it, syncs into `members_cache.balanceCents`, and adding the
  /// local row on top bills the member twice for one drink (issue #417).
  ///
  /// "The drink was drunk, so the member owes it" is the defensible reading
  /// this drops, and the double-count is what it grows out of. A quarantined
  /// sale is surfaced by the failed-sales banner instead, which is where staff
  /// read it off to report it.
  Future<int> getUnsyncedAmountForMember(String memberId) async {
    final transactions = await (_db.select(_db.transactionsLocal)
          ..where((t) =>
              t.memberId.equals(memberId) &
              t.synced.equals(0) &
              t.quarantinedAt.isNull()))
        .get();
    return transactions.fold<int>(0, (sum, txn) => sum + txn.amountCents);
  }

  /// Effective balance (Deckel) for a member: the balance last confirmed by
  /// the backend plus everything bought since that has not synced yet.
  ///
  /// The single definition of "what this member currently owes" — the member
  /// bar, the cart's balance preview and the credit-limit check all read it
  /// from here, so an offline terminal cannot show one number and enforce
  /// against another.
  ///
  /// A quarantined sale is not part of it: it has left the sync queue for
  /// good, so the failed-sales banner owns it, not the tab (issue #417).
  Future<int> getEffectiveBalance(MembersCacheData member) async {
    return member.balanceCents + await getUnsyncedAmountForMember(member.id);
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
