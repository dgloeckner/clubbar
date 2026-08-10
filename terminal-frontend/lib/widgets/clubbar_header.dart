import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:flutter/material.dart';
import 'dart:async';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/rfid_reader_health_service.dart';

/// Minimum edge of a header pill's touch target.
///
/// The connection pill is the app's only persistent health indicator *and* the
/// sole way into the diagnostics modal, so it obeys the same 44 px rule as the
/// rest of the app instead of being the smallest thing on screen (issue #40).
const double kHeaderPillTouchTarget = 44;

class ClubBarHeader extends StatefulWidget implements PreferredSizeWidget {
  final ConnectionStatus connectionStatus;

  /// State of the RFID reader, or [RfidReaderStatus.unknown] on a terminal that
  /// does not monitor it — then no reader pill is shown at all (issue #35).
  final RfidReaderStatus readerStatus;

  /// Whether a sync cycle is running right now.
  ///
  /// Rendered as a spinner *inside* the connection pill rather than as a state
  /// of its own: after a failure the pill must keep saying "Error" while still
  /// showing that the terminal is retrying (issue #40).
  final bool isSyncing;

  final VoidCallback? onStatusTap;

  /// The deploying club's name shown in the header (#297). Defaults to the
  /// stock "Club Bar" so existing deployments without a `displayName` in
  /// `config.json` are unaffected.
  final String displayName;

  const ClubBarHeader({
    required this.connectionStatus,
    this.readerStatus = RfidReaderStatus.unknown,
    this.isSyncing = false,
    this.onStatusTap,
    this.displayName = ConfigService.defaultDisplayName,
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
        return AppColors.semanticSuccess;
      case ConnectionStatus.offline:
        return AppColors.semanticDanger;
      case ConnectionStatus.error:
        return AppColors.semanticPending;
    }
  }

  /// Label colour for a pill tinted with [color] (#41).
  ///
  /// Green and amber clear AA at full strength on both tints this widget uses
  /// — 15 % when quiet, 25 % when alerting. Red does not: 3.9:1 quiet, and
  /// only 3.5:1 alerting, because the heavier fill is itself redder. So a red
  /// pill takes the lighter [AppColors.dangerOnTint] (5.3:1 / 4.7:1). Fill and
  /// border keep [color] either way, so the pill still reads as red.
  ///
  /// Lives here rather than at the call sites so every pill — connection and
  /// reader, quiet and alerting — is legible by construction.
  Color _pillTextColor(Color color) {
    return color == AppColors.semanticDanger
        ? AppColors.dangerOnTint
        : color;
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

  IconData _badgeIcon() {
    switch (widget.connectionStatus) {
      case ConnectionStatus.online:
        return Icons.cloud_done_outlined;
      case ConnectionStatus.offline:
        return Icons.cloud_off;
      case ConnectionStatus.error:
        return Icons.warning_amber_rounded;
    }
  }

  /// A pill in the same style as the connection badge, so the header reads as
  /// one row of terminal health rather than a badge plus an afterthought.
  ///
  /// [alert] makes the pill shout: full-opacity text, a heavier border and a
  /// stronger tint. A healthy terminal stays quiet, so anything that is *not*
  /// green reads across the room (issue #40).
  Widget _pill({
    required String text,
    required Color color,
    required IconData icon,
    required bool alert,
    bool busy = false,
    String? semanticsLabel,
  }) {
    // Full strength in both states (#41). Fading the label to 70 % over the
    // pill's own tint put it at 3.6:1 green / 2.6:1 red / 3.8:1 amber — the
    // quiet state was the unreadable one, which is most of the time. What
    // makes an alert shout is the stronger fill, the heavier border and the
    // larger bold type below, none of which cost legibility.
    final foreground = _pillTextColor(color);

    return Semantics(
      button: true,
      label: semanticsLabel,
      child: GestureDetector(
        onTap: widget.onStatusTap,
        // Opaque: the whole 44 px box takes the tap, not just the glyphs.
        behavior: HitTestBehavior.opaque,
        child: Container(
          constraints: const BoxConstraints(
            minHeight: kHeaderPillTouchTarget,
            minWidth: kHeaderPillTouchTarget,
          ),
          padding: const EdgeInsets.symmetric(horizontal: 14),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: color.withValues(alpha: alert ? 0.25 : 0.15),
            border: Border.all(
              color: color.withValues(alpha: alert ? 0.6 : 0.3),
              width: alert ? 1.5 : 1,
            ),
            borderRadius: BorderRadius.circular(kHeaderPillTouchTarget / 2),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (busy)
                SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    valueColor: AlwaysStoppedAnimation<Color>(foreground),
                  ),
                )
              else
                Icon(icon, size: 18, color: foreground),
              const SizedBox(width: 6),
              Text(
                text,
                style: TextStyle(
                  color: foreground,
                  // A step larger when something is wrong: the pill has to be
                  // read from across the room, not from the stool.
                  fontSize: alert ? AppFontSizes.lg : AppFontSizes.base,
                  fontWeight: alert ? FontWeight.w700 : FontWeight.w500,
                ),
              ),
            ],
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
          color: AppColors.semanticSuccess,
          icon: Icons.nfc,
          alert: false,
        );
      case RfidReaderStatus.disconnected:
        return _pill(
          text: l10n.statusReaderMissing,
          color: AppColors.semanticDanger,
          icon: Icons.sensors_off,
          alert: true,
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final color = _badgeColor();
    final readerPill = _readerPill(context);
    final statusText = _badgeText(context);

    return Container(
      height: 56,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(
        color: AppColors.bgSecondary,
        border: Border(
          bottom: BorderSide(
            color: AppColors.semanticPrimary.withValues(alpha: 0.2),
            width: 1,
          ),
        ),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          // Left: deploying club's name (#297)
          Flexible(
            child: Text(
              widget.displayName,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: AppColors.textPrimary,
                fontSize: AppFontSizes.xxl,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          // Right: Reader pill, status badge and clock
          Row(
            children: [
              if (readerPill != null) ...[
                readerPill,
                const SizedBox(width: 8),
              ],
              _pill(
                text: statusText,
                color: color,
                icon: _badgeIcon(),
                alert: widget.connectionStatus != ConnectionStatus.online,
                busy: widget.isSyncing,
                semanticsLabel: widget.isSyncing
                    ? '$statusText, ${l10n.statusSyncing}'
                    : statusText,
              ),
              const SizedBox(width: 12),
              Text(
                _formatTime(_currentTime),
                style: TextStyle(
                  // Was a hardcoded #64748b (3.5:1 here) — the clock is text
                  // a member reads, so it gets the secondary token (#41).
                  color: AppColors.textSecondary,
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
