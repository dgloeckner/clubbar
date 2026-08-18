import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

class RfidDetectorButton extends StatefulWidget {
  final bool hasError;
  final double errorOpacity;

  /// The reader is known to be gone (issue #35): the button stops inviting a
  /// scan that cannot be read, and says so rather than pulsing forever.
  final bool isOffline;

  const RfidDetectorButton({
    super.key,
    this.hasError = false,
    this.errorOpacity = 1.0,
    this.isOffline = false,
  });

  @override
  State<RfidDetectorButton> createState() => _RfidDetectorButtonState();
}

class _RfidDetectorButtonState extends State<RfidDetectorButton>
    with SingleTickerProviderStateMixin {
  late AnimationController _glowController;
  late Animation<double> _spreadAnimation;
  late Animation<double> _opacityAnimation;

  // Fixed blur radius (was animated 40px -> 60px): a BoxShadow's blurRadius
  // drives Skia's mask-filter sigma, so animating it forces a new blur mask
  // every frame. On the terminal's Pi this pinned a core near 70% CPU for as
  // long as the idle screen (this button) was on screen — i.e. always. The
  // spread/opacity pulse below reproduces the same visual breathing effect
  // without ever touching blurRadius.
  static const double _glowBlurRadius = 50.0;

  // Colors from prototype
  static const Color _blue = AppColors.semanticPrimary;
  static const Color _teal = AppColors.accentTeal;
  static const Color _red = AppColors.semanticDanger;
  static const Color _slate = AppColors.textDisabled;

  @override
  void initState() {
    super.initState();
    _glowController = AnimationController(
      duration: const Duration(milliseconds: 2000),
      vsync: this,
    )..repeat(reverse: true);

    // Glow spread radius: 0px -> 6px (replaces the old blurRadius animation)
    _spreadAnimation = Tween<double>(begin: 0.0, end: 6.0).animate(
      CurvedAnimation(parent: _glowController, curve: Curves.easeInOut),
    );

    // Glow opacity: 0.2 -> 0.4 (from prototype)
    _opacityAnimation = Tween<double>(begin: 0.2, end: 0.4).animate(
      CurvedAnimation(parent: _glowController, curve: Curves.easeInOut),
    );
  }

  @override
  void dispose() {
    _glowController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Consumer<RfidProvider>(
      builder: (context, rfidProvider, child) {
        // A missing reader must not keep pulsing: the glow is an invitation to
        // tap, and there is nothing to tap into.
        if (widget.isOffline) {
          _glowController.stop();
        } else {
          // Adjust animation speed based on state
          final wanted = rfidProvider.isScanning
              ? const Duration(milliseconds: 800) // faster pulse when scanning
              : const Duration(milliseconds: 2000); // slower glow when idle
          if (_glowController.duration != wanted ||
              !_glowController.isAnimating) {
            _glowController.duration = wanted;
            _glowController.repeat(reverse: true);
          }
        }

        // Interpolate colors based on error state
        final Color primaryColor = widget.isOffline
            ? _slate
            : widget.hasError
                ? Color.lerp(_red, _blue, 1.0 - widget.errorOpacity)!
                : _blue;
        final Color secondaryColor = widget.isOffline
            ? _slate
            : widget.hasError
                ? Color.lerp(_red, _teal, 1.0 - widget.errorOpacity)!
                : _teal;

        final demoMode = context.read<ConfigService>().demoMode;
        return GestureDetector(
          onTap: demoMode && !rfidProvider.isScanning
              ? () => rfidProvider.simulateCardDetection(context)
              : null,
          child: RepaintBoundary(
            child: AnimatedBuilder(
              animation: _glowController,
              builder: (context, child) {
                return Container(
                  width: 237,
                  height: 237,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    gradient: LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: rfidProvider.isScanning
                          ? [primaryColor, secondaryColor] // Full opacity when scanning
                          : [
                              primaryColor.withValues(alpha: 0.2),
                              secondaryColor.withValues(alpha: 0.2),
                            ], // 20% opacity when idle
                    ),
                    boxShadow: [
                      if (!widget.isOffline)
                        BoxShadow(
                          color: primaryColor.withValues(
                            alpha: rfidProvider.isScanning ? 0.5 : _opacityAnimation.value,
                          ),
                          blurRadius: rfidProvider.isScanning ? 60 : _glowBlurRadius,
                          spreadRadius: rfidProvider.isScanning ? 5 : _spreadAnimation.value,
                        ),
                    ],
                  ),
                  child: Center(
                    child: widget.isOffline
                        ? const Icon(
                            Icons.sensors_off,
                            size: 135,
                            color: _slate,
                          )
                        : rfidProvider.isScanning
                        ? SizedBox(
                            width: 101,
                            height: 101,
                            child: CircularProgressIndicator(
                              valueColor: AlwaysStoppedAnimation(
                                Colors.white.withValues(alpha: 0.8),
                              ),
                              strokeWidth: 3,
                            ),
                          )
                        : SvgPicture.asset(
                            'assets/icons/ui/rfid_icon.svg',
                            width: 135,
                            height: 135,
                          ),
                  ),
                );
              },
            ),
          ),
        );
      },
    );
  }
}
