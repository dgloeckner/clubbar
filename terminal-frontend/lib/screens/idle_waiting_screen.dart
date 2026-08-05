import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/l10n/terminal_error_messages.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/config/app_config.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/rfid_detector_button.dart';

class IdleWaitingScreen extends StatefulWidget {
  const IdleWaitingScreen({super.key});

  @override
  State<IdleWaitingScreen> createState() => _IdleWaitingScreenState();
}

class _IdleWaitingScreenState extends State<IdleWaitingScreen> {
  static const _errorDisplayDuration = Duration(seconds: 5);
  static const _errorFadeDuration = Duration(milliseconds: 500);

  Timer? _errorDismissTimer;
  Timer? _errorClearTimer;
  /// The occurrence already shown, so a repeat of the same key still displays.
  TerminalError? _shownError;
  double _errorOpacity = 1.0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      // Scan capture itself belongs to the app shell (see [ScanCapture]) — the
      // reader is a property of the terminal, not of this screen (issue #26).
      context.read<SyncProvider>().startBackgroundSync();
    });
  }

  @override
  void dispose() {
    _errorDismissTimer?.cancel();
    _errorClearTimer?.cancel();
    super.dispose();
  }

  /// Show [error] at full opacity for [_errorDisplayDuration], then fade it out.
  ///
  /// Every failed scan is a distinct occurrence (see [TerminalError.sequence]),
  /// so this restarts from scratch each time — including for a repeat scan of
  /// the same rejected card. Both timers of the previous occurrence are
  /// cancelled first: a stale clear firing mid-display would wipe the new
  /// banner after a few hundred milliseconds.
  void _startErrorDismissTimer(TerminalError error) {
    _errorDismissTimer?.cancel();
    _errorClearTimer?.cancel();

    setState(() {
      _errorOpacity = 1.0;
      _shownError = error;
    });

    _errorDismissTimer = Timer(_errorDisplayDuration, () {
      if (!mounted) return;
      setState(() {
        _errorOpacity = 0.0;
      });

      _errorClearTimer = Timer(_errorFadeDuration, () {
        if (!mounted) return;
        setState(() {
          _shownError = null;
        });
        context.read<RfidProvider>().clearDetection();
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    return Container(
      decoration: const BoxDecoration(
        gradient: RadialGradient(
          center: Alignment.center,
          radius: 0.7,
          colors: [
            Color(0x143b82f6),
            Colors.transparent,
          ],
        ),
      ),
      child: Center(
        child: SingleChildScrollView(
          child: Padding(
            padding: const EdgeInsets.symmetric(
              vertical: AppSpacing.xxxl,
              horizontal: AppSpacing.lg,
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                // RFID button with error overlay (no layout jump)
                Consumer<RfidProvider>(
                  builder: (context, rfidProvider, child) {
                    if (rfidProvider.error != null &&
                        rfidProvider.error != _shownError) {
                      WidgetsBinding.instance.addPostFrameCallback((_) {
                        _startErrorDismissTimer(rfidProvider.error!);
                      });
                    }

                    return SizedBox(
                      width: 300,
                      height: 237,
                      child: Stack(
                        alignment: Alignment.center,
                        children: [
                          RfidDetectorButton(
                            hasError: rfidProvider.error != null,
                            errorOpacity: _errorOpacity,
                          ),
                          if (rfidProvider.error != null)
                            Positioned(
                              bottom: 0,
                              left: 0,
                              right: 0,
                              child: AnimatedOpacity(
                                opacity: _errorOpacity,
                                // Must match the clear timer, or the banner is
                                // torn out of the tree mid-fade.
                                duration: _errorFadeDuration,
                                child: Container(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: AppSpacing.md,
                                    vertical: AppSpacing.sm,
                                  ),
                                  decoration: BoxDecoration(
                                    color: const Color(0xffef4444).withValues(alpha: 0.95),
                                    borderRadius: BorderRadius.circular(AppBorderRadius.md),
                                    border: Border.all(
                                      color: const Color(0xffef4444),
                                      width: 1,
                                    ),
                                  ),
                                  child: Text(
                                    rfidProvider.error!
                                        .message(AppLocalizations.of(context)!),
                                    textAlign: TextAlign.center,
                                    style: TextStyle(
                                      color: Colors.white,
                                      fontSize: AppFontSizes.sm,
                                      fontWeight: FontWeight.w600,
                                    ),
                                  ),
                                ),
                              ),
                            ),
                        ],
                      ),
                    );
                  },
                ),
                const SizedBox(height: AppSpacing.xxxl),

                Text(
                  l10n.idleTitle,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Color(0xfff1f5f9),
                    fontSize: 55,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(height: AppSpacing.md),

                Text(
                  l10n.idleSubtitle,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Color(0xff94a3b8),
                    fontSize: AppFontSizes.xxl,
                    fontWeight: FontWeight.w400,
                  ),
                ),
                const SizedBox(height: AppSpacing.xxxl),
                if (context.read<ConfigService>().demoMode)
                  Consumer<RfidProvider>(
                    builder: (context, rfidProvider, child) {
                      return ElevatedButton(
                        onPressed: !rfidProvider.isScanning
                            ? () => rfidProvider.simulateCardDetection(context)
                            : null,
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xff3b82f6),
                          disabledBackgroundColor: const Color(0xff334155),
                          padding: const EdgeInsets.symmetric(
                            horizontal: AppSpacing.xl,
                            vertical: AppSpacing.md,
                          ),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(AppBorderRadius.md),
                          ),
                        ),
                        child: Text(
                          l10n.demoScanCard,
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: AppFontSizes.base,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      );
                    },
                  ),
                const SizedBox(height: AppSpacing.xxxl),
                Text(
                  AppConfig.version,
                  style: TextStyle(
                    color: const Color(0xff94a3b8).withValues(alpha: 0.4),
                    fontSize: AppFontSizes.xs,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
