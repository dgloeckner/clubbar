import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/l10n/terminal_error_messages.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/dispenser_health_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/rfid_reader_health_service.dart';
import 'package:clubbar_terminal/services/scan_log.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/error_modal.dart';

void showStatusInfoModal(BuildContext context) {
  try {
    final syncProvider = context.read<SyncProvider>();
    final configService = context.read<ConfigService>();

    // Get backend endpoint
    final backendUrl = configService.apiUrl;

    // Try to get dispenser health service and URL (may be null if dispenser disabled)
    DispenserHealth? dispenserHealth;
    String? dispenserUrl;
    try {
      final dispenserHealthService = context.read<DispenserHealthService>();
      dispenserHealth = dispenserHealthService.currentHealth;
      if (configService.dispenserEnabled) {
        dispenserUrl = configService.dispenserBaseUrl;
      }
    } catch (_) {
      // Dispenser not configured
      dispenserHealth = null;
    }

    // Get local database for pending dispenser operations
    ClubBarDatabase? database;
    try {
      database = context.read<ClubBarDatabase>();
    } catch (_) {
      // Database not available
    }

    // Get network service for fetching backend version
    NetworkService? networkService;
    try {
      networkService = context.read<NetworkService>();
    } catch (_) {
      // Network service not available
    }

    showDialog(
      context: context,
      builder: (context) => _StatusInfoDialog(
        connectionStatus: syncProvider.connectionStatus,
        lastSyncTime: syncProvider.lastSyncTime,
        lastTransactionSync: syncProvider.lastSuccessfulTransactionSync,
        retryCount: syncProvider.retryCount,
        lastError: syncProvider.lastError,
        degradedSince: syncProvider.degradedSince,
        dispenserHealth: dispenserHealth,
        backendUrl: backendUrl,
        dispenserUrl: dispenserUrl,
        database: database,
        networkService: networkService,
      ),
    );
  } catch (e, stackTrace) {
    // The exception belongs in the log; the member gets the localized key.
    logTerminalError(TerminalErrorKey.statusLoadFailed, e, stackTrace);
    showErrorModal(
      context,
      TerminalErrorKey.statusLoadFailed.message(AppLocalizations.of(context)!),
    );
  }
}

enum StatusModalTab { overview, dispenser }

class _StatusInfoDialog extends StatefulWidget {
  final ConnectionStatus connectionStatus;
  final DateTime? lastSyncTime;
  final DateTime? lastTransactionSync;
  final int retryCount;
  final TerminalError? lastError;

  /// Start of the current outage, or null while healthy — see
  /// [SyncProvider.degradedSince].
  final DateTime? degradedSince;

  final DispenserHealth? dispenserHealth;
  final String? backendUrl;
  final String? dispenserUrl;
  final ClubBarDatabase? database;
  final NetworkService? networkService;

  const _StatusInfoDialog({
    required this.connectionStatus,
    required this.lastSyncTime,
    required this.lastTransactionSync,
    required this.retryCount,
    required this.lastError,
    this.degradedSince,
    this.backendUrl,
    this.dispenserHealth,
    this.dispenserUrl,
    this.database,
    this.networkService,
  });

  @override
  State<_StatusInfoDialog> createState() => _StatusInfoDialogState();
}

class _StatusInfoDialogState extends State<_StatusInfoDialog> {
  /// How many scan-log entries the section shows: enough to cover a member
  /// trying again before fetching staff, short enough to stay readable on a 7"
  /// panel.
  static const int _recentScansShown = 6;

  StatusModalTab _currentTab = StatusModalTab.overview;
  List<DispenserOperation> _pendingOps = [];
  StreamSubscription<List<DispenserOperation>>? _opsSub;
  DispenserHealthService? _healthService;
  RfidReaderHealthService? _readerHealthService;
  String? _backendVersion;

  /// Tri-state for the version badge: pending → known → unavailable. Without
  /// it a failed fetch is indistinguishable from a slow one, and the badge
  /// simply never appears (issue #40).
  bool _backendVersionPending = true;

  bool _showTechnicalDetails = false;

