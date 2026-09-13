import 'package:flutter/material.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// One tile's entrance when a new category is shown: a fade from nothing and
/// an 8 px rise, started a little later than the tile before it (#921).
///
/// The stagger is what makes a category switch read as *this set of things
/// arriving* rather than as one rectangle of pixels being swapped for another.
/// It is kept very short on purpose — the last tile starts no later than
/// [AppAnimations.tileStaggerCap] after the first — because a member who
/// tapped "Snacks" wants snacks, not a curtain call.
///
/// Three things keep it from turning into a ticker that outlives its effect
/// (#760):
///
///  * It animates only when it is built **within** the stagger window after
///    [switchedAt]. A tile built later — one scrolled to below the fold, or
///    one rebuilt because the cart changed — renders at rest and creates no
///    controller at all.
///  * [switchedAt] comes from the moment the *selected category* changed, not
///    from a rebuild. A provider refresh, a cart change and a re-tap of the
///    chip already showing all leave it alone.
///  * Under reduced motion it renders at rest.
class StaggeredEntry extends StatefulWidget {
  const StaggeredEntry({
    required this.index,
    required this.switchedAt,
    required this.child,
    super.key,
  });

  /// Position in grid order — what turns one duration into a stagger.
  final int index;

  /// When the category changed, or null on a screen that has not switched
  /// yet: the first paint after login does not stagger.
  final DateTime? switchedAt;

  final Widget child;

  /// How far the tile rises into place.
  static const double rise = 8.0;

  /// How late tile [index] starts, capped so a 40-product category does not
  /// take a second to finish arriving.
  static Duration delayFor(int index) {
    final delay = AppAnimations.tileStagger * index;
    return delay > AppAnimations.tileStaggerCap
        ? AppAnimations.tileStaggerCap
        : delay;
  }

  /// The whole stagger's length: the last start, plus one tile's entrance.
  static Duration get window =>
      AppAnimations.tileStaggerCap + AppAnimations.tileEnter;

  @override
  State<StaggeredEntry> createState() => _StaggeredEntryState();
}

class _StaggeredEntryState extends State<StaggeredEntry>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  /// The switch this tile is animating for, so a *second* category switch
  /// while the first is still playing restarts it rather than being ignored.
  DateTime? _playedFor;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: AppAnimations.tileEnter,
      // At rest means fully in place: a tile that never animates is a tile
      // that is simply there.
      value: 1.0,
    );
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _maybePlay();
  }

  @override
  void didUpdateWidget(StaggeredEntry oldWidget) {
    super.didUpdateWidget(oldWidget);
    _maybePlay();
  }

  void _maybePlay() {
    final switchedAt = widget.switchedAt;
    if (switchedAt == null || switchedAt == _playedFor) return;
    _playedFor = switchedAt;

    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) {
      _controller.value = 1.0;
      return;
    }

    // A tile built after the window has passed has missed its entrance —
    // it was scrolled to, not switched to.
    final since = DateTime.now().difference(switchedAt);
    if (since >= StaggeredEntry.window) {
      _controller.value = 1.0;
      return;
    }

    final delay = StaggeredEntry.delayFor(widget.index) - since;
    _controller.value = 0.0;
    if (delay <= Duration.zero) {
      _controller.forward();
    } else {
      // `forward(from:)` after a delay rather than a timer: the controller
      // owns its own schedule, so disposing it is all the cleanup there is.
      Future<void>.delayed(delay, () {
        if (mounted && _playedFor == switchedAt) _controller.forward();
      });
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      // Transforms and opacity only (#41), over a raster built once.
      child: RepaintBoundary(child: widget.child),
      builder: (context, child) {
        final t = Curves.easeOut.transform(_controller.value);
        return Opacity(
          opacity: t,
          child: Transform.translate(
            offset: Offset(0, StaggeredEntry.rise * (1.0 - t)),
            child: child,
          ),
        );
      },
    );
  }
}
