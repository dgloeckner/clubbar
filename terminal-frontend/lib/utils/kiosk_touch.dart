import 'package:flutter/gestures.dart';

/// Touch slop for the kiosk, in logical pixels.
///
/// Flutter's default is [kTouchSlop] = 18: move a finger further than that
/// between down and up and the tap is gone — the tile it started on never
/// fires. That default is calibrated for a phone held in the hand. A bar
/// terminal is a fixed panel tapped at arm's length by a finger that rolls as
/// it lifts, and 18 px of drift is what an ordinary tap produces there, which
/// is why members had to tap a product two or three times to get it into the
/// cart.
///
/// 36 px asks for a deliberate swipe before a gesture stops being a tap.
/// Scrolling still works; it just no longer wins gestures meant as taps.
///
/// Both recognizers that matter read it from `MediaQuery.gestureSettings`,
/// which `TerminalMaterialApp` overrides for every route below it: the tap
/// recognizer takes it as its `preAcceptSlopTolerance`, and the scrollable's
/// drag recognizer as the distance before it claims the gesture. Neither is
/// configured at the call site, so a tap target does not have to opt in.
///
/// This is the second half of the fix; see `KioskScrollBehavior` for the
/// first. On a grid that cannot scroll at all, no amount of slop helps if a
/// drag recognizer is in the arena regardless.
const double kKioskTouchSlop = 36.0;
