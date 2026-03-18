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
  final NetworkService _networkService;
  final ClubBarDatabase database;
  final Logger _logger;

  TransactionHistoryService({
    required NetworkService networkService,
    required this.database,
    Logger? logger,
  })  : _networkService = networkService,
        _logger = logger ?? Logger();

  /// Fetch transaction history for a member
  ///
  /// Returns unsynced local transactions first, then top 50 remote transactions.
  /// All ordered by transaction date (newest first).
  ///
  /// Throws [TransactionFetchException] on network or API errors.
  /// Throws [TimeoutException] if request takes longer than 5 seconds.
  Future<List<TransactionListItem>> fetchTransactionHistory({
    required String memberId,
    required String preferredLanguage,
    int remoteLimit = 50,
  }) async {
    try {
      // 1. Query unsynced local transactions
      final localTransactions = await _fetchLocalUnsyncedTransactions(
        memberId: memberId,
        preferredLanguage: preferredLanguage,
      );

      _logger.d('Found ${localTransactions.length} unsynced local transactions');

      // 2. Fetch remote transactions from API
      final remoteTransactions = await _fetchRemoteTransactions(
        memberId: memberId,
        limit: remoteLimit,
      );

      _logger.d('Fetched ${remoteTransactions.length} remote transactions');

      // 3. Combine: unsynced first, then remote
      final allTransactions = [
        ...localTransactions,
        ...remoteTransactions,
      ];

      // 4. Sort by date (newest first)
      allTransactions.sort((a, b) {
        return b.createdAt.compareTo(a.createdAt);
      });

      return allTransactions;
    } catch (e) {
      _logger.e('Error fetching transaction history', error: e);
      rethrow;
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
      final response = await _networkService.getTransactionHistory(
        memberId,
        limit: limit,
      );

      if (response == null) return []; // 304 → empty

      return response.transactions.map((item) {
        final details =
            item.type == TransactionHistoryResponse$Transactions$ItemType.correction
                ? (item.notes ?? 'Correction')
                : item.productName;

        return TransactionListItem(
          id: item.id,
          timestamp: item.createdAt.toLocal(),
          details: details,
          amountCents: item.amountCents,
          syncStatus: TransactionSyncStatus.open,
          productIcon: null,
        );
      }).toList();
    } on NetworkException catch (e) {
      if (e.statusCode == 404) throw MemberNotFoundException(memberId);
      throw TransactionFetchException('Failed to load transactions: ${e.message}');
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
