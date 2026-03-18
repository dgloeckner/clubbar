import 'dart:convert';
import 'dart:io';

import 'package:logger/logger.dart';
import '../config/app_config.dart';
import '../database/database.dart';
import '../repository/members_repository.dart';
import '../repository/products_repository.dart';
import '../repository/transactions_repository.dart';
import '../repository/sync_repository.dart';
import 'network_service.dart';

/// Result of a sync operation
enum SyncResult { success, failure, alreadyInProgress }

class SyncService {
  final NetworkService _networkService;
  final MembersRepository _membersRepo;
  final ProductsRepository _productsRepo;
  final TransactionsRepository _transactionsRepo;
  final SyncRepository _syncRepo;
  final Logger _logger;
  final String? _failedTransactionsPath;

  bool _isSyncing = false;
  DateTime? _lastSyncTime;
  DateTime? _lastTransactionSyncTime;
  String? _lastTransactionSyncError;

  SyncService({
    required NetworkService networkService,
    required MembersRepository membersRepo,
    required ProductsRepository productsRepo,
    required TransactionsRepository transactionsRepo,
    required SyncRepository syncRepo,
    Logger? logger,
    String? failedTransactionsPath,
  })  : _networkService = networkService,
        _membersRepo = membersRepo,
        _productsRepo = productsRepo,
        _transactionsRepo = transactionsRepo,
        _syncRepo = syncRepo,
        _logger = logger ?? Logger(),
        _failedTransactionsPath = failedTransactionsPath;

  /// Check if sync is currently in progress
  bool get isSyncing => _isSyncing;

  /// Get last successful sync time
  DateTime? get lastSyncTime => _lastSyncTime;

  /// Get last successful transaction sync time
  DateTime? get lastTransactionSyncTime => _lastTransactionSyncTime;

  /// Get last transaction sync error (null if last transaction sync succeeded or no attempt)
  String? get lastTransactionSyncError => _lastTransactionSyncError;

  /// Check if sync is needed based on interval
  Future<bool> isSyncNeeded() async {
    return _syncRepo.isSyncNeeded(syncInterval: AppConfig.syncInterval);
  }

  /// Perform full sync cycle: fetch members, products, and sync transactions
  Future<SyncResult> syncAll() async {
    if (_isSyncing) {
      _logger.w('Sync already in progress');
      return SyncResult.alreadyInProgress;
    }

    _isSyncing = true;
    try {
      _logger.i('Starting sync cycle');

      // Sync members
      await _syncMembers();

      // Sync categories and products
      await _syncCategories();
      await _syncProducts();

      // Sync transactions (unsynced transactions to backend)
      // Non-fatal: transaction sync errors should not block member/product sync
      try {
        await _syncTransactions();
        _lastTransactionSyncTime = DateTime.now();
        _lastTransactionSyncError = null;
      } catch (e, stackTrace) {
        _logger.w('Transaction sync failed (non-fatal): $e', error: e, stackTrace: stackTrace);
        _lastTransactionSyncError = e.toString();
      }

      // Update last sync time
      final now = DateTime.now();
      await _syncRepo.setLastSyncTime(now.toIso8601String());
      _lastSyncTime = now;

      // Clear retry count and error on success
      await _syncRepo.resetSyncRetryCount();
      await _syncRepo.clearLastSyncError();

      _logger.i('Sync cycle completed successfully');
      return SyncResult.success;
    } catch (e, stackTrace) {
      _logger.e('Sync cycle failed: $e', error: e, stackTrace: stackTrace);
      await _syncRepo.setLastSyncError(e.toString());
      await _syncRepo.incrementSyncRetryCount();
      return SyncResult.failure;
    } finally {
      _isSyncing = false;
    }
  }

