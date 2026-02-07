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

  @override
  void initState() {
    super.initState();
    // Start background sync when idle screen mounts
    WidgetsBinding.instance.addPostFrameCallback((_) {
      context.read<SyncProvider>().startBackgroundSync();

      // Start listening for real RFID scans
      context.read<RfidProvider>().startListening(context);

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
    context.read<RfidProvider>().stopListening();
    _rfidFocusNode.dispose();
    _rfidController.dispose();
    super.dispose();
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
                    // RFID button (glowing effect handled in RfidDetectorButton)
                    const RfidDetectorButton(),
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
                    const SizedBox(height: AppSpacing.xxxl),

                    // Error display
                    Consumer<RfidProvider>(
                      builder: (context, rfidProvider, child) {
                        if (rfidProvider.error != null) {
                          return Padding(
                            padding: const EdgeInsets.only(bottom: AppSpacing.lg),
                            child: Container(
                              padding: const EdgeInsets.symmetric(
                                horizontal: AppSpacing.lg,
                                vertical: AppSpacing.md,
                              ),
                              decoration: BoxDecoration(
                                color: const Color(0xffef4444).withValues(alpha: 0.1),
                                border: Border.all(
                                  color: const Color(0xffef4444),
                                  width: 1,
                                ),
                                borderRadius: BorderRadius.circular(AppBorderRadius.md),
                              ),
                              child: Text(
                                rfidProvider.error!,
                                textAlign: TextAlign.center,
                                style: const TextStyle(
                                  color: Color(0xffef4444),
                                  fontSize: AppFontSizes.base,
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                            ),
                          );
                        }
                        return const SizedBox.shrink();
                      },
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
