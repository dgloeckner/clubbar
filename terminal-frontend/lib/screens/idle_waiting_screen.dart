import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/widgets/rfid_detector_button.dart';

class IdleWaitingScreen extends StatefulWidget {
  const IdleWaitingScreen({super.key});

  @override
  State<IdleWaitingScreen> createState() => _IdleWaitingScreenState();
}

class _IdleWaitingScreenState extends State<IdleWaitingScreen> {
  final FocusNode _rfidFocusNode = FocusNode();
  final TextEditingController _rfidController = TextEditingController();
  Timer? _errorDismissTimer;
  String? _lastError;
  double _errorOpacity = 1.0;
  late RfidProvider _rfidProvider;

  @override
  void initState() {
    super.initState();
    // Start background sync when idle screen mounts
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<SyncProvider>().startBackgroundSync();

      // Save reference to RfidProvider for safe disposal
      _rfidProvider = context.read<RfidProvider>();

      // Start listening for real RFID scans
      _rfidProvider.startListening(context);

      // Request focus for hidden RFID input
      _rfidFocusNode.requestFocus();
    });

    // Keep focus on hidden field (prevent user clicks from stealing focus)
    _rfidFocusNode.addListener(() {
      if (!_rfidFocusNode.hasFocus) {
        Future.delayed(const Duration(milliseconds: 100), () {
          if (mounted) {
            _rfidFocusNode.requestFocus();
          }
        });
      }
    });
  }

  @override
  void dispose() {
    _errorDismissTimer?.cancel();
    _rfidProvider.stopListening();
    _rfidFocusNode.dispose();
    _rfidController.dispose();
    super.dispose();
  }

  /// Translate RFID error key to localized message
  String _getLocalizedError(BuildContext context, String errorKey) {
    final l10n = AppLocalizations.of(context)!;

    // Map error keys to localized messages
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
        // Fallback for non-standard errors (show as-is)
        return errorKey;
    }
  }

  /// Start auto-dismiss timer for error message
  void _startErrorDismissTimer(String error) {
    // Cancel existing timer if any
    _errorDismissTimer?.cancel();

    // Reset opacity to fully visible
    setState(() {
      _errorOpacity = 1.0;
      _lastError = error;
    });

    // Start 5-second countdown
    _errorDismissTimer = Timer(const Duration(seconds: 5), () {
      // Fade out over 500ms
      setState(() {
        _errorOpacity = 0.0;
      });

      // Clear error from provider after fade completes
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

    // Body content only - MainLayout provides Scaffold and header
    return Stack(
      children: [
        // Hidden TextField for capturing RFID scans (USB keyboard emulation)
        Positioned(
          left: -1000, // Off-screen but still focusable
          top: 0,
          child: SizedBox(
            width: 100,
            height: 50,
            child: TextField(
              controller: _rfidController,
              focusNode: _rfidFocusNode,
              autofocus: true,
              enableSuggestions: false,
              autocorrect: false,
              keyboardType: TextInputType.text,
              onSubmitted: (value) {
                // RFID reader scanned a card (sends UID + Enter)
                if (value.isNotEmpty) {
                  context.read<RfidProvider>().emitScan(value);
                  _rfidController.clear();
                }
                // Re-focus for next scan
                _rfidFocusNode.requestFocus();
              },
              style: const TextStyle(color: Colors.transparent),
              decoration: const InputDecoration(
                border: InputBorder.none,
                contentPadding: EdgeInsets.zero,
              ),
            ),
          ),
        ),

        // Main UI
        Container(
          // Radial gradient glow from center (from prototype)
          decoration: const BoxDecoration(
            gradient: RadialGradient(
              center: Alignment.center,
              radius: 0.7,
              colors: [
                Color(0x143b82f6), // rgba(59, 130, 246, 0.08)
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
                        // Start auto-dismiss timer when error appears or changes
                        if (rfidProvider.error != null && rfidProvider.error != _lastError) {
                          WidgetsBinding.instance.addPostFrameCallback((_) {
                            _startErrorDismissTimer(rfidProvider.error!);
                          });
                        }

                        return SizedBox(
                          width: 300,
                          height: 182,
                          child: Stack(
                            alignment: Alignment.center,
                            children: [
                              // RFID button (changes color when error)
                              RfidDetectorButton(
                                hasError: rfidProvider.error != null,
                                errorOpacity: _errorOpacity,
                              ),

                              // Error text overlay (positioned on top of button)
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
                                        style: const TextStyle(
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

                    // Welcome text (30% larger than xxxl)
                    Text(
                      l10n.idleTitle,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        color: Color(0xfff1f5f9), // Primary text
                        fontSize: 42, // 32 * 1.3
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: AppSpacing.md),

                    // Subtitle (30% larger than base)
                    Text(
                      l10n.idleSubtitle,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        color: Color(0xff94a3b8), // Secondary text
                        fontSize: 21, // 16 * 1.3
                        fontWeight: FontWeight.w400,
                      ),
                    ),
                    // Optional demo button
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
                            style: const TextStyle(
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
        ),
      ],
    );
  }
}
