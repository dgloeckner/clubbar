import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// Scroll behaviour for touchscreen kiosk use:
/// - Enables finger-drag scrolling on Linux (not included in the Flutter
///   desktop default which only covers mouse/trackpad).
/// - Uses BouncingScrollPhysics for a natural overscroll feel.
///
/// Deliberately **without** [AlwaysScrollableScrollPhysics]. That flag makes
/// `shouldAcceptUserOffset` return true unconditionally, so every list and
/// grid in the app keeps a vertical drag recognizer in the gesture arena even
/// when its content fits and there is nothing to scroll. A tap whose finger
/// drifts more than the touch slop then loses the arena to that drag, and
/// `ProductCard` gets `onTapCancel` instead of `onTapUp` — the product never
/// reaches the cart and the member taps the tile again. On a kiosk digitizer
/// with a finger rolling off the glass that is a routine amount of drift, not
/// an edge case. Without the flag Flutter withdraws the drag recognizer as
/// soon as the content fits, and those taps win instantly. See also
/// `kKioskTouchSlop` in `terminal_material_app.dart`, which covers the case where the grid *does* scroll.
class KioskScrollBehavior extends MaterialScrollBehavior {
  @override
  Set<PointerDeviceKind> get dragDevices => const {
    PointerDeviceKind.touch,
    PointerDeviceKind.mouse,
  };

  @override
  ScrollPhysics getScrollPhysics(BuildContext context) =>
      const BouncingScrollPhysics();

  /// A scrollbar that stays up on every vertical list that can scroll.
  ///
  /// The desktop default fades its thumb out unless a mouse hovers, and on a
  /// touch kiosk nothing ever hovers: a cart line below the fold was invisible,
  /// and nothing said the list went on. The thumb is painted only when the
  /// content is longer than the viewport, so a list that fits shows nothing.
  @override
  Widget buildScrollbar(
      BuildContext context, Widget child, ScrollableDetails details) {
    if (axisDirectionToAxis(details.direction) != Axis.vertical) return child;
    return RawScrollbar(
      controller: details.controller,
      thumbVisibility: true,
      thickness: 6,
      radius: const Radius.circular(3),
      thumbColor: AppColors.textSecondary.withValues(alpha: 0.6),
      child: child,
    );
  }
}
