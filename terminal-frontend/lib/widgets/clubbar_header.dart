import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:flutter/material.dart';
import 'dart:async';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/rfid_reader_health_service.dart';

class ClubBarHeader extends StatefulWidget implements PreferredSizeWidget {
  final ConnectionStatus connectionStatus;

  /// State of the RFID reader, or [RfidReaderStatus.unknown] on a terminal that
  /// does not monitor it — then no reader pill is shown at all (issue #35).
  final RfidReaderStatus readerStatus;

  final VoidCallback? onStatusTap;

  const ClubBarHeader({
    required this.connectionStatus,
    this.readerStatus = RfidReaderStatus.unknown,
    this.onStatusTap,
    super.key,
  });

  @override
  State<ClubBarHeader> createState() => _ClubBarHeaderState();

  @override
  Size get preferredSize => const Size.fromHeight(56);
}

class _ClubBarHeaderState extends State<ClubBarHeader> {
  late DateTime _currentTime;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _currentTime = DateTime.now();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) {
        setState(() {
          _currentTime = DateTime.now();
        });
      }
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  String _formatTime(DateTime time) {
    final hours = time.hour.toString().padLeft(2, '0');
    final minutes = time.minute.toString().padLeft(2, '0');
    return '$hours:$minutes';
  }

  Color _badgeColor() {
    switch (widget.connectionStatus) {
      case ConnectionStatus.online:
        return const Color(0xff22c55e);
      case ConnectionStatus.offline:
        return const Color(0xffef4444);
      case ConnectionStatus.error:
        return const Color(0xfff59e0b);
    }
  }

  String _badgeText(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    switch (widget.connectionStatus) {
      case ConnectionStatus.online:
        return l10n.statusOnline;
      case ConnectionStatus.offline:
        return l10n.statusOffline;
      case ConnectionStatus.error:
        return l10n.statusError;
    }
  }

  /// A pill in the same style as the connection badge, so the header reads as
  /// one row of terminal health rather than a badge plus an afterthought.
  Widget _pill({required String text, required Color color}) {
    return GestureDetector(
      onTap: widget.onStatusTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.15),
          border: Border.all(
            color: color.withValues(alpha: 0.3),
            width: 1,
          ),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Text(
          text,
          style: TextStyle(
            color: color.withValues(alpha: 0.7),
            fontSize: AppFontSizes.xs,
            fontWeight: FontWeight.w500,
          ),
        ),
      ),
    );
  }

  /// The reader pill, or null on a terminal that cannot tell.
  ///
  /// Deliberately shown in both directions: staff who see "Scanner OK" while a
  /// tap does nothing know to look at the card, not at the hardware.
  Widget? _readerPill(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    switch (widget.readerStatus) {
      case RfidReaderStatus.unknown:
        return null;
      case RfidReaderStatus.connected:
        return _pill(
          text: l10n.statusReaderOk,
          color: const Color(0xff22c55e),
        );
      case RfidReaderStatus.disconnected:
        return _pill(
          text: l10n.statusReaderMissing,
          color: const Color(0xffef4444),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final color = _badgeColor();
    final readerPill = _readerPill(context);

    return Container(
      height: 56,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(
        color: const Color(0xff0f1d32),
        border: Border(
          bottom: BorderSide(
            color: const Color(0xff3b82f6).withValues(alpha: 0.2),
            width: 1,
          ),
        ),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          // Left: Club Bar title
          Text(
            'Club Bar',
            style: TextStyle(
              color: Color(0xfff1f5f9),
              fontSize: AppFontSizes.xxl,
              fontWeight: FontWeight.w600,
            ),
          ),
          // Right: Reader pill, status badge and clock
          Row(
            children: [
              if (readerPill != null) ...[
                readerPill,
                const SizedBox(width: 8),
              ],
              _pill(text: _badgeText(context), color: color),
              const SizedBox(width: 12),
              Text(
                _formatTime(_currentTime),
                style: TextStyle(
                  color: Color(0xff64748b),
                  fontSize: AppFontSizes.lg,
                  fontFamily: 'JetBrains Mono',
                  fontWeight: FontWeight.w500,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
