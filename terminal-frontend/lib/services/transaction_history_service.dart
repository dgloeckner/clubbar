import 'dart:async';
import 'package:logger/logger.dart';
import 'package:drift/drift.dart' as drift;
import '../models/transaction_list_item.dart';
import '../database/database.dart';
import 'network_service.dart';
import '../generated/terminal.swagger.dart';
import '../generated/terminal.enums.swagger.dart';

/// Service for fetching transaction history (local + remote)
class TransactionHistoryService {
  /// Default for [requestTimeout].
  static const Duration defaultRequestTimeout = Duration(seconds: 5);

  final NetworkService _networkService;
  final ClubBarDatabase database;
  final Logger _logger;

  /// How long a single backend round-trip may take before the member is shown
  /// the retry UI instead of an indefinite spinner.
  final Duration requestTimeout;

  TransactionHistoryService({
    required NetworkService networkService,
    required this.database,
    Logger? logger,
    this.requestTimeout = defaultRequestTimeout,
  })  : _networkService = networkService,
        _logger = logger ?? Logger();

  /// Fetch transaction history for a member.
  ///
  /// Merges the unsynced local transactions with the top [remoteLimit] remote
  /// ones, newest first. When the backend is unreachable the local ones are
  /// returned on their own and [TransactionHistoryResult.isOffline] is set —
  /// the member's balance already includes them, so hiding them would make
  /// balance and history contradict each other (issue #32).
  ///
  /// Throws [TransactionFetchException] on network, API, or timeout errors.
  Future<TransactionHistoryResult> fetchTransactionHistory({
    required String memberId,
    required String preferredLanguage,
    int remoteLimit = 50,
  }) async {
    try {
      // 1. Query unsynced local transactions — available with or without network
      final localTransactions = await _fetchLocalUnsyncedTransactions(
        memberId: memberId,
        preferredLanguage: preferredLanguage,
      );

      _logger.d('Found ${localTransactions.length} unsynced local transactions');

      // 2. Offline: local data is all there is, and it is worth showing
      if (!await _isOnline()) {
        _logger.i('Offline — showing ${localTransactions.length} local transactions only');
        return TransactionHistoryResult(
          transactions: localTransactions,
          isOffline: true,
        );
      }

      // 3. Fetch remote transactions from API
      final remoteTransactions = await _fetchRemoteTransactions(
        memberId: memberId,
        limit: remoteLimit,
      );

      _logger.d('Fetched ${remoteTransactions.length} remote transactions');

      // 4. Combine: unsynced first, then remote
      final allTransactions = [
        ...localTransactions,
        ...remoteTransactions,
      ];

      // 5. Sort by date (newest first)
      allTransactions.sort((a, b) {
        return b.createdAt.compareTo(a.createdAt);
      });

      return TransactionHistoryResult(
        transactions: allTransactions,
        isOffline: false,
      );
    } catch (e) {
      _logger.e('Error fetching transaction history', error: e);
      rethrow;
    }
  }

  /// Health check, bounded by [requestTimeout].
  ///
  /// A health check that never answers means the same thing to the member as
  /// one that answers "no": show them what this terminal knows.
  Future<bool> _isOnline() async {
    try {
      return await _networkService.checkHealth().timeout(requestTimeout);
    } on TimeoutException {
      _logger.w('Health check timed out after $requestTimeout — treating as offline');
      return false;
    }
  }

  /// Fetch unsynced local transactions from database
  Future<List<TransactionListItem>> _fetchLocalUnsyncedTransactions({
    required String memberId,
    required String preferredLanguage,
  }) async {
    // Join transactions with products to get product names and icons
    final query = database.select(database.transactionsLocal).join([
      drift.leftOuterJoin(
        database.productsCache,
        database.productsCache.id.equalsExp(database.transactionsLocal.productId),
      ),
    ])
      ..where(database.transactionsLocal.memberId.equals(memberId) &
          database.transactionsLocal.synced.equals(0))
      ..orderBy([drift.OrderingTerm.desc(database.transactionsLocal.createdAt)]);

    final rows = await query.get();

    return rows.map((row) {
      final transaction = row.readTable(database.transactionsLocal);
      final product = row.readTableOrNull(database.productsCache);

      return TransactionListItem.fromLocalDb(
        transaction,
        product,
        preferredLanguage,
      );
    }).toList();
  }

  /// Fetch remote transactions from backend API via NetworkService
  Future<List<TransactionListItem>> _fetchRemoteTransactions({
    required String memberId,
    required int limit,
  }) async {
    try {
      final response = await _networkService
          .getTransactionHistory(
            memberId,
            limit: limit,
          )
          .timeout(requestTimeout);

      if (response == null) return []; // 304 → empty

      return response.transactions.map((item) {
        final isStornoOrPayout = item.type ==
                TransactionHistoryResponse$Transactions$ItemType.storno ||
            item.type == TransactionHistoryResponse$Transactions$ItemType.payout;
        final details = isStornoOrPayout
            ? (item.notes ??
                (item.type == TransactionHistoryResponse$Transactions$ItemType.payout
                    ? 'Auszahlung'
                    : 'Storno'))
            : item.productName;

        // A transaction that landed in a (non-cancelled) settlement is history,
        // not a running tab — the badge has to say so.
        final settlementId = item.settlementId;

        return TransactionListItem(
          id: item.id,
          timestamp: item.createdAt.toLocal(),
          details: details,
          amountCents: item.amountCents,
          syncStatus: settlementId == null
              ? TransactionSyncStatus.open
              : TransactionSyncStatus.settled,
          productIcon: item.productIcon,
          settlementId: settlementId,
          settlementDate: item.settlementDate,
        );
      }).toList();
    } on NetworkException catch (e) {
      if (e.statusCode == 404) throw MemberNotFoundException(memberId);
      throw TransactionFetchException('Failed to load transactions: ${e.message}');
    } on TimeoutException {
      throw TransactionFetchException(
          'Transaction history timed out after $requestTimeout');
    } catch (e) {
      throw TransactionFetchException('Network error: $e');
    }
  }
}

/// Exception thrown when transaction fetch fails
class TransactionFetchException implements Exception {
  final String message;

  TransactionFetchException(this.message);

  @override
  String toString() => 'TransactionFetchException: $message';
}

/// Exception thrown when member is not found
class MemberNotFoundException implements Exception {
  final String memberId;

  MemberNotFoundException(this.memberId);

  @override
  String toString() => 'MemberNotFoundException: Member $memberId not found';
}