  @override
  void initState() {
    super.initState();
    _fetchBackendVersion();
    if (widget.database != null) {
      _opsSub = widget.database!
          .select(widget.database!.dispenserOperations)
          .watch()
          .listen((ops) {
        if (mounted) setState(() => _pendingOps = ops);
      });
    }
    // Subscribe directly to DispenserHealthService so updates propagate
    // reliably inside the dialog overlay (context.watch is unreliable there).
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      try {
        _healthService = context.read<DispenserHealthService>();
        _healthService!.addListener(_onHealthChanged);
        _healthService!.checkNow();
      } catch (_) {
        // Dispenser not configured — leave _healthService null.
      }
      try {
        _readerHealthService = context.read<RfidReaderHealthService>();
        _readerHealthService!.addListener(_onHealthChanged);
        // Staff open this modal right after pushing the cable back in — check
        // now rather than making them wait out the poll interval.
        _readerHealthService!.checkNow();
      } catch (_) {
        // Reader monitoring not configured — leave _readerHealthService null.
      }
    });
  }

  void _onHealthChanged() {
    if (mounted) setState(() {});
  }

  Future<void> _fetchBackendVersion() async {
    final version = await widget.networkService?.fetchBackendVersion();
    if (!mounted) return;
    setState(() {
      _backendVersion = version;
      _backendVersionPending = false;
    });
  }

  /// What the version badge says: still fetching, the version, or an honest
  /// "unknown" — never nothing at all.
  String _backendVersionLabel(AppLocalizations l10n) {
    if (_backendVersionPending) return l10n.loading;
    return _backendVersion ?? l10n.backendVersionUnknown;
  }

  @override
  void dispose() {
    _healthService?.removeListener(_onHealthChanged);
    _readerHealthService?.removeListener(_onHealthChanged);
    _opsSub?.cancel();
    super.dispose();
  }

  /// Returns live health from the subscribed service, or the snapshot passed
  /// at dialog construction time when no service is available.
  DispenserHealth? _effectiveHealth() {
    return _healthService?.currentHealth ?? widget.dispenserHealth;
  }

  String _translateMachineState(String? state, AppLocalizations l10n) {
    switch (state) {
      case 'idle': return l10n.dispenserStateIdle;
      case 'dispensing': return l10n.dispenserStateDispensing;
      case 'done': return l10n.dispenserStateDone;
      case 'error': return l10n.dispenserStateError;
      case 'not_found': return l10n.dispenserStateNotFound;
      case 'offline': return l10n.dispenserStateOffline;
      default: return l10n.dispenserStateUnknown;
    }
  }

  bool _isDispenserOffline(DispenserHealth? health) {
    return health != null &&
           (health.dispenser == 'offline' || health.status == 'error');
  }

  IconData _statusIcon(DispenserHealth? health) {
    if (widget.connectionStatus == ConnectionStatus.online && _isDispenserOffline(health)) {
      return Icons.warning_amber_rounded;
    }
    switch (widget.connectionStatus) {
      case ConnectionStatus.online:
        return Icons.check_circle_outline;
      case ConnectionStatus.offline:
        return Icons.cloud_off;
      case ConnectionStatus.error:
        return Icons.warning_amber_rounded;
    }
  }

  Color _statusColor(DispenserHealth? health) {
    if (widget.connectionStatus == ConnectionStatus.online && _isDispenserOffline(health)) {
      return AppColors.semanticPending;
    }
    switch (widget.connectionStatus) {
      case ConnectionStatus.online:
        return AppColors.semanticSuccess;
      case ConnectionStatus.offline:
        return AppColors.semanticDanger;
      case ConnectionStatus.error:
        return AppColors.semanticPending;
    }
  }

  String _statusTitle(DispenserHealth? health, AppLocalizations l10n) {
    if (widget.connectionStatus == ConnectionStatus.online && _isDispenserOffline(health)) {
      return l10n.statusWarning;
    }
    switch (widget.connectionStatus) {
      case ConnectionStatus.online:
        return l10n.statusOnline;
      case ConnectionStatus.offline:
        return l10n.statusOffline;
      case ConnectionStatus.error:
        return l10n.statusError;
    }
  }

  Widget _buildTabButton({
    required String label,
    required bool isActive,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        decoration: BoxDecoration(
          color: isActive ? AppColors.semanticSuccessLight : Colors.transparent,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(
            color: isActive ? AppColors.semanticSuccessLight : AppColors.borderMuted,
            width: 1,
          ),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontSize: AppFontSizes.sm,
            fontWeight: isActive ? FontWeight.w600 : FontWeight.normal,
            color: isActive ? Colors.white : AppColors.textSecondary,
          ),
        ),
      ),
    );
  }

  Widget _buildTabBar(AppLocalizations l10n) {
    return Row(
      children: [
        _buildTabButton(
          label: l10n.tabOverview,
          isActive: _currentTab == StatusModalTab.overview,
          onTap: () => setState(() => _currentTab = StatusModalTab.overview),
        ),
        const SizedBox(width: 8),
        _buildTabButton(
          label: l10n.tabDispenserStatus,
          isActive: _currentTab == StatusModalTab.dispenser,
          onTap: () => setState(() => _currentTab = StatusModalTab.dispenser),
        ),
      ],
    );
  }

  String _formatTimestamp(DateTime? dt, AppLocalizations l10n) {
    if (dt == null) return l10n.never;
    final hours = dt.hour.toString().padLeft(2, '0');
    final minutes = dt.minute.toString().padLeft(2, '0');
    final seconds = dt.second.toString().padLeft(2, '0');
    return '${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')} $hours:$minutes:$seconds';
  }

  String _formatClock(DateTime dt) {
    return '${dt.hour.toString().padLeft(2, '0')}:'
        '${dt.minute.toString().padLeft(2, '0')}';
  }

  /// Truncate an identifier before it reaches the screen.
  ///
  /// This modal is reachable by any patron who taps the header pill, so it
  /// must not print member UUIDs in full (issue #40). The kept prefix is long
  /// enough for staff to quote when asking support to look a row up, and short
  /// enough not to be the identifier itself.
  String _maskId(String id, {int keep = 8}) {
    return id.length <= keep ? id : '${id.substring(0, keep)}…';
  }

  String _formatUptime(int seconds) {
    final h = seconds ~/ 3600;
    final m = (seconds % 3600) ~/ 60;
    final s = seconds % 60;
    return '${h.toString().padLeft(2, '0')}:${m.toString().padLeft(2, '0')}:${s.toString().padLeft(2, '0')}';
  }

  String _formatErrorTimestamp(int ms) {
    final h = ms ~/ 3600000;
    final m = (ms % 3600000) ~/ 60000;
    final s = (ms % 60000) ~/ 1000;
    return 'T+${h}h ${m}m ${s}s';
  }

  /// HTTP code plus its family (e.g. "401 (4xx)") — never the exception
  /// message. The code alone is enough for support to look the failure up;
  /// the raw text stays in `error.log` (issue #40 masking the same way).
  String _httpStatusLabel(int statusCode) {
    final family = '${statusCode ~/ 100}xx';
    return '$statusCode ($family)';
  }

  int _rssiToPercent(int rssi) {
    return (2 * (rssi + 100)).clamp(0, 100);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final health = _effectiveHealth();

    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: ConstrainedBox(
        constraints: const BoxConstraints(
          maxWidth: 910,
          maxHeight: 600,
        ),
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Header with icon and title
              Row(
                children: [
                  Icon(
                    _statusIcon(health),
                    color: _statusColor(health),
                    size: 32,
                  ),
                  const SizedBox(width: 12),
                  Text(
                    _statusTitle(health, l10n),
                    style: TextStyle(
                      fontSize: AppFontSizes.xxxl,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  const Spacer(),
                  IconButton(
                    icon: const Icon(Icons.close),
                    onPressed: () => Navigator.of(context).pop(),
                    iconSize: 20,
                  ),
                ],
              ),
              const SizedBox(height: 16),

              // Tab bar
              _buildTabBar(l10n),
              const SizedBox(height: 20),

              // Content area with scrolling
              Flexible(
                child: _currentTab == StatusModalTab.overview
                    ? _buildOverviewTab(l10n, health)
                    : _buildDispenserTab(l10n, health),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildOverviewTab(AppLocalizations l10n, DispenserHealth? health) {
    return SingleChildScrollView(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Sync status section
          _buildSection(
            title: l10n.syncStatus,
            children: [
              _infoRow(l10n.lastSync, _formatTimestamp(widget.lastSyncTime, l10n)),
              const SizedBox(height: 8),
              _infoRow(l10n.lastTransactionSync, _formatTimestamp(widget.lastTransactionSync, l10n)),
              if (widget.retryCount > 0) ...[
                const SizedBox(height: 8),
                _infoRow(l10n.retryCount, '${widget.retryCount}'),
              ],
            ],
          ),
          const SizedBox(height: 20),

          // Card reader section — only on a terminal that monitors it (#35)
          if (_readerHealthService != null &&
              _readerHealthService!.status != RfidReaderStatus.unknown) ...[
            _buildReaderSection(l10n, _readerHealthService!),
            const SizedBox(height: 20),
          ],

          // What the reader has actually been doing (#370)
          _buildRecentScansSection(l10n),
          const SizedBox(height: 20),

          // Dispenser section - simplified (if available)
          if (health != null) ...[
            _buildSection(
              title: l10n.dispenser,
              children: [
                _infoRow('Status', _dispenserStatusText(health, l10n)),
                if (health.totalDispenses > 0 || (health.requestedTokens ?? 0) > 0) ...[
                  const SizedBox(height: 8),
                  _infoRow(
                    l10n.successRate,
                    '${health.successRate.toStringAsFixed(1)}%',
                  ),
                ],
              ],
            ),
            const SizedBox(height: 20),
          ],

          // Endpoints section
          _buildSection(
            title: l10n.endpoints,
            children: [
              if (widget.backendUrl != null) ...[
                _urlRow(
                  l10n.backendEndpoint,
                  widget.backendUrl!,
                  version: _backendVersionLabel(l10n),
                ),
                const SizedBox(height: 12),
              ],
              if (widget.dispenserUrl != null) ...[
                _urlRow(l10n.dispenserEndpoint, widget.dispenserUrl!),
              ],
            ],
          ),

          // Error section (if any)
          if (widget.lastError != null && widget.connectionStatus != ConnectionStatus.online) ...[
            const SizedBox(height: 20),
            _buildErrorSection(l10n),
          ],
        ],
      ),
    );
  }

  /// What is wrong, in plain language and at a readable size, with the
  /// engineering detail collapsed underneath (issue #40).
  ///
  /// The patron who taps the pill gets a sentence they can act on; the
  /// volunteer on the phone to support expands one row for the error code.
  Widget _buildErrorSection(AppLocalizations l10n) {
    return _buildSection(
      title: l10n.errorDetails,
      titleColor: AppColors.semanticDanger,
      children: [
        Text(
          widget.lastError!.message(l10n),
          style: TextStyle(
            fontSize: AppFontSizes.base,
            color: AppColors.textBright,
          ),
        ),
        if (widget.degradedSince != null) ...[
          const SizedBox(height: 6),
          Text(
            l10n.statusDegradedSince(_formatClock(widget.degradedSince!)),
            style: TextStyle(
              fontSize: AppFontSizes.sm,
              color: AppColors.textSecondary,
            ),
          ),
        ],
        _buildTechnicalDetails(l10n),
      ],
    );
  }

  Widget _buildTechnicalDetails(AppLocalizations l10n) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        GestureDetector(
          behavior: HitTestBehavior.opaque,
          onTap: () => setState(
            () => _showTechnicalDetails = !_showTechnicalDetails,
          ),
          child: Padding(
            // Vertical padding, not a fixed height: this is a 44 px touch
            // target for the same fingers that had to hit the pill.
            padding: const EdgeInsets.symmetric(vertical: 12),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  _showTechnicalDetails
                      ? Icons.expand_less
                      : Icons.expand_more,
                  size: 20,
                  color: AppColors.textSecondary,
                ),
                const SizedBox(width: 4),
                Text(
                  l10n.technicalDetails,
                  style: TextStyle(
                    fontSize: AppFontSizes.sm,
                    color: AppColors.textSecondary,
                  ),
                ),
              ],
            ),
          ),
        ),
        if (_showTechnicalDetails) ...[
          _infoRow(l10n.technicalErrorCode, widget.lastError!.key.name),
          const SizedBox(height: 8),
          if (widget.lastError!.httpStatusCode != null) ...[
            _infoRow(
              l10n.technicalHttpStatus,
              _httpStatusLabel(widget.lastError!.httpStatusCode!),
            ),
            const SizedBox(height: 8),
          ],
          _infoRow(l10n.retryCount, '${widget.retryCount}'),
          if (widget.backendUrl != null) ...[
            const SizedBox(height: 8),
            _infoRow(l10n.backendEndpoint, widget.backendUrl!),
          ],
        ],
      ],
    );
  }

  /// The reader's own row in the overview: what staff need before they start
  /// looking for a cable — is it seen, and if not, when was it last seen.
  Widget _buildReaderSection(
    AppLocalizations l10n,
    RfidReaderHealthService reader,
  ) {
    final connected = reader.status == RfidReaderStatus.connected;

    return _buildSection(
      title: l10n.cardReader,
      titleColor: connected ? null : AppColors.semanticDanger,
      children: [
        _infoRow(
          'Status',
          connected ? l10n.cardReaderConnected : l10n.cardReaderDisconnected,
        ),
        if (!connected) ...[
          const SizedBox(height: 8),
          _infoRow(
            l10n.cardReaderLastSeen,
            _formatTimestamp(reader.lastSeenAt, l10n),
          ),
        ],
      ],
    );
  }

  /// The last few card taps and what the terminal made of each (issue #370).
  ///
  /// "The chip was not recognised" is all a member can report, and the failure
  /// modes behind it are indistinguishable from the bar: a UID that never
  /// finished arriving, a key the reader typed that carries no character, a
  /// card the cache does not know. Staff open this, read the lines out, and the
  /// difference is obvious. The lines are deliberately technical and
  /// untranslated — they exist to be reported verbatim, and the kind is the
  /// term to search the source for.
  Widget _buildRecentScansSection(AppLocalizations l10n) {
    return AnimatedBuilder(
      animation: ScanLog.instance,
      builder: (context, _) {
        final events = ScanLog.instance.events.take(_recentScansShown).toList();

        return _buildSection(
          title: l10n.recentScans,
          children: [
            if (events.isEmpty)
              Text(
                l10n.recentScansEmpty,
                style: TextStyle(
                  fontSize: AppFontSizes.sm,
                  color: AppColors.textSecondary,
                ),
              )
            else
              for (final event in events)
                Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Text(
                    '${_formatClockTime(event.at)}  ${event.summary}',
                    style: TextStyle(
                      fontSize: AppFontSizes.xs,
                      fontFamily: 'monospace',
                      color: event.kind.isSilentLoss
                          ? AppColors.semanticDanger
                          : AppColors.textSecondary,
                    ),
                  ),
                ),
          ],
        );
      },
    );
  }

  static String _two(int value) => value.toString().padLeft(2, '0');

  static String _formatClockTime(DateTime at) =>
      '${_two(at.hour)}:${_two(at.minute)}:${_two(at.second)}';

  Widget _buildDispenserTab(AppLocalizations l10n, DispenserHealth? health) {
    if (health == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(
            l10n.dispenserNotAvailable,
            style: TextStyle(fontSize: AppFontSizes.base, color: AppColors.textSecondary),
          ),
        ),
      );
    }

    return SingleChildScrollView(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _buildUptimeSection(l10n, health),
          const SizedBox(height: 20),
          _buildMachineStateSection(l10n, health),
          const SizedBox(height: 20),
          _buildNetworkSection(l10n, health),
          const SizedBox(height: 20),
          _buildErrorHistorySection(l10n, health),
          if (_pendingOps.isNotEmpty) ...[
            const SizedBox(height: 20),
            _buildPendingOperationsSection(l10n),
          ],
        ],
      ),
    );
  }

  Widget _buildMetricCard({
    required String label,
    required int value,
    Color? accentColor,
  }) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 18, horizontal: 8),
      decoration: BoxDecoration(
        color: const Color(0x05FFFFFF), // rgba(255,255,255,0.02)
        border: Border.all(color: const Color(0x0DFFFFFF)), // rgba(255,255,255,0.05)
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        children: [
          Text(
            '$value',
            style: TextStyle(
              fontSize: AppFontSizes.xxxl,
              fontWeight: FontWeight.w700,
              color: accentColor ?? AppColors.textBright,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            label.toUpperCase(),
            style: TextStyle(
              fontSize: AppFontSizes.xs,
              color: AppColors.borderMuted,
              letterSpacing: 0.1,
            ),
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }

  Widget _buildWiFiSignalBar(int signalPercent) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [25, 50, 75, 100].asMap().entries.map((entry) {
        final threshold = entry.value;
        final index = entry.key;
        final isActive = signalPercent >= threshold;

        return Container(
          margin: const EdgeInsets.only(right: 2),
          width: 3,
          height: 4.0 + (index * 4),
          decoration: BoxDecoration(
            color: isActive
                ? const Color(0xCC34D399) // rgba(52,211,153,0.8)
                : const Color(0x14FFFFFF), // rgba(255,255,255,0.08)
            borderRadius: BorderRadius.circular(1),
          ),
        );
      }).toList(),
    );
  }

  Widget _buildStatusBadge(String status, AppLocalizations l10n) {
    final isIdle = status == 'idle';
    final isError = status == 'offline' || status == 'error';
    final Color bg, border, text;
    if (isError) {
      bg     = const Color(0x19ef4444);
      border = const Color(0x33ef4444);
      text   = AppColors.semanticDanger;
    } else if (isIdle) {
      bg     = const Color(0x196366f1);
      border = const Color(0x336366f1);
      text   = AppColors.semanticIdle;
    } else {
      bg     = const Color(0x1934d399);
      border = const Color(0x3334d399);
      text   = AppColors.semanticSuccessLight;
    }
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      decoration: BoxDecoration(
        color: bg,
        border: Border.all(color: border),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        _translateMachineState(status, l10n).toUpperCase(),
        style: TextStyle(
          fontFamily: 'monospace',
          fontSize: AppFontSizes.xs,
          fontWeight: FontWeight.w600,
          color: text,
          letterSpacing: 0.08,
        ),
      ),
    );
  }

  Widget _buildUptimeSection(AppLocalizations l10n, DispenserHealth health) {
    // Note: uptime field may not be available yet from backend
    final uptimeSeconds = health.uptime ?? 0;
    final uptimeFormatted = _formatUptime(uptimeSeconds);
    final firmware = health.firmware ?? 'N/A';

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12),
      decoration: BoxDecoration(
        color: const Color(0x08FFFFFF),
        border: Border.all(color: const Color(0x0AFFFFFF)),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Row(
            children: [
              Text(
                l10n.dispenserUptime.toUpperCase(),
                style: TextStyle(
                  fontSize: AppFontSizes.xs,
                  fontWeight: FontWeight.w600,
                  color: AppColors.borderMuted,
                  letterSpacing: 0.12,
                ),
              ),
              const SizedBox(width: 8),
              Text(
                uptimeFormatted,
                style: TextStyle(
                  fontFamily: 'monospace',
                  fontSize: AppFontSizes.xl,
                  fontWeight: FontWeight.w600,
                  color: AppColors.semanticSuccessLight,
                  letterSpacing: 0.05,
                ),
              ),
            ],
          ),
          Text(
            'fw $firmware',
            style: TextStyle(
              fontFamily: 'monospace',
              fontSize: AppFontSizes.xs,
              color: AppColors.borderLight,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildMachineStateSection(AppLocalizations l10n, DispenserHealth health) {
    final failures = health.failures ?? 0;

    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            Color(0xCC0f172a), // rgba(15,23,42,0.8)
            Color(0x660f172a), // rgba(15,23,42,0.4)
          ],
        ),
        border: Border.all(color: const Color(0x0FFFFFFF)),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                l10n.dispenserMachineState.toUpperCase(),
                style: TextStyle(
                  fontSize: AppFontSizes.xs,
                  fontWeight: FontWeight.w600,
                  color: AppColors.borderMuted,
                  letterSpacing: 0.12,
                ),
              ),
              _buildStatusBadge(health.dispenser, l10n),
            ],
          ),
          const SizedBox(height: 20),
          Row(
            children: [
              Expanded(
                child: _buildMetricCard(
                  label: l10n.dispenserDispensed,
                  value: health.dispensedTokens ?? health.totalDispenses,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _buildMetricCard(
                  label: l10n.dispenserSuccess,
                  value: health.requestedTokens ?? health.successful,
                  accentColor: AppColors.semanticSuccessLight,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _buildMetricCard(
                  label: l10n.dispenserJams,
                  value: health.jams,
                  accentColor: AppColors.semanticPending,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: _buildMetricCard(
                  label: l10n.dispenserFailures,
                  value: failures,
                  accentColor: AppColors.semanticDanger,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildNetworkSection(AppLocalizations l10n, DispenserHealth health) {
    // Note: wifi field may not be available yet from backend
    if (health.wifi == null) {
      return Container(
        padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 20),
        decoration: BoxDecoration(
          color: const Color(0x990f172a),
          border: Border.all(color: const Color(0x0FFFFFFF)),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              l10n.dispenserNetwork.toUpperCase(),
              style: TextStyle(
                fontSize: AppFontSizes.xs,
                fontWeight: FontWeight.w600,
                color: AppColors.borderMuted,
                letterSpacing: 0.12,
              ),
            ),
            const SizedBox(height: 16),
            Text(
              l10n.dispenserNetworkNotAvailable,
              style: TextStyle(
                fontFamily: 'monospace',
                fontSize: AppFontSizes.xs,
                color: AppColors.borderMuted,
              ),
            ),
          ],
        ),
      );
    }

    final wifi = health.wifi!;
    final wifiPercent = _rssiToPercent(wifi.rssi);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 20),
      decoration: BoxDecoration(
        color: const Color(0x990f172a),
        border: Border.all(color: const Color(0x0FFFFFFF)),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            l10n.dispenserNetwork.toUpperCase(),
            style: TextStyle(
              fontSize: AppFontSizes.xs,
              fontWeight: FontWeight.w600,
              color: AppColors.borderMuted,
              letterSpacing: 0.12,
            ),
          ),
          const SizedBox(height: 16),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  _buildWiFiSignalBar(wifiPercent),
                  const SizedBox(width: 12),
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        wifi.ssid,
                        style: TextStyle(
                          fontFamily: 'monospace',
                          fontSize: AppFontSizes.base,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textBright,
                        ),
                      ),
                      Text(
                        '${wifi.rssi} dBm · $wifiPercent%',
                        style: TextStyle(
                          fontFamily: 'monospace',
                          fontSize: AppFontSizes.xs,
                          color: AppColors.borderMuted,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  color: const Color(0x33000000),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  wifi.ip,
                  style: TextStyle(
                    fontFamily: 'monospace',
                    fontSize: AppFontSizes.xs,
                    color: AppColors.textSecondary,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildErrorHistorySection(AppLocalizations l10n, DispenserHealth health) {
    // Note: errorHistory field may not be available yet from backend
    final errors = health.errorHistory ?? [];

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 20),
      decoration: BoxDecoration(
        color: const Color(0x990f172a),
        border: Border.all(color: const Color(0x0FFFFFFF)),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            l10n.dispenserErrorHistory.toUpperCase(),
            style: TextStyle(
              fontSize: AppFontSizes.xs,
              fontWeight: FontWeight.w600,
              color: AppColors.borderMuted,
              letterSpacing: 0.12,
            ),
          ),
          const SizedBox(height: 16),
          errors.isEmpty
              ? Text(
                  l10n.dispenserNoErrors,
                  style: TextStyle(
                    fontFamily: 'monospace',
                    fontSize: AppFontSizes.xs,
                    color: AppColors.borderLight,
                  ),
                  textAlign: TextAlign.center,
                )
              : Column(
                  children: errors.map((error) => _buildErrorItem(l10n, error)).toList(),
                ),
        ],
      ),
    );
  }

  Widget _buildErrorItem(AppLocalizations l10n, DispenserError error) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: error.cleared ? const Color(0x05FFFFFF) : const Color(0x0Fef4444),
        border: Border.all(
          color: error.cleared ? const Color(0x0AFFFFFF) : const Color(0x26ef4444),
        ),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: [
          Container(
            width: 32,
            height: 32,
            decoration: BoxDecoration(
              color: error.cleared ? const Color(0x1434d399) : const Color(0x19ef4444),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Center(
              child: Text(
                error.cleared ? '✓' : '⚠',
                style: TextStyle(
                  fontSize: AppFontSizes.base,
                  color: error.cleared ? AppColors.semanticSuccessLight : AppColors.semanticDanger,
                ),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'E${error.code} · ${error.type}',
                  style: TextStyle(
                    fontFamily: 'monospace',
                    fontSize: AppFontSizes.xs,
                    fontWeight: FontWeight.w600,
                    color: error.cleared ? AppColors.textSecondary : AppColors.semanticDanger,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  '${_formatErrorTimestamp(error.timestamp)}${error.cleared ? " · ${l10n.dispenserErrorCleared}" : ""}',
                  style: TextStyle(
                    fontFamily: 'monospace',
                    fontSize: AppFontSizes.xs,
                    color: AppColors.borderMuted,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildPendingOperationsSection(AppLocalizations l10n) {
    final notFound = _pendingOps.where((op) => op.lastKnownState == 'not_found').toList();
    final active = _pendingOps.where((op) => op.lastKnownState != 'not_found').toList();

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 20),
      decoration: BoxDecoration(
        color: notFound.isNotEmpty ? const Color(0x0Fef4444) : const Color(0x990f172a),
        border: Border.all(
          color: notFound.isNotEmpty ? const Color(0x33ef4444) : const Color(0x0FFFFFFF),
        ),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              if (notFound.isNotEmpty)
                const Padding(
                  padding: EdgeInsets.only(right: 6),
                  child: Icon(Icons.warning_amber_rounded, color: AppColors.semanticDanger, size: 14),
                ),
              Text(
                l10n.dispenserLocalTransactionLog.toUpperCase(),
                style: TextStyle(
                  fontSize: AppFontSizes.xs,
                  fontWeight: FontWeight.w600,
                  color: notFound.isNotEmpty ? AppColors.semanticDanger : AppColors.borderMuted,
                  letterSpacing: 0.12,
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          if (notFound.isNotEmpty) ...[
            Text(
              l10n.dispenserManualReconciliationRequired.toUpperCase(),
              style: TextStyle(
                fontSize: AppFontSizes.xs,
                fontWeight: FontWeight.w700,
                color: AppColors.semanticDanger,
                letterSpacing: 0.08,
              ),
            ),
            const SizedBox(height: 8),
            ...notFound.map((op) => _buildPendingOpRow(op, l10n: l10n, isNotFound: true)),
          ],
          if (active.isNotEmpty) ...[
            if (notFound.isNotEmpty) const SizedBox(height: 12),
            Text(
              l10n.dispenserInProgress.toUpperCase(),
              style: TextStyle(
                fontSize: AppFontSizes.xs,
                fontWeight: FontWeight.w600,
                color: AppColors.borderMuted,
                letterSpacing: 0.08,
              ),
            ),
            const SizedBox(height: 8),
            ...active.map((op) => _buildPendingOpRow(op, l10n: l10n, isNotFound: false)),
          ],
        ],
      ),
    );
  }

  Widget _buildPendingOpRow(DispenserOperation op, {required AppLocalizations l10n, required bool isNotFound}) {
    final stateColor = isNotFound ? AppColors.semanticDanger : AppColors.semanticPending;
    final stateLabel = _translateMachineState(op.lastKnownState, l10n).toUpperCase();
    final shortId = _maskId(op.dispenserTxId);

    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: const Color(0x08FFFFFF),
        border: Border.all(color: const Color(0x0AFFFFFF)),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  shortId,
                  style: TextStyle(
                    fontFamily: 'monospace',
                    fontSize: AppFontSizes.xs,
                    color: AppColors.textSecondary,
                  ),
                ),
                Text(
                  l10n.pendingOperationDetails(
                    _maskId(op.memberId),
                    '${op.requestedQty}',
                    op.createdAt.substring(0, 10),
                  ),
                  style: TextStyle(
                    fontFamily: 'monospace',
                    fontSize: AppFontSizes.xs,
                    color: AppColors.borderMuted,
                  ),
                ),
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(
              color: stateColor.withValues(alpha: 0.1),
              border: Border.all(color: stateColor.withValues(alpha: 0.3)),
              borderRadius: BorderRadius.circular(4),
            ),
            child: Text(
              stateLabel,
              style: TextStyle(
                fontFamily: 'monospace',
                fontSize: AppFontSizes.xs,
                fontWeight: FontWeight.w600,
                color: stateColor,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSection({
    required String title,
    required List<Widget> children,
    Color? titleColor,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: TextStyle(
            fontSize: AppFontSizes.base,
            fontWeight: FontWeight.w600,
            color: titleColor ?? AppColors.textDisabled,
            letterSpacing: 0.5,
          ),
        ),
        const SizedBox(height: 12),
        ...children,
      ],
    );
  }

  String _dispenserStatusText(DispenserHealth health, AppLocalizations l10n) {
    final isOnline = health.dispenser != 'offline' && health.status != 'error';
    if (isOnline) {
      return l10n.dispenserStatusOnline(_translateMachineState(health.dispenser, l10n));
    } else {
      return l10n.statusOffline;
    }
  }

  Widget _infoRow(String label, String value) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: TextStyle(fontSize: AppFontSizes.sm)),
        Text(value, style: TextStyle(fontSize: AppFontSizes.sm, fontWeight: FontWeight.w500)),
      ],
    );
  }

  Widget _urlRow(String label, String url, {String? version}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Text(
              label,
              style: TextStyle(fontSize: AppFontSizes.sm, fontWeight: FontWeight.w600),
            ),
            if (version != null) ...[
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(
                  color: AppColors.borderLight,
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(
                  version,
                  style: TextStyle(
                    fontSize: AppFontSizes.xs,
                    fontFamily: 'monospace',
                    color: AppColors.textSecondary,
                  ),
                ),
              ),
            ],
          ],
        ),
        const SizedBox(height: 4),
        Text(
          url,
          style: TextStyle(fontSize: AppFontSizes.xs, fontFamily: 'monospace'),
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
        ),
      ],
    );
  }
}
