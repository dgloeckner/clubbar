import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';

void showStatusInfoModal(BuildContext context) {
  final syncProvider = context.read<SyncProvider>();

  showDialog(
    context: context,
    builder: (context) => _StatusInfoDialog(
      connectionStatus: syncProvider.connectionStatus,
      lastSyncTime: syncProvider.lastSyncTime,
      lastTransactionSync: syncProvider.lastSuccessfulTransactionSync,
      retryCount: syncProvider.retryCount,
      lastError: syncProvider.lastError,
    ),
  );
}

class _StatusInfoDialog extends StatelessWidget {
  final ConnectionStatus connectionStatus;
  final DateTime? lastSyncTime;
  final DateTime? lastTransactionSync;
  final int retryCount;
  final String? lastError;

  const _StatusInfoDialog({
    required this.connectionStatus,
    required this.lastSyncTime,
    required this.lastTransactionSync,
    required this.retryCount,
    required this.lastError,
  });

  IconData _statusIcon() {
    switch (connectionStatus) {
      case ConnectionStatus.online:
        return Icons.check_circle_outline;
      case ConnectionStatus.offline:
        return Icons.cloud_off;
      case ConnectionStatus.error:
        return Icons.warning_amber_rounded;
    }
  }

  Color _statusColor() {
    switch (connectionStatus) {
      case ConnectionStatus.online:
        return const Color(0xff22c55e);
      case ConnectionStatus.offline:
        return const Color(0xffef4444);
      case ConnectionStatus.error:
        return const Color(0xfff59e0b);
    }
  }

  String _statusTitle(AppLocalizations l10n) {
    switch (connectionStatus) {
      case ConnectionStatus.online:
        return l10n.statusOnline;
      case ConnectionStatus.offline:
        return l10n.statusOffline;
      case ConnectionStatus.error:
        return l10n.statusError;
    }
  }

  String _formatTimestamp(DateTime? dt, AppLocalizations l10n) {
    if (dt == null) return l10n.never;
    final hours = dt.hour.toString().padLeft(2, '0');
    final minutes = dt.minute.toString().padLeft(2, '0');
    final seconds = dt.second.toString().padLeft(2, '0');
    return '${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')} $hours:$minutes:$seconds';
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    return AlertDialog(
      icon: Icon(
        _statusIcon(),
        color: _statusColor(),
        size: 32,
      ),
      title: Text(_statusTitle(l10n)),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _infoRow(l10n.lastSync, _formatTimestamp(lastSyncTime, l10n)),
          const SizedBox(height: 8),
          _infoRow(l10n.lastTransactionSync, _formatTimestamp(lastTransactionSync, l10n)),
          if (retryCount > 0) ...[
            const SizedBox(height: 8),
            _infoRow(l10n.retryCount, '$retryCount'),
          ],
          if (lastError != null &&
              connectionStatus != ConnectionStatus.online) ...[
            const SizedBox(height: 12),
            const Divider(),
            const SizedBox(height: 8),
            Text(
              l10n.errorDetails,
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: Theme.of(context).colorScheme.onSurface,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              lastError!,
              style: TextStyle(
                fontSize: 12,
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
            ),
          ],
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(l10n.dismiss),
        ),
      ],
    );
  }

  Widget _infoRow(String label, String value) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: const TextStyle(fontSize: 13)),
        Text(value, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500)),
      ],
    );
  }
}