  /// Sync members from backend
  Future<void> _syncMembers() async {
    try {
      // Get last sync cursor for delta sync (stored as string, parse to int)
      final cursorStr = await _syncRepo.getLastMembersSyncCursor();
      final since = cursorStr != null ? int.tryParse(cursorStr) : null;

      _logger.i('Syncing members${since != null ? " (since=$since)" : " (full)"}');
      final response = await _networkService.syncMembers(since: since);

      // Handle 304 Not Modified
      if (response == null) {
        _logger.i('Members: not modified');
        return;
      }

      // Separate deleted members (tombstones) from active/updated members
      final deletedMembers = response.members.where((m) => m.deletedAt != null).toList();
      final activeMembers = response.members.where((m) => m.deletedAt == null).toList();

      // Remove deleted members from local cache
      for (final deleted in deletedMembers) {
        await _membersRepo.deleteById(deleted.id);
        _logger.i('Member deleted: ${deleted.id}');
      }

      // Upsert active members into local database
      await _membersRepo.upsertMembers(activeMembers);

      // Store cursor for next delta sync (API returns int, store as string)
      if (response.cursor != null) {
        await _syncRepo.setLastMembersSyncCursor(response.cursor.toString());
        _logger.i('Members: cursor updated to ${response.cursor}');
      } else {
        _logger.w('Members: no cursor in response');
      }

      // Update sync timestamp
      final now = DateTime.now();
      await _syncRepo.setLastMembersSyncTime(now.toIso8601String());

      _logger.i('Members synced: ${response.members.length} items');
    } catch (e, stackTrace) {
      _logger.e('Members sync failed: $e', error: e, stackTrace: stackTrace);
      rethrow;
    }
  }

  /// Sync categories from backend
  Future<void> _syncCategories() async {
    try {
      // Get last sync cursor for delta sync (stored as string, parse to int)
      final cursorStr = await _syncRepo.getLastCategoriesSyncCursor();
      final since = cursorStr != null ? int.tryParse(cursorStr) : null;

      _logger.i('Syncing categories${since != null ? " (since=$since)" : " (full)"}');
      final response = await _networkService.syncCategories(since: since);

      // Handle 304 Not Modified
      if (response == null) {
        _logger.i('Categories: not modified');
        return;
      }

      // Upsert categories into local database
      // Note: the generated Category type does not include deleted_at; tombstone
      // handling is not available for categories in this API version.
      await _productsRepo.upsertCategories(response.categories);

      // Store cursor for next delta sync (API returns int, store as string)
      if (response.cursor != null) {
        await _syncRepo.setLastCategoriesSyncCursor(response.cursor.toString());
        _logger.i('Categories: cursor updated to ${response.cursor}');
      } else {
        _logger.w('Categories: no cursor in response');
      }

      _logger.i('Categories synced: ${response.categories.length} items');
    } catch (e, stackTrace) {
      _logger.e('Categories sync failed: $e', error: e, stackTrace: stackTrace);
      rethrow;
    }
  }

  /// Sync products from backend
  Future<void> _syncProducts() async {
    try {
      // Get last sync cursor for delta sync (stored as string, parse to int)
      final cursorStr = await _syncRepo.getLastProductsSyncCursor();
      final since = cursorStr != null ? int.tryParse(cursorStr) : null;

      _logger.i('Syncing products${since != null ? " (since=$since)" : " (full)"}');
      final response = await _networkService.syncProducts(since: since);

      // Handle 304 Not Modified
      if (response == null) {
        _logger.i('Products: not modified');
        return;
      }

      // Upsert products into local database
      // Note: the generated Product type does not include deleted_at; tombstone
      // handling is not available for products in this API version.
      await _productsRepo.upsertProducts(response.products);

      // Store cursor for next delta sync (API returns int, store as string)
      if (response.cursor != null) {
        await _syncRepo.setLastProductsSyncCursor(response.cursor.toString());
        _logger.i('Products: cursor updated to ${response.cursor}');
      } else {
        _logger.w('Products: no cursor in response');
      }

      // Update sync timestamp
      final now = DateTime.now();
      await _syncRepo.setLastProductsSyncTime(now.toIso8601String());

      _logger.i('Products synced: ${response.products.length} items');
    } catch (e, stackTrace) {
      _logger.e('Products sync failed: $e', error: e, stackTrace: stackTrace);
      rethrow;
    }
  }

