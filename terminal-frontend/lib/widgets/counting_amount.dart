import 'dart:async';

import 'package:flutter/material.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// An amount that *counts* to its new value instead of jumping to it (#921).
///
/// Two numbers on this terminal earn it: the running total on the product
/// grid, which changes under the member's own finger, and the balance on the
/// receipt, which is the number they walk away with. Both are amounts a member
/// is watching *because* of something they just did; a number that slides from
/// the old value to the new one says "this is what your tap cost" in a way a
/// jump cannot.
///
/// The tween is on **cents as an int**, and every frame is formatted through
/// the caller's own [format] — which is [formatPrice] or [formatBalance], never
/// a reimplementation. A separator, a currency symbol and the balance's
/// wording (credit vs open tab) are decided in exactly one place on this
/// terminal, and an animation is not the place to fork them.
///
/// Retargeting mid-flight starts from the value *currently displayed*, not
/// from the original start: a member tapping three times in two seconds must
/// see one continuous number, not three restarts.
///
/// Under reduced motion ([MediaQuery.maybeDisableAnimationsOf]) it renders the
/// target immediately — the value is never the thing that is skipped, only the
/// motion is.
class CountingAmount extends StatefulWidget {
  const CountingAmount({
    required this.cents,
    required this.format,
    this.style,
    this.styleFor,
    this.duration = AppAnimations.countUp,
    this.startCents,
    this.delay = Duration.zero,
    this.textAlign,
    this.textKey,
    super.key,
  });

  /// The value to count *to*.
  final int cents;

  /// How a value is written — [formatPrice] or [formatBalance], bound to the
  /// member's locale by the caller.
  final String Function(int cents) format;

  /// Fixed style, for an amount whose appearance does not depend on its value.
  final TextStyle? style;

  /// Per-value style, for one that does: the receipt's balance is red while it
  /// is owed and green while it is credit ([balanceColor]), and a count that
  /// crosses zero has to cross the colour with it. Takes precedence over
  /// [style].
  final TextStyle Function(int cents)? styleFor;

  final Duration duration;

  /// Where the count starts, for a one-shot count whose start is *not* the
  /// previously displayed value — the receipt's balance begins at the balance
  /// before this checkout. Defaults to [cents], i.e. no initial count.
  final int? startCents;

  /// How long to wait before the initial count begins. The receipt gives its
  /// card time to scale in first, so the number is legible before it moves.
  final Duration delay;

  final TextAlign? textAlign;

  /// Key placed on the rendered [Text], so a screen keeps the key its tests
  /// and its other readers already look for (`cart-summary-total`,
  /// `receipt-balance`) rather than gaining an animation-shaped one.
  final Key? textKey;

  @override
  State<CountingAmount> createState() => _CountingAmountState();
}

class _CountingAmountState extends State<CountingAmount>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;

  /// Where the current count started and where it is going. [_from] is
  /// re-read from the *displayed* value on every retarget.
  late int _from;
  late int _to;

  /// Set once the initial count has been started (or skipped), so a rebuild
  /// cannot replay it.
  bool _started = false;

  /// Pending start of a delayed count; cancelled on dispose so a receipt
  /// dismissed early leaves no timer behind.
  Timer? _delayTimer;

  @override
  void initState() {
    super.initState();
    _to = widget.cents;
    _from = widget.startCents ?? widget.cents;
    _controller = AnimationController(vsync: this, duration: widget.duration);
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    if (_from == _to || _reducedMotion) {
      // Nothing to count, or nothing to animate: the target is already what
      // [_displayed] reports at rest.
      _from = _to;
      return;
    }
    if (widget.delay == Duration.zero) {
      _controller.forward(from: 0.0);
      return;
    }
    // Held at the start value until the delay is up — a number that is still
    // scaling in is not one the member can read moving.
    _delayTimer = Timer(widget.delay, () {
      if (mounted) _controller.forward(from: 0.0);
    });
  }

  bool get _reducedMotion =>
      MediaQuery.maybeDisableAnimationsOf(context) ?? false;

  @override
  void didUpdateWidget(CountingAmount oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.cents == _to) return;
    // Retarget from what is on screen right now, so a tap mid-count extends
    // the movement rather than restarting it somewhere else.
    _from = _displayed;
    _to = widget.cents;
    if (_reducedMotion) {
      _from = _to;
      _controller.value = 1.0;
      return;
    }
    _controller.forward(from: 0.0);
  }

  /// The value the widget is showing at this instant.
  int get _displayed {
    final t = Curves.easeOutCubic.transform(_controller.value.clamp(0.0, 1.0));
    return (_from + (_to - _from) * t).round();
  }

  @override
  void dispose() {
    _delayTimer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) {
        final cents = _displayed;
        final style = widget.styleFor?.call(cents) ?? widget.style;
        return Text(
          widget.format(cents),
          key: widget.textKey,
          textAlign: widget.textAlign,
          style: (style ?? const TextStyle()).copyWith(
            // Tabular figures, so a counting amount never reflows what sits
            // beside it: "1,50 €" → "12,00 €" would otherwise shove its own
            // label sideways twenty times a second.
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        );
      },
    );
  }
}
