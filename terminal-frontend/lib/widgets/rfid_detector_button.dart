import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// The scan target on the idle screen — a glowing disc inviting a card.
///
/// **This widget runs no animation while idle, and that is the point**
/// (issue #760). It used to breathe: an [AnimationController] on `repeat`,
/// driving a shadow's spread and opacity, created in `initState` and never
/// stopped. The idle screen is the state a bar terminal is in almost all of
/// the time, so "while idle" meant "always" — the Pi rasterized ~27 frames a
/// second for a screen nobody was looking at, and did it *through* the
/// blanking overlay `scripts/blackscreen.py` puts over the display after five
/// idle minutes. Measured on a Pi 4B: 27.7 % of a core on the platform thread,
/// 6 % on the raster thread, and about 7 °C — enough to cross the 80 °C soft
/// limit and record a throttling event (`vcgencmd get_throttled` = 0x80000).
///
/// An earlier pass (#41) pinned `blurRadius` because animating it rebuilds
/// Skia's blur mask every frame. That helped and did not cure it: animating
/// `spreadRadius` changes the shadow's *shape*, so the mask is recomputed
/// anyway. The cure is not a cheaper pulse — it is no pulse. The glow is now a
/// constant, so the idle screen produces no frames at all.
///
/// Nothing is lost visually: the pulse was only ever drawn in the idle state.
/// While scanning, the disc has always used fixed shadow values and shown a
/// spinner, and it still does — that animation is bounded by the scan.
class RfidDetectorButton extends StatelessWidget {
  final bool hasError;
  final double errorOpacity;

  /// The reader is known to be gone (issue #35): the button stops inviting a
  /// scan that cannot be read, and says so rather than glowing forever.
  final bool isOffline;

  const RfidDetectorButton({
    super.key,
    this.hasError = false,
    this.errorOpacity = 1.0,
    this.isOffline = false,
  });

  // Fixed blur radius (was animated 40px -> 60px): a BoxShadow's blurRadius
  // drives Skia's mask-filter sigma, so animating it forces a new blur mask
  // every frame (#41).
  static const double _glowBlurRadius = 50.0;

  // The idle glow, at the midpoint of the pulse it replaces (#760): spread ran
  // 0 -> 6 px and opacity 0.2 -> 0.4, so a still frame at the middle is what
  // the breathing disc averaged out to. The disc looks like itself; it just
  // holds still.
  static const double _idleGlowSpread = 3.0;
  static const double _idleGlowOpacity = 0.3;

  // Colors from prototype
  static const Color _blue = AppColors.semanticPrimary;
  static const Color _teal = AppColors.accentTeal;
  static const Color _red = AppColors.semanticDanger;
  static const Color _slate = AppColors.textDisabled;

  @override
  Widget build(BuildContext context) {
    return Consumer<RfidProvider>(
      builder: (context, rfidProvider, child) {
        // Interpolate colors based on error state
        final Color primaryColor = isOffline
            ? _slate
            : hasError
                ? Color.lerp(_red, _blue, 1.0 - errorOpacity)!
                : _blue;
        final Color secondaryColor = isOffline
            ? _slate
            : hasError
                ? Color.lerp(_red, _teal, 1.0 - errorOpacity)!
                : _teal;

        final demoMode = context.read<ConfigService>().demoMode;
        return GestureDetector(
          onTap: demoMode && !rfidProvider.isScanning
              ? () => rfidProvider.simulateCardDetection(context)
              : null,
          // Keeps the scanning spinner's repaints off the rest of the screen.
          // Idle, there is nothing here to repaint at all.
          child: RepaintBoundary(
            child: Container(
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
                  if (!isOffline)
                    BoxShadow(
                      color: primaryColor.withValues(
                        alpha: rfidProvider.isScanning ? 0.5 : _idleGlowOpacity,
                      ),
                      blurRadius: rfidProvider.isScanning ? 60 : _glowBlurRadius,
                      spreadRadius:
                          rfidProvider.isScanning ? 5 : _idleGlowSpread,
                    ),
                ],
              ),
              child: Center(
                child: isOffline
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
            ),
          ),
        );
      },
    );
  }
}
