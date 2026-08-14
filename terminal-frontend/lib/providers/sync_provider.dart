import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/error_signal.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/quarantine_provider.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/sync_service.dart';

/// Three-state connectivity status for the terminal.
enum ConnectionStatus { online, offline, error }

class SyncProvider extends ChangeNotifier with ErrorSignal {
  final SyncService _syncService;
  final MembersProvider _membersProvider;
  final ProductsProvider _productsProvider;
  final NetworkService _networkService;

  /// Optional: absent in tests and in headless setups that have no UI to warn.
  final QuarantineProvider? _quarantineProvider;

  /// Optional: absent in tests that do not care about instance branding
  /// (ADR-0034). When present, a successful health check feeds the
  /// backend-reported instance name into it every sync cycle.
  final ConfigService? _configService;

  bool _isSyncing = false;
  bool _disposed = false;
  DateTime? _lastSyncTime;
  DateTime? _lastSuccessfulTransactionSync;
  int _retryCount = 0;
  Timer? _backgroundTimer;
  ConnectionStatus _connectionStatus = ConnectionStatus.online;
  DateTime? _degradedSince;

  // ADR-0035: set the moment a synced backend reports a different
  // instance_id than the one this terminal last paired with. Never cleared
  // by startSync() itself — only acknowledgePairingMismatch() may clear it,
  // after staff have deliberately confirmed it is safe.
  bool _pairingMismatch = false;
  int _pairingMismatchTransactionCount = 0;

  // #395: set when the backend refuses this terminal's token as expired. Unlike
  // a pairing mismatch this is not a judgement call anyone at the bar can make
  // — the credential is gone and only an administrator can issue a new one — so
  // there is no acknowledge action, only a rotation entered at this device,
  // which clears it on the next successful cycle.
  bool _credentialExpired = false;

  // Guards startSync() and acknowledgePairingMismatch() from interleaving.
  // Both read and write the same paired-instance state; without this, a
  // background timer tick racing a staff-initiated acknowledgement could
  // read a stale local value and re-flag a mismatch the instant it was
  // just cleared.
  bool _syncCycleInFlight = false;

  SyncProvider({
    required SyncService syncService,
    required MembersProvider membersProvider,
    required ProductsProvider productsProvider,
    required NetworkService networkService,
    QuarantineProvider? quarantineProvider,
    ConfigService? configService,
  })  : _syncService = syncService,
        _membersProvider = membersProvider,
        _productsProvider = productsProvider,
        _networkService = networkService,
        _quarantineProvider = quarantineProvider,
        _configService = configService;

  bool get isSyncing => _isSyncing;
  DateTime? get lastSyncTime => _lastSyncTime;
  DateTime? get lastSuccessfulTransactionSync => _lastSuccessfulTransactionSync;
  int get retryCount => _retryCount;
  ConnectionStatus get connectionStatus => _connectionStatus;
  bool get pairingMismatch => _pairingMismatch;
  int get pairingMismatchTransactionCount => _pairingMismatchTransactionCount;

  /// This terminal's API token has expired: nothing it rings up can reach the
  /// backend, so sales are stopped until an administrator rotates the token.
  bool get credentialExpired => _credentialExpired;

  /// When the terminal first stopped being healthy, or null while online.
  ///
  /// This is the *transition* time, not the time of the latest failed attempt:
  /// staff asking "how long has this been broken?" want the start of the
  /// outage, and a retry every 60 s would otherwise keep resetting it to now.
  DateTime? get degradedSince => _degradedSince;

  /// Single place where [_connectionStatus] moves, so [_degradedSince] cannot
  /// drift out of step with it.
  void _setConnectionStatus(ConnectionStatus next) {
    if (next == ConnectionStatus.online) {
      _degradedSince = null;
    } else {
      _degradedSince ??= DateTime.now();
    }
    _connectionStatus = next;
  }

  /// Manually trigger sync
  Future<void> startSync() async {
    if (_syncCycleInFlight) return;
    _syncCycleInFlight = true;
    try {
      await _runSyncCycle();
    } finally {
      _syncCycleInFlight = false;
    }
  }