  /// Sync unsynced transactions to backend via POST /sync/transactions
  Future<void> _syncTransactions() async {
    final unsyncedTxns = await _transactionsRepo.getUnsyncedTransactions();

    if (unsyncedTxns.isEmpty) {
      _logger.i('No unsynced transactions');
      return;
    }

    try {
      _logger.i('Syncing ${unsyncedTxns.length} transactions');

      // Filter out transactions missing required fields (e.g. legacy records without product_id)
      final validTxns = unsyncedTxns.where((t) => t.productId != null).toList();
      if (validTxns.isEmpty) {
        _logger.i('No valid transactions to sync (${unsyncedTxns.length} skipped: missing product_id)');
        return;
      }
      if (validTxns.length < unsyncedTxns.length) {
        _logger.w('Skipping ${unsyncedTxns.length - validTxns.length} transactions with null product_id');
      }

      // Convert to API format per api/terminal.yaml
      final payloads = validTxns.map((t) => {
        'id': t.id,
        'member_id': t.memberId,
        'product_id': t.productId,
        'amount_cents': t.amountCents,
        'created_at': _normalizeTimestamp(t.createdAt),
      }).toList();

      // POST to backend
      final response = await _networkService.syncTransactions(payloads);

      // Cast member_balances from Map<String, dynamic> to Map<String, int>
      final memberBalances = response.memberBalances.map(
        (key, value) => MapEntry(key, (value as num).toInt()),
      );

      // Atomically mark accepted transactions as synced and update balances
      await _transactionsRepo.completeSyncAtomically(
        acceptedIds: response.acceptedIds,
        memberBalances: memberBalances,
        membersRepo: _membersRepo,
      );

      _logger.i('Transactions synced: ${response.acceptedIds.length} accepted');

      final rejectedCount = response.rejected.count ?? 0;
      if (rejectedCount > 0) {
        _logger.w('Transactions rejected: $rejectedCount');
        for (final error in response.rejected.errors ?? []) {
          _logger.w('  Rejected ${error.transactionId}: ${error.reason}');
        }
      }
    } catch (e, stackTrace) {
      _logger.e('Transactions sync failed: $e', error: e, stackTrace: stackTrace);
      _logFailedTransactions(unsyncedTxns, e);
      rethrow;
    }
  }

  /// Normalize a timestamp to ISO 8601 UTC format (with Z suffix) as expected by the backend.
  String _normalizeTimestamp(String timestamp) {
    try {
      return DateTime.parse(timestamp).toUtc().toIso8601String();
    } catch (_) {
      return timestamp;
    }
  }

  /// Append failed transaction details to a JSON file for later recovery.
  void _logFailedTransactions(List<TransactionsLocalData> txns, Object error) {
    if (_failedTransactionsPath == null) return;

    try {
      final file = File(_failedTransactionsPath);
      List<dynamic> existing = [];

      if (file.existsSync()) {
        try {
          final contents = file.readAsStringSync();
          if (contents.isNotEmpty) {
            existing = jsonDecode(contents) as List<dynamic>;
          }
        } catch (_) {
          // Corrupt file — start fresh
        }
      } else {
        // Ensure parent directory exists
        file.parent.createSync(recursive: true);
      }

      existing.add({
        'timestamp': DateTime.now().toIso8601String(),
        'error': error.toString(),
        'transaction_count': txns.length,
        'transactions': txns.map((t) => {
          'id': t.id,
          'member_id': t.memberId,
          'product_id': t.productId,
          'amount_cents': t.amountCents,
          'transaction_type': t.transactionType,
          'notes': t.notes,
          'created_at': t.createdAt,
        }).toList(),
      });

      file.writeAsStringSync(
        const JsonEncoder.withIndent('  ').convert(existing),
      );
    } catch (e) {
      _logger.w('Failed to write failed transactions log: $e');
    }
  }

  /// Reset sync state and cache (for logout or reset)
  Future<void> reset() async {
    _logger.i('Resetting sync state');
    await _syncRepo.clearAllSyncState();
    _isSyncing = false;
    _lastSyncTime = null;
  }

  /// Get current retry count
  Future<int> getRetryCount() async {
    return _syncRepo.getSyncRetryCount();
  }

  /// Get last sync error message
  Future<String?> getLastError() async {
    return _syncRepo.getLastSyncError();
  }
}
