import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/models/login_moment.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// Zero-size listener that plays the login success animation into the **root**
/// overlay whenever a card scan starts a session.
///
/// Lives in the app shell for the same reason `ScanCapture` does: a login can
/// happen from any route — the idle screen, and the confirmation screen's
/// takeover (ADR-0027 rule 9) — so the celebration belongs to the terminal,
/// not to a screen. It renders *above* the route transition already running
/// underneath, so it adds zero latency: the products screen is loading in
/// while the burst plays, and the fade-out reveals it ready to use.
///
/// Occurrence tracking mirrors `_ScanFeedbackBanner`: [LoginMoment.sequence]
/// makes each login a distinct event, and the moment current at mount time is
/// adopted as already shown, so a shell remount never replays a celebration.
class LoginSuccessOverlay extends StatefulWidget {
  const LoginSuccessOverlay({super.key});

  @override
  State<LoginSuccessOverlay> createState() => _LoginSuccessOverlayState();
}

class _LoginSuccessOverlayState extends State<LoginSuccessOverlay> {
  RfidProvider? _rfidProvider;
  OverlayEntry? _entry;
  LoginMoment? _shownMoment;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final rfidProvider = context.read<RfidProvider>();
      _rfidProvider = rfidProvider;
      // A login that happened before this widget existed has been celebrated
      // by a previous incarnation; replaying it mid-session would be noise.
      _shownMoment = rfidProvider.loginMoment;
      rfidProvider.addListener(_onLoginMoment);
    });
  }

  @override
  void dispose() {
    _rfidProvider?.removeListener(_onLoginMoment);
    _entry?.remove();
    _entry = null;
    super.dispose();
  }

  void _onLoginMoment() {
    final provider = _rfidProvider;
    if (provider == null || !mounted) return;

    final moment = provider.loginMoment;
    if (moment == null || moment == _shownMoment) return;
    _shownMoment = moment;

    // Remove and re-insert rather than rebuild, like the scan feedback
    // banner: overlay entries stack in insertion order, so a new login lands
    // on top of whatever is currently showing.
    _entry?.remove();
    late final OverlayEntry entry;
    entry = OverlayEntry(
      builder: (context) => LoginBurst(
        firstName: (moment.member.firstName ?? '').trim(),
        onCompleted: () => _removeEntry(entry),
      ),
    );
    _entry = entry;
    Overlay.of(context, rootOverlay: true).insert(entry);
  }

  /// Remove [entry] unless dispose (or a newer login) already did.
  void _removeEntry(OverlayEntry entry) {
    if (_entry != entry) return;
    _entry = null;
    entry.remove();
  }

  // Nothing to render in place: the burst lives in the root overlay.
  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}

/// One-shot login celebration in the club bar brand language
/// (`artwork/clubbar-logo.svg`): ripple rings in the logo's emerald→cyan
/// accent burst outward from the scan point while club-star sparkles twinkle
/// around them; a dark-glass badge with the accent rim pops in and **fills
/// with golden beer** — animated wave surface, foam line, carbonation bubbles
/// rising — then a foam-white checkmark draws itself over the pour and the
/// member is greeted by name above a sweeping accent underline (the logo's
/// mug band). Everything fades out over the products screen already on the
/// glass underneath.
///
/// Deliberately quick (about a second, [duration]) — it must feel like a
/// reward, never like a loading screen — and deliberately cheap: only
/// transforms, opacity, strokes, fills and one oval clip in a single
/// [CustomPainter]; no animated blur, no mask filters, no save layers. A
/// [BoxShadow] blur animated per frame once pinned a core of the terminal's
/// Pi (see `RfidDetectorButton`); nothing here goes near that.
///
/// With animations disabled ([MediaQuery.disableAnimationsOf]) the burst
/// skips itself entirely: the member lands on the products screen through the
/// normal route transition, just without the celebration.
class LoginBurst extends StatefulWidget {
  /// How long the whole celebration takes, fade-out included.
  static const Duration duration = Duration(milliseconds: 1250);

  final String firstName;

  /// Called exactly once, when the burst has played out (or was skipped for
  /// reduced motion). The owner removes the overlay entry here.
  final VoidCallback onCompleted;

  const LoginBurst({
    required this.firstName,
    required this.onCompleted,
    super.key,
  });

  @override
  State<LoginBurst> createState() => _LoginBurstState();
}

