import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/rfid_detector_button.dart';

class IdleWaitingScreen extends StatefulWidget {
  const IdleWaitingScreen({super.key});

  @override
  State<IdleWaitingScreen> createState() => _IdleWaitingScreenState();
}

class _IdleWaitingScreenState extends State<IdleWaitingScreen> {
  final StringBuffer _rfidBuffer = StringBuffer();
  Timer? _errorDismissTimer;
  String? _lastError;
  double _errorOpacity = 1.0;
  late RfidProvider _rfidProvider;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<SyncProvider>().startBackgroundSync();

      // Save reference to RfidProvider for safe disposal
      _rfidProvider = context.read<RfidProvider>();

      // Start listening for real RFID scans (stream subscription)
      _rfidProvider.startListening(context);

      // Capture all keyboard input globally — works on Linux/Wayland and macOS
      HardwareKeyboard.instance.addHandler(_onKeyEvent);
    });
  }

  @override
  void dispose() {
    _errorDismissTimer?.cancel();
    HardwareKeyboard.instance.removeHandler(_onKeyEvent);
    _rfidProvider.stopListening();
    super.dispose();
  }

  /// Accumulate characters from the RFID reader (USB keyboard emulation).
  /// Emits the buffered UID when Enter is received.
  bool _onKeyEvent(KeyEvent event) {
    if (event is! KeyDownEvent) return false;
    if (event.logicalKey == LogicalKeyboardKey.enter) {
      final uid = _rfidBuffer.toString().trim();
      _rfidBuffer.clear();
      if (uid.isNotEmpty) {
        _rfidProvider.emitScan(uid);
      }
    } else if (event.character != null && event.character!.isNotEmpty) {
      _rfidBuffer.write(event.character);
    }
    return false; // don't consume — let other widgets handle events normally
  }

  /// Translate RFID error key to localized message
  String _getLocalizedError(BuildContext context, String errorKey) {
    final l10n = AppLocalizations.of(context)!;

    switch (errorKey) {
      case 'rfidErrorUnknownCard':
        return l10n.rfidErrorUnknownCard;
      case 'rfidErrorAccountInactive':
        return l10n.rfidErrorAccountInactive;
      case 'rfidErrorSepaMissing':
        return l10n.rfidErrorSepaMissing;
      case 'rfidErrorDatabaseError':
        return l10n.rfidErrorDatabaseError;
      default:
        return errorKey;
    }
  }

  /// Start auto-dismiss timer for error message
  void _startErrorDismissTimer(String error) {
    _errorDismissTimer?.cancel();

    setState(() {
      _errorOpacity = 1.0;
      _lastError = error;
    });

    _errorDismissTimer = Timer(const Duration(seconds: 5), () {
      setState(() {
        _errorOpacity = 0.0;
      });

      Timer(const Duration(milliseconds: 500), () {
        if (mounted) {
          context.read<RfidProvider>().clearDetection();
        }
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
                    if (rfidProvider.error != null && rfidProvider.error != _lastError) {
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
                                duration: const Duration(milliseconds: 500),
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
                                    _getLocalizedError(context, rfidProvider.error!),
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
              ],
            ),
          ),
        ),
      ),
    );
  }
}