  Future<void> _runSyncCycle() async {
    // Always run health check to keep connectionStatus accurate
    final healthy = await _networkService.checkHealth();
    if (!healthy) {
      _setConnectionStatus(ConnectionStatus.offline);
      _retryCount++;
      // Emitted on every failed attempt, not just on the transition, so a
      // repeated outage still signals a fresh display event.
      emitError(TerminalErrorKey.backendUnreachable);
      notifyListeners();
      return;
    }

    // Health passed — if we were offline, update immediately
    if (_connectionStatus == ConnectionStatus.offline) {
      _setConnectionStatus(ConnectionStatus.online);
      resetError();
      _retryCount = 0;
      notifyListeners();
    }

    // ADR-0035: a backend with a different instance_id than the one this
    // terminal last synced with has a discontinuous history — hard-block
    // before any push or pull, including the instance-name propagation and
    // member/product delta sync below. A mismatch never auto-clears; only
    // acknowledgePairingMismatch() may.
    final remoteInstanceId = await _networkService.fetchInstanceId();
    final pairing = await _syncService.checkPairing(remoteInstanceId);
    if (pairing == PairingResult.mismatch) {
      _pairingMismatch = true;
      try {
        _pairingMismatchTransactionCount = await _syncService.getUnsyncedCount();
      } catch (_) {
        // The count is a nicety for the warning dialog, not a precondition
        // for the block itself — staff must still be told about the
        // mismatch even if this particular read fails.
      } finally {
        notifyListeners();
      }
      return;
    }

    // Best-effort: propagate the backend's instance name (ADR-0034) into
    // ConfigService so the header can pick it up on its next rebuild. A
    // failed/timed-out fetch must not block or fail the sync cycle, matching
    // checkHealth()'s own fail-soft behaviour — so a caught exception here
    // simply leaves ConfigService's previously known name in place.
    if (_configService != null) {
      try {
        final instanceName = await _networkService.fetchInstanceName();
        if (instanceName != null && instanceName.isNotEmpty) {
          _configService.setBackendDisplayName(instanceName);
        }
      } catch (_) {
        // Fail-soft: keep whatever instance name ConfigService already has.
      }
    }

    // Check if a full sync cycle is needed
    final needed = await _syncService.isSyncNeeded();
    if (!needed) {
      return;
    }

    _isSyncing = true;
    notifyListeners();

    try {
      final result = await _syncService.syncAll();

      if (result == SyncResult.success) {
        // Refresh other providers
        await _membersProvider.refreshMembers();
        await _productsProvider.refreshProducts();

        // A sync cycle is the only thing that can quarantine a sale, so this
        // is where the staff warning learns about one (issue #152).
        await _quarantineProvider?.refresh();

        _lastSyncTime = DateTime.now();
        _retryCount = 0;

        // Track transaction sync time from SyncService
        final txnSyncTime = _syncService.lastTransactionSyncTime;
        if (txnSyncTime != null) {
          _lastSuccessfulTransactionSync = txnSyncTime;
        }

        // Check if transaction sync had a non-fatal error
        final txnError = _syncService.lastTransactionSyncError;
        if (txnError != null) {
          emitError(TerminalErrorKey.transactionSyncFailed, cause: txnError);
          _setConnectionStatus(ConnectionStatus.error);
        } else {
          resetError();
          _setConnectionStatus(ConnectionStatus.online);
        }
      } else {
        // Health passed but sync failed → error state
        emitError(TerminalErrorKey.syncFailed,
            cause: await _syncService.getLastError());
        _retryCount++;
        _setConnectionStatus(ConnectionStatus.error);
      }
    } catch (e, stackTrace) {
      emitError(TerminalErrorKey.syncFailed, cause: e, stackTrace: stackTrace);
      _retryCount++;
      _setConnectionStatus(ConnectionStatus.error);
    } finally {
      _isSyncing = false;
      // Mirrored out of the service on every cycle, success or failure, so the
      // banner and the checkout button read one flag rather than each deciding
      // for themselves what a 401 meant.
      _credentialExpired = _syncService.credentialExpired;
      notifyListeners();
    }
  }

  /// Staff at the bar confirmed the current pairing mismatch is safe to
  /// trust (ADR-0035). Calls the backend to record the acknowledgement and
  /// get back the instance_id to re-pair against; a failure — the whole
  /// point of not being fail-soft here — leaves the terminal blocked rather
  /// than silently clearing state the backend never actually recorded.
  Future<void> acknowledgePairingMismatch() async {
    if (_syncCycleInFlight) return;
    _syncCycleInFlight = true;
    try {
      final instanceId = await _networkService.acknowledgePairing();
      if (instanceId == null) {
        // The backend's own /health contract is fail-soft — instance_id can
        // be null if instance_config wasn't readable at that instant. That
        // is not a confirmed acknowledgement, so stay blocked rather than
        // clearing state for a re-pair that never actually happened.
        return;
      }
      await _syncService.acknowledgePairing(instanceId);
      _pairingMismatch = false;
      _pairingMismatchTransactionCount = 0;
      notifyListeners();
    } catch (_) {
      // Stays blocked — the whole point of this not being fail-soft.
    } finally {
      _syncCycleInFlight = false;
    }
  }

  /// Start background sync timer (every N seconds)
  void startBackgroundSync({int intervalSeconds = 60}) {
    // Stop existing timer if any
    _backgroundTimer?.cancel();

    // Run sync immediately
    startSync();

    // Then schedule periodic syncs
    _backgroundTimer = Timer.periodic(Duration(seconds: intervalSeconds), (_) {
      if (!_isSyncing) {
        startSync();
      }
    });
  }

  /// Stop background sync timer
  void stopSync() {
    _backgroundTimer?.cancel();
    _backgroundTimer = null;
  }

  @override
  void notifyListeners() {
    if (_disposed) return;
    super.notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    stopSync();
    super.dispose();
  }
}
