import 'package:flutter/material.dart';
import 'dart:async';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';

class RuderbarHeader extends StatefulWidget implements PreferredSizeWidget {
  final ConnectionStatus connectionStatus;
  final VoidCallback? onStatusTap;

  const RuderbarHeader({
    required this.connectionStatus,
    this.onStatusTap,
    Key? key,
  }) : super(key: key);

  @override
  State<RuderbarHeader> createState() => _RuderbarHeaderState();

  @override
  Size get preferredSize => const Size.fromHeight(56);
}

class _RuderbarHeaderState extends State<RuderbarHeader> {
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

  @override
  Widget build(BuildContext context) {
    final color = _badgeColor();

    return Container(
      height: 56,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(
        color: const Color(0xff0f1d32),
        border: Border(
          bottom: BorderSide(
            color: const Color(0xff3b82f6).withOpacity(0.2),
            width: 1,
          ),
        ),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          // Left: Ruderbar title
          Text(
            'Ruderbar',
            style: const TextStyle(
              color: Color(0xfff1f5f9),
              fontSize: 20,
              fontWeight: FontWeight.w600,
            ),
          ),
          // Right: Status badge and clock
          Row(
            children: [
              GestureDetector(
                onTap: widget.onStatusTap,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: color.withOpacity(0.15),
                    border: Border.all(
                      color: color.withOpacity(0.3),
                      width: 1,
                    ),
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Text(
                    _badgeText(context),
                    style: TextStyle(
                      color: color.withOpacity(0.7),
                      fontSize: 12,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Text(
                _formatTime(_currentTime),
                style: const TextStyle(
                  color: Color(0xff64748b),
                  fontSize: 16,
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
