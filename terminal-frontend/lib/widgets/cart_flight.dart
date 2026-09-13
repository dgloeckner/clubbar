import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/icon_registry.dart';

/// One-shot "fly to cart" sprite: a copy of the tapped product's icon leaves
/// the tile and arcs into the running total, which pops as it lands (#921).
///
/// The terminal animated the *edges* of a session well — the login burst, the
/// screen fade, the receipt's drain bar — and almost nothing in the middle,
/// where a member taps most. This is the tap's receipt: the thing you touched
/// went *there*, and the number over there is what changed because of it.
///
/// It is deliberately a sprite in the **root** overlay rather than a widget on
/// the screen (#644, `lib/config/app_router.dart`): a flight that is still in
/// the air when the member is logged out, scanned over by the next member, or
/// sent to `/cart` must not be torn down with the route it started on. It
/// finishes, or it is discarded, on its own.
///
/// Three rules it must not break, each from an incident rather than taste:
///
///  * **Transforms and opacity only** (#41). The icon is rastered once behind
///    a [RepaintBoundary] and the frames only move its layer; nothing here
///    rebuilds a Skia blur per frame the way an animated `BoxShadow` did.
///  * **It never eats a tap.** The sprite sits under [IgnorePointer], so the
///    next tap belongs to the tile or the button underneath it, not to a
///    decoration flying over them.
///  * **State first, motion second.** The caller has already told
///    `CartProvider` about the tap when it launches a flight. Nothing here
///    delays, debounces or reorders a cart mutation, and under reduced motion
///    ([MediaQuery.maybeDisableAnimationsOf]) nothing is launched at all —
///    the total simply shows its new value.
class CartFlight {
  const CartFlight._();

  /// How many sprites may be in the air at once.
  ///
  /// A member hammering a tile gets a cart update for *every* tap — that is
  /// the provider's job and it is synchronous — but only the first few get a
  /// sprite. Past a handful the screen is a swarm and one more icon adds
  /// nothing but raster work on a Pi.
  static const int maxInFlight = 8;

  /// How far above the straight line the arc lifts, as a fraction of the
  /// distance travelled…
  static const double _liftFraction = 0.35;

  /// …clamped, so a tile right next to the bar still arcs visibly and one in
  /// the far corner does not loop out of the screen.
  static const double _minLift = 40.0;
  static const double _maxLift = 160.0;

  static int _inFlight = 0;

  /// Sprites currently in the air. Test-visible so a suite can assert the cap
  /// without reaching into the overlay.
  static int get inFlight => _inFlight;

  /// Launches one sprite from [from] to [to], both in **global** coordinates.
  ///
  /// Returns false — and animates nothing — when the member has asked for
  /// reduced motion, or when [maxInFlight] sprites are already in the air.
  /// [onLanded] fires once, as the sprite arrives, and only when something
  /// was actually launched: the landing pop belongs to the flight, so a
  /// skipped flight has no landing.
  static bool launch(
    BuildContext context, {
    required Rect from,
    required Rect to,
    String? iconName,
    VoidCallback? onLanded,
  }) {
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) return false;
    if (_inFlight >= maxInFlight) return false;

    final overlay = Overlay.maybeOf(context, rootOverlay: true);
    if (overlay == null) return false;

    _inFlight++;
    late final OverlayEntry entry;
    var released = false;

    // The slot is given back exactly once, whichever way the flight ends.
    // A sprite *discarded* — its overlay torn down with the app, or the
    // screen under it replaced — never reaches `completed`, and a counter
    // that only ever counted completions would drift up until no member
    // could see a flight again.
    void release({required bool removeEntry}) {
      if (released) return;
      released = true;
      _inFlight--;
      if (removeEntry) entry.remove();
    }

    entry = OverlayEntry(
      builder: (context) => _CartFlightSprite(
        from: from,
        to: to,
        iconName: iconName,
        onLanded: onLanded,
        onCompleted: () => release(removeEntry: true),
        onDiscarded: () => release(removeEntry: false),
      ),
    );
    overlay.insert(entry);
    return true;
  }
}

/// The sprite itself: one [AnimationController], one cached icon raster, and
/// a transform per frame.
class _CartFlightSprite extends StatefulWidget {
  const _CartFlightSprite({
    required this.from,
    required this.to,
    required this.iconName,
    required this.onLanded,
    required this.onCompleted,
    required this.onDiscarded,
  });

  final Rect from;
  final Rect to;
  final String? iconName;

  /// Fired as the icon disappears into the total, not after it — the number
  /// has to react *to* the arrival.
  final VoidCallback? onLanded;

  /// Fired when the flight is over; the owner removes the overlay entry.
  final VoidCallback onCompleted;

  /// Fired if the sprite is torn down before it arrived — the owner gives the
  /// slot back but leaves the entry alone, since whatever removed the sprite
  /// is already removing the overlay it sat in.
  final VoidCallback onDiscarded;

  @override
  State<_CartFlightSprite> createState() => _CartFlightSpriteState();
}

