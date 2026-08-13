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

/// Result of comparing a backend's reported instance_id against the one this
/// terminal last synced with (ADR-0035).
enum PairingResult { paired, mismatch }

class SyncService {
  /// Largest batch `POST /sync/transactions` accepts, per `api/terminal.yaml`.
  ///
  /// Must not exceed the server's own cap: it answers an oversized batch with
  /// a whole-request 400, and a whole-request failure is retried unchanged, so
  /// exceeding it does not degrade the upload — it stops it forever (#259).
  static const int maxSyncBatchSize = 100;

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
        // Still non-fatal to the sync cycle (no rethrow) — but logged at
        // error level so it actually reaches error.log instead of being
        // silently excluded by ErrorFileOutput's level filter.
        _logger.e('Transaction sync failed (non-fatal): $e', error: e, stackTrace: stackTrace);
        _lastTransactionSyncError = e.toString();
      }

      // Refresh open tabs last, so it sees the balances the upload just moved.
      // Non-fatal for the same reason: a stale balance must not cost the
      // terminal its member and product data.
      try {
        await _refreshOpenBalances();
      } catch (e, stackTrace) {
        _logger.e('Balance refresh failed (non-fatal): $e', error: e, stackTrace: stackTrace);
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

      // A row without a product_id can never satisfy the contract, so no
      // number of retries will store it. Quarantine it rather than skipping it
      // on every cycle for the rest of the terminal's life (issue #152).
      final unsendable = unsyncedTxns.where((t) => t.productId == null).toList();
      if (unsendable.isNotEmpty) {
        _logger.w('Quarantining ${unsendable.length} transactions with null product_id');
        await _transactionsRepo.quarantineTransactions({
          for (final t in unsendable) t.id: 'missing_product_id',
        });
      }

      final validTxns = unsyncedTxns.where((t) => t.productId != null).toList();
      if (validTxns.isEmpty) {
        _logger.i('No valid transactions to sync');
        return;
      }

      // The server refuses a batch larger than maxSyncBatchSize outright, with
      // a whole-request 400 — and a whole-request failure is retried unchanged,
      // so an oversized queue would never sync again. The queue is unbounded
      // and an offline evening passes 100 easily, so it is split here (#259).
      for (var start = 0; start < validTxns.length; start += maxSyncBatchSize) {
        final end = start + maxSyncBatchSize;
        await _uploadTransactionBatch(
          validTxns.sublist(start, end < validTxns.length ? end : validTxns.length),
        );
      }
    } catch (e, stackTrace) {
      _logger.e('Transactions sync failed: $e', error: e, stackTrace: stackTrace);
      _logFailedTransactions(unsyncedTxns, e);
      rethrow;
    }
  }

  /// Upload one batch and settle its outcome: accepted rows are marked synced,
  /// permanently rejected rows are quarantined.
  ///
  /// A transient failure throws out of here, which aborts the remaining
  /// batches and leaves them queued for an unchanged retry. Batches that
  /// already committed stay committed — their rows are on the server, so
  /// resending them would be pointless rather than harmful.
  Future<void> _uploadTransactionBatch(List<TransactionsLocalData> batch) async {
    // Convert to API format per api/terminal.yaml
    final payloads = batch.map((t) => {
      'id': t.id,
      'member_id': t.memberId,
      'product_id': t.productId,
      'amount_cents': t.amountCents,
      'created_at': _normalizeTimestamp(t.createdAt),
    }).toList();

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

    // A per-item rejection is permanent: the server tells us it could not
    // store the row and would refuse it again. Quarantine it so it leaves
    // the queue without being lost — a transient failure, by contrast,
    // throws and leaves the whole batch queued for an unchanged retry.
    final rejections = <String, String>{};
    for (final rejected in response.rejected.errors ?? []) {
      final id = rejected.transactionId;
      if (id == null) continue;
      rejections[id] = rejected.error ?? 'unknown';
      _logger.w('  Rejected $id: ${rejected.error} ${rejected.message ?? ''}');
    }
    if (rejections.isNotEmpty) {
      _logger.w('Transactions rejected: ${rejections.length}');
      await _transactionsRepo.quarantineTransactions(rejections);
    }
  }

  /// Re-ask the backend for every cached tab that is not zero (#374).
  ///
  /// `member_balances` only ever reports the members a request *names*, and
  /// until now the only way to name one was to sell them something. So a
  /// balance the terminal did not move itself — an admin's storno, a
  /// settlement, a sale on another terminal — never reached this cache, and the
  /// credit-limit banner went on warning about money the member had already
  /// paid. `member_ids` is the ask (#191, ADR-0023), and an empty `transactions`
  /// array makes it a pure read.
  ///
  /// Only non-zero tabs are asked about: a cached zero cannot be visibly stale
  /// (it grows again only through a purchase, which reports its own balance
  /// back), and a scan refreshes the scanned member regardless.
  Future<void> _refreshOpenBalances() async {
    final memberIds = await _membersRepo.getMemberIdsWithOpenBalance();
    if (memberIds.isEmpty) {
      _logger.i('No open balances to refresh');
      return;
    }

    // Same cap as an upload: the server refuses an oversized request whole.
    var refreshed = 0;
    for (var start = 0; start < memberIds.length; start += maxSyncBatchSize) {
      final end = start + maxSyncBatchSize;
      final chunk = memberIds.sublist(
          start, end < memberIds.length ? end : memberIds.length);

      final response =
          await _networkService.syncTransactions(const [], memberIds: chunk);

      // An id the backend does not know is omitted rather than reported as 0;
      // writing a phantom zero would wipe a real tab (ADR-0023).
      for (final entry in response.memberBalances.entries) {
        final value = entry.value;
        if (value is num) {
          await _membersRepo.updateMemberBalance(entry.key, value.toInt());
          refreshed++;
        }
      }
    }

    _logger.i('Balances refreshed: $refreshed of ${memberIds.length} open tabs');
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

  /// Compare the backend's reported instance_id against the one this
  /// terminal last synced with (ADR-0035).
  ///
  /// [remoteInstanceId] null means the backend has no instance_config row
  /// yet (pre-migration) — there is nothing to compare against, so this
  /// proceeds rather than blocking; the same fail-soft contract as the
  /// backend's own getInstanceId().
  ///
  /// A first-ever pairing is trust-on-first-use: nothing was stored before,
  /// so the reported id is simply adopted. Once something is stored, a
  /// mismatch is never auto-corrected here — only [acknowledgePairing] may
  /// overwrite it, after a human has deliberately confirmed it is safe.
  Future<PairingResult> checkPairing(String? remoteInstanceId) async {
    if (remoteInstanceId == null) {
      return PairingResult.paired;
    }

    final paired = await _syncRepo.getPairedBackendInstanceId();
    if (paired == null) {
      await _syncRepo.setPairedBackendInstanceId(remoteInstanceId);
      return PairingResult.paired;
    }

    return paired == remoteInstanceId ? PairingResult.paired : PairingResult.mismatch;
  }

  /// Staff confirmed a pairing mismatch is safe to trust — re-pair against
  /// the given instance_id so sync can resume.
  Future<void> acknowledgePairing(String instanceId) async {
    await _syncRepo.setPairedBackendInstanceId(instanceId);
  }

  /// How many local sales are queued to sync — the count a pairing-mismatch
  /// warning shows staff as "at risk" (ADR-0035).
  Future<int> getUnsyncedCount() async {
    return _transactionsRepo.getUnsyncedCount();
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