class _LoginBurstState extends State<LoginBurst>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  bool _started = false;

  /// Whole-burst fade-out at the end; everything sits under this.
  static const _fadeOut = Interval(0.84, 1.0, curve: Curves.easeIn);

  /// Scrim fade-in — fast, so the burst pops against a calm background.
  static const _scrimIn = Interval(0.0, 0.12, curve: Curves.easeOut);

  /// Greeting slide-and-fade, timed to land as the pour settles.
  static const _greetIn = Interval(0.4, 0.64, curve: Curves.easeOutCubic);

  /// The accent underline sweeping in under the name — the logo's mug band.
  static const _underlineIn = Interval(0.5, 0.72, curve: Curves.easeOutCubic);

  @override
  void initState() {
    super.initState();
    _controller =
        AnimationController(vsync: this, duration: LoginBurst.duration);
    _controller.addStatusListener((status) {
      if (status == AnimationStatus.completed) widget.onCompleted();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) {
      // Reduced motion: no celebration, but the owner must still clean up.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) widget.onCompleted();
      });
    } else {
      _controller.forward();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) {
      return const SizedBox.shrink();
    }

    final l10n = AppLocalizations.of(context)!;

    return Positioned.fill(
      // The member's next tap belongs to the products screen underneath, not
      // to a celebration.
      child: IgnorePointer(
        child: RepaintBoundary(
          child: AnimatedBuilder(
            animation: _controller,
            builder: (context, child) {
              final t = _controller.value;
              final visible = 1.0 - _fadeOut.transform(t);
              final scrim = _scrimIn.transform(t) * 0.78;
              final greet = _greetIn.transform(t);
              final underline = _underlineIn.transform(t);

              return Opacity(
                opacity: visible,
                child: Stack(
                  children: [
                    Positioned.fill(
                      child: ColoredBox(
                        color: AppColors.bgPrimary.withValues(alpha: scrim),
                      ),
                    ),
                    Positioned.fill(
                      child: CustomPaint(painter: _LoginBurstPainter(t)),
                    ),
                    Center(
                      child: Transform.translate(
                        // Clear of the badge, sliding up into place.
                        offset: Offset(
                          0,
                          _LoginBurstPainter.discRadius + 72 +
                              (1.0 - greet) * 24,
                        ),
                        child: Opacity(
                          opacity: greet,
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                widget.firstName.isEmpty
                                    ? l10n.loginWelcomeNoName
                                    : l10n.loginWelcome(widget.firstName),
                                textAlign: TextAlign.center,
                                style: TextStyle(
                                  color: AppColors.textPrimary,
                                  fontSize: AppFontSizes.display * 0.8,
                                  fontWeight: FontWeight.bold,
                                  letterSpacing: 1.5,
                                ),
                              ),
                              const SizedBox(height: AppSpacing.sm),
                              Container(
                                width: 200 * underline,
                                height: 6,
                                decoration: BoxDecoration(
                                  borderRadius: BorderRadius.circular(
                                    AppBorderRadius.full,
                                  ),
                                  gradient: const LinearGradient(
                                    colors: [
                                      AppColors.semanticSuccessLight,
                                      AppColors.brandCyan,
                                    ],
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}

/// Paints the whole badge scene from a single progress value: accent ripple
/// rings, star sparkles, the dark-glass badge with its brand rim, the golden
/// beer pour with wave, foam and carbonation, and the draw-on checkmark.
///
/// Everything is strokes, fills, gradients and one oval clip — no mask
/// filters, no blur, no save layers — so a frame costs the same on the
/// terminal's Pi as on a dev machine. The sparkle and bubble layouts are
/// deterministic fans, not `Random`: a painter must paint the same scene for
/// the same progress.
class _LoginBurstPainter extends CustomPainter {
  final double progress;

  /// Final radius of the badge.
  static const double discRadius = 84.0;

  /// When each ripple ring starts, as a fraction of the whole burst.
  static const _ringStarts = [0.0, 0.09, 0.18];

  /// How long one ring lives, as a fraction of the whole burst.
  static const _ringSpan = 0.55;

  static const _discPop = Interval(0.0, 0.2, curve: Curves.easeOutBack);
  static const _beerFill = Interval(0.14, 0.52, curve: Curves.easeInOutCubic);
  static const _checkDraw = Interval(0.44, 0.68, curve: Curves.easeOutCubic);

  /// How much of the badge the pour fills — headroom keeps the check legible
  /// against glass as well as beer.
  static const _fillFraction = 0.72;

  /// The logo's accent pair; rings alternate through it.
  static const _accents = [
    AppColors.semanticSuccessLight,
    AppColors.brandCyan,
  ];

  /// Star sparkles around the badge: angle (radians), distance (in badge
  /// radii), size (px), start (fraction of the burst). Positions echo the
  /// logo's stars — up and to the sides — and each twinkles once.
  static const _sparkles = [
    (angle: -2.2, dist: 1.75, size: 13.0, start: 0.08),
    (angle: -0.9, dist: 1.9, size: 10.0, start: 0.16),
    (angle: -2.9, dist: 2.3, size: 8.0, start: 0.24),
    (angle: -0.35, dist: 2.5, size: 12.0, start: 0.32),
    (angle: 2.6, dist: 2.1, size: 9.0, start: 0.4),
    (angle: 0.55, dist: 2.0, size: 11.0, start: 0.48),
  ];

  /// One twinkle's length, as a fraction of the whole burst.
  static const _sparkleSpan = 0.28;

  /// Carbonation bubbles inside the pour: horizontal offset (in badge radii),
  /// radius (px), start (fraction of the burst). Staggered so the glass never
  /// looks flat while the beer stands.
  static const _bubbles = [
    (dx: -0.45, r: 3.5, start: 0.24),
    (dx: -0.15, r: 2.5, start: 0.3),
    (dx: 0.2, r: 3.0, start: 0.27),
    (dx: 0.5, r: 2.0, start: 0.34),
    (dx: -0.32, r: 2.0, start: 0.42),
    (dx: 0.05, r: 3.5, start: 0.38),
    (dx: 0.36, r: 2.5, start: 0.46),
  ];

  /// One bubble's rise, as a fraction of the whole burst.
  static const _bubbleSpan = 0.4;

  const _LoginBurstPainter(this.progress);

  @override
  void paint(Canvas canvas, Size size) {
    final center = size.center(Offset.zero);
    final maxRadius = size.shortestSide * 0.55;

    _paintRings(canvas, center, maxRadius);
    _paintSparkles(canvas, center);

    final pop = _discPop.transform(progress.clamp(0.0, 1.0));
    if (pop > 0.0) {
      _paintBadge(canvas, center, discRadius * pop);
    }
    _paintCheck(canvas, center);
  }

  /// Ripple rings in the brand accents, like the card's field answering back.
  void _paintRings(Canvas canvas, Offset center, double maxRadius) {
    for (var i = 0; i < _ringStarts.length; i++) {
      final local = ((progress - _ringStarts[i]) / _ringSpan).clamp(0.0, 1.0);
      if (local <= 0.0 || local >= 1.0) continue;
      final eased = Curves.easeOut.transform(local);
      final radius = discRadius * 0.9 + (maxRadius - discRadius) * eased;
      canvas.drawCircle(
        center,
        radius,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 3.0 + 3.0 * (1.0 - local)
          ..color = _accents[i % _accents.length]
              .withValues(alpha: (1.0 - local) * 0.5),
      );
    }
  }

  /// Four-point star sparkles twinkling once each around the badge.
  void _paintSparkles(Canvas canvas, Offset center) {
    for (var i = 0; i < _sparkles.length; i++) {
      final spark = _sparkles[i];
      final local = ((progress - spark.start) / _sparkleSpan).clamp(0.0, 1.0);
      if (local <= 0.0 || local >= 1.0) continue;
      // In and back out: one twinkle.
      final twinkle = math.sin(local * math.pi);
      final pos = center +
          Offset(math.cos(spark.angle), math.sin(spark.angle)) *
              (discRadius * spark.dist);
      final s = spark.size * twinkle;
      final path = Path()
        ..moveTo(pos.dx, pos.dy - s)
        ..quadraticBezierTo(
            pos.dx + s * 0.18, pos.dy - s * 0.18, pos.dx + s, pos.dy)
        ..quadraticBezierTo(
            pos.dx + s * 0.18, pos.dy + s * 0.18, pos.dx, pos.dy + s)
        ..quadraticBezierTo(
            pos.dx - s * 0.18, pos.dy + s * 0.18, pos.dx - s, pos.dy)
        ..quadraticBezierTo(
            pos.dx - s * 0.18, pos.dy - s * 0.18, pos.dx, pos.dy - s)
        ..close();
      canvas.drawPath(
        path,
        Paint()
          ..color = (i.isEven ? _accents[i % _accents.length] : Colors.white)
              .withValues(alpha: 0.6 + 0.4 * twinkle),
      );
    }
  }

  /// The badge itself: halo, dark glass, brand rim, and the beer pour.
  void _paintBadge(Canvas canvas, Offset center, double radius) {
    final rect = Rect.fromCircle(center: center, radius: radius);

    // Cheap halo: a translucent fill, not a blur.
    canvas.drawCircle(
      center,
      radius * 1.28,
      Paint()
        ..color = AppColors.semanticSuccessLight.withValues(alpha: 0.1),
    );

    // Dark glass, like the logo's mug body behind the pour.
    canvas.drawCircle(
      center,
      radius,
      Paint()..color = AppColors.bgDeep.withValues(alpha: 0.92),
    );

    _paintBeer(canvas, center, radius);

    // Brand rim over the pour, so the beer reads as inside the glass.
    canvas.drawCircle(
      center,
      radius,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 4.0
        ..shader = const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: _accents,
        ).createShader(rect),
    );
  }

  /// Golden beer rising in the glass: wavy surface, foam line, carbonation.
  void _paintBeer(Canvas canvas, Offset center, double radius) {
    final fill = _beerFill.transform(progress.clamp(0.0, 1.0));
    if (fill <= 0.0) return;

    final bottom = center.dy + radius;
    final surface = bottom - 2 * radius * _fillFraction * fill;
    // The pour sloshes, then settles as the glass fills.
    final wavePhase = progress * math.pi * 6;
    final waveAmp = 5.0 * (1.0 - fill * 0.75);

    double waveY(double x) =>
        surface + math.sin(x / 22.0 + wavePhase) * waveAmp;

    canvas.save();
    canvas.clipPath(Path()
      ..addOval(Rect.fromCircle(center: center, radius: radius)));

    final left = center.dx - radius;
    final right = center.dx + radius;
    final beer = Path()..moveTo(left, waveY(left));
    for (var x = left + 8; x <= right; x += 8) {
      beer.lineTo(x, waveY(x));
    }
    beer
      ..lineTo(right, bottom)
      ..lineTo(left, bottom)
      ..close();
    canvas.drawPath(
      beer,
      Paint()
        ..shader = const LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [AppColors.brandBeerGold, AppColors.semanticPending],
        ).createShader(
          Rect.fromLTRB(left, surface - waveAmp, right, bottom),
        ),
    );

    // Foam line riding the surface.
    final foam = Path()..moveTo(left, waveY(left));
    for (var x = left + 8; x <= right; x += 8) {
      foam.lineTo(x, waveY(x));
    }
    canvas.drawPath(
      foam,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 6.0
        ..strokeCap = StrokeCap.round
        ..color = Colors.white.withValues(alpha: 0.85),
    );

    // Carbonation rising toward the foam.
    for (final bubble in _bubbles) {
      final local = ((progress - bubble.start) / _bubbleSpan).clamp(0.0, 1.0);
      if (local <= 0.0 || local >= 1.0) continue;
      final x = center.dx +
          bubble.dx * radius +
          math.sin(progress * math.pi * 4 + bubble.dx * 10) * 3;
      final y = bottom - 8 - (bottom - 8 - (surface + 10)) * local;
      if (y <= surface + 4) continue; // already reached the foam
      canvas.drawCircle(
        x.isFinite ? Offset(x, y) : Offset(center.dx, y),
        bubble.r,
        Paint()
          ..color = Colors.white.withValues(alpha: 0.5 * (1.0 - local * 0.5)),
      );
    }

    canvas.restore();
  }

  /// Foam-white checkmark drawing itself over the pour, with a whisper of
  /// navy underneath so it stays crisp against the gold.
  void _paintCheck(Canvas canvas, Offset center) {
    final check = _checkDraw.transform(progress.clamp(0.0, 1.0));
    if (check <= 0.0) return;

    const r = discRadius;
    final path = Path()
      ..moveTo(center.dx - 0.38 * r, center.dy + 0.02 * r)
      ..lineTo(center.dx - 0.10 * r, center.dy + 0.30 * r)
      ..lineTo(center.dx + 0.42 * r, center.dy - 0.26 * r);
    final metric = path.computeMetrics().first;
    final drawn = metric.extractPath(0.0, metric.length * check);

    canvas.drawPath(
      drawn,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 14.0
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round
        ..color = AppColors.bgPrimary.withValues(alpha: 0.28),
    );
    canvas.drawPath(
      drawn,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 9.0
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round
        ..color = Colors.white,
    );
  }

  @override
  bool shouldRepaint(_LoginBurstPainter oldDelegate) =>
      oldDelegate.progress != progress;
}