class _CartFlightSpriteState extends State<_CartFlightSprite>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  bool _landed = false;

  /// When the landing pop starts, as a fraction of the flight.
  static const double _landAt = 0.8;

  /// The last fifth of the flight is also where the icon fades out, so it
  /// reads as absorbed by the bar rather than as vanishing over it.
  static const _fadeOut = Interval(0.8, 1.0, curve: Curves.easeIn);

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: AppAnimations.cartFlight,
    )
      ..addListener(_maybeLand)
      ..addStatusListener((status) {
        if (status == AnimationStatus.completed) widget.onCompleted();
      });
    _controller.forward();
  }

  void _maybeLand() {
    if (_landed || _controller.value < _landAt) return;
    _landed = true;
    widget.onLanded?.call();
  }

  @override
  void dispose() {
    widget.onDiscarded();
    _controller.dispose();
    super.dispose();
  }

  /// The quadratic Bézier the icon travels: start, end, and a control point
  /// lifted above both, so the icon is tossed up and swoops down into the bar
  /// instead of sliding along a ruler.
  Offset _positionAt(double t) {
    final start = widget.from.center;
    final end = widget.to.center;
    final lift = ((end - start).distance * CartFlight._liftFraction)
        .clamp(CartFlight._minLift, CartFlight._maxLift);
    final control = Offset(
      (start.dx + end.dx) / 2,
      math.min(start.dy, end.dy) - lift,
    );
    final u = 1.0 - t;
    return start * (u * u) + control * (2 * u * t) + end * (t * t);
  }

  @override
  Widget build(BuildContext context) {
    final size = math.max(widget.from.shortestSide, 1.0);

    return Positioned.fill(
      key: const Key('cart-flight'),
      // The member's next tap belongs to the grid underneath, never to a
      // sprite passing over it.
      child: IgnorePointer(
        child: AnimatedBuilder(
          animation: _controller,
          // Built once and cached: only the transform changes per frame.
          child: RepaintBoundary(
            child: getProductIcon(widget.iconName, size: size),
          ),
          builder: (context, child) {
            final t = _controller.value;
            final position = _positionAt(Curves.easeInOutCubic.transform(t));
            final scale =
                1.0 - 0.6 * Curves.easeIn.transform(t); // 1.0 → 0.4
            return Stack(
              children: [
                Positioned(
                  left: position.dx - size / 2,
                  top: position.dy - size / 2,
                  width: size,
                  height: size,
                  child: Opacity(
                    opacity: 1.0 - _fadeOut.transform(t),
                    child: Transform.scale(scale: scale, child: child),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }
}

/// A [Listenable] that says "something landed", and nothing else.
///
/// The screen owns one and the summary bar listens to it. Deliberately not a
/// value: the bar plays the same pop however many sprites arrive, and a
/// counter would tempt a listener into deriving state from it.
class CartLandingSignal extends ChangeNotifier {
  /// How many landings have been signalled — for tests, not for layout.
  int get count => _count;
  int _count = 0;

  void landed() {
    _count++;
    notifyListeners();
  }
}

/// Plays a single scale pop on [child] whenever [signal] fires (#921).
///
/// With no signal there is no ticker and therefore no frame: this is the same
/// rule the idle screen is held to (#760), applied to a widget that spends
/// almost all of its life at rest.
class PopOnSignal extends StatefulWidget {
  const PopOnSignal({
    required this.signal,
    required this.child,
    super.key,
  });

  /// Fires once per landing. Null means "never pops" — which is what a screen
  /// that has not wired a flight passes.
  final Listenable? signal;

  final Widget child;

  /// How far the pop overshoots.
  static const double peak = 1.18;

  @override
  State<PopOnSignal> createState() => _PopOnSignalState();
}

class _PopOnSignalState extends State<PopOnSignal>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _scale;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: AppAnimations.amountPop,
    );
    // Out past the target and back: `easeOutBack` on the way out, straight
    // back down, which reads as a bump rather than a wobble.
    _scale = TweenSequence<double>([
      TweenSequenceItem(
        tween: Tween(begin: 1.0, end: PopOnSignal.peak)
            .chain(CurveTween(curve: Curves.easeOutBack)),
        weight: 55,
      ),
      TweenSequenceItem(
        tween: Tween(begin: PopOnSignal.peak, end: 1.0)
            .chain(CurveTween(curve: Curves.easeOut)),
        weight: 45,
      ),
    ]).animate(_controller);
    widget.signal?.addListener(_pop);
  }

  @override
  void didUpdateWidget(PopOnSignal oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.signal != widget.signal) {
      oldWidget.signal?.removeListener(_pop);
      widget.signal?.addListener(_pop);
    }
  }

  void _pop() {
    if (!mounted) return;
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) return;
    _controller.forward(from: 0.0);
  }

  @override
  void dispose() {
    widget.signal?.removeListener(_pop);
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return ScaleTransition(scale: _scale, child: widget.child);
  }
}

/// Plays the same pop whenever [value] changes — the tile's `Nx` badge.
///
/// A sibling of [PopOnSignal] rather than the same widget: the badge has no
/// signal to listen to, it simply *is* a number that sometimes changes, and
/// the first value it is built with must not pop (that is the badge
/// appearing, which the layout already announces).
class PopOnChange extends StatefulWidget {
  const PopOnChange({
    required this.value,
    required this.child,
    super.key,
  });

  final Object? value;
  final Widget child;

  @override
  State<PopOnChange> createState() => _PopOnChangeState();
}

class _PopOnChangeState extends State<PopOnChange>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _scale;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: AppAnimations.amountPop,
    );
    _scale = TweenSequence<double>([
      TweenSequenceItem(
        tween: Tween(begin: 1.0, end: PopOnSignal.peak)
            .chain(CurveTween(curve: Curves.easeOutBack)),
        weight: 55,
      ),
      TweenSequenceItem(
        tween: Tween(begin: PopOnSignal.peak, end: 1.0)
            .chain(CurveTween(curve: Curves.easeOut)),
        weight: 45,
      ),
    ]).animate(_controller);
  }

  @override
  void didUpdateWidget(PopOnChange oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.value == widget.value) return;
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) return;
    _controller.forward(from: 0.0);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return ScaleTransition(scale: _scale, child: widget.child);
  }
}
