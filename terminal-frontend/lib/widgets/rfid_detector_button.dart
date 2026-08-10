import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/services/config_service.dart';

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
  late Animation<double> _glowAnimation;
  late Animation<double> _opacityAnimation;

  // Colors from prototype
  static const Color _blue = Color(0xff3b82f6);
  static const Color _teal = Color(0xff14b8a6);
  static const Color _red = Color(0xffef4444);
  static const Color _slate = Color(0xff64748b);

  @override
  void initState() {
    super.initState();
    _glowController = AnimationController(
      duration: const Duration(milliseconds: 2000),
      vsync: this,
    )..repeat(reverse: true);

    // Glow blur radius: 40px -> 60px (from prototype)
    _glowAnimation = Tween<double>(begin: 40.0, end: 60.0).animate(
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
          child: AnimatedBuilder(
            animation: _glowAnimation,
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
                        blurRadius: rfidProvider.isScanning ? 60 : _glowAnimation.value,
                        spreadRadius: rfidProvider.isScanning ? 5 : 0,
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
        );
      },
    );
  }
}
