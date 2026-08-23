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

/// One-shot login celebration: teal ripple rings burst outward from the scan
/// point, a green disc pops in with a draw-on checkmark and sparks, and the
/// member is greeted by name — then everything fades out over the products
/// screen already on the glass underneath.
///
/// Deliberately quick (about a second, [duration]) — it must feel like a
/// reward, never like a loading screen — and deliberately cheap: only
/// transforms and opacity, one [CustomPainter], no animated blur. A
/// [BoxShadow] blur animated per frame once pinned a core of the terminal's
/// Pi (see `RfidDetectorButton`); nothing here touches a mask filter at all.
///
/// With animations disabled ([MediaQuery.disableAnimationsOf]) the burst
/// skips itself entirely: the member lands on the products screen through the
/// normal route transition, just without the celebration.
class LoginBurst extends StatefulWidget {
  /// How long the whole celebration takes, fade-out included.
  static const Duration duration = Duration(milliseconds: 1100);

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
  static const _fadeOut = Interval(0.82, 1.0, curve: Curves.easeIn);

  /// Scrim fade-in — fast, so the burst pops against a calm background.
  static const _scrimIn = Interval(0.0, 0.12, curve: Curves.easeOut);

  /// Greeting slide-and-fade, once the checkmark is mostly drawn.
  static const _greetIn = Interval(0.34, 0.6, curve: Curves.easeOutCubic);

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
              final scrim = _scrimIn.transform(t) * 0.72;
              final greet = _greetIn.transform(t);

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
                        // Clear of the disc, sliding up into place.
                        offset: Offset(
                          0,
                          _LoginBurstPainter.discRadius + 64 +
                              (1.0 - greet) * 24,
                        ),
                        child: Opacity(
                          opacity: greet,
                          child: Text(
                            widget.firstName.isEmpty
                                ? l10n.loginWelcomeNoName
                                : l10n.loginWelcome(widget.firstName),
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              color: AppColors.textPrimary,
                              fontSize: AppFontSizes.display * 0.8,
                              fontWeight: FontWeight.bold,
                            ),
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

/// Paints the ripple rings, the popping success disc, the draw-on checkmark
/// and the spark dots, all from a single progress value.
///
/// Everything is strokes, fills and gradients — no mask filters, no blur —
/// so a frame costs the same on the terminal's Pi as on a dev machine.
class _LoginBurstPainter extends CustomPainter {
  final double progress;

  /// Final radius of the success disc.
  static const double discRadius = 78.0;

  /// When each ripple ring starts, as a fraction of the whole burst.
  static const _ringStarts = [0.0, 0.1, 0.2];

  /// How long one ring lives, as a fraction of the whole burst.
  static const _ringSpan = 0.62;

  static const _discPop = Interval(0.0, 0.26, curve: Curves.easeOutBack);
  static const _checkDraw = Interval(0.22, 0.52, curve: Curves.easeOutCubic);

  static const _sparkColors = [
    AppColors.accentTeal,
    AppColors.semanticSuccessLight,
    AppColors.semanticPrimaryLight,
  ];

  const _LoginBurstPainter(this.progress);

  @override
  void paint(Canvas canvas, Size size) {
    final center = size.center(Offset.zero);
    final maxRadius = size.shortestSide * 0.55;

    // Ripple rings, like the card's field answering back.
    for (final start in _ringStarts) {
      final local = ((progress - start) / _ringSpan).clamp(0.0, 1.0);
      if (local <= 0.0 || local >= 1.0) continue;
      final eased = Curves.easeOut.transform(local);
      final radius = discRadius * 0.9 + (maxRadius - discRadius) * eased;
      canvas.drawCircle(
        center,
        radius,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 3.0 + 3.0 * (1.0 - local)
          ..color = AppColors.accentTeal.withValues(alpha: (1.0 - local) * 0.5),
      );
    }

    // Spark dots flying outward between the rings. Deterministic layout — a
    // fixed fan with slight per-dot irregularity — so no Random in a painter.
    final sparkT = ((progress - 0.14) / 0.5).clamp(0.0, 1.0);
    if (sparkT > 0.0 && sparkT < 1.0) {
      final eased = Curves.easeOutCubic.transform(sparkT);
      const count = 10;
      for (var i = 0; i < count; i++) {
        final angle = (i / count) * 2 * math.pi + (i.isEven ? 0.35 : 0.0);
        final dist =
            discRadius + 20 + eased * (maxRadius * 0.55 + (i % 3) * 24);
        final pos = center + Offset(math.cos(angle), math.sin(angle)) * dist;
        canvas.drawCircle(
          pos,
          (i.isEven ? 5.0 : 3.5) * (1.0 - eased * 0.8),
          Paint()
            ..color = _sparkColors[i % _sparkColors.length]
                .withValues(alpha: (1.0 - sparkT) * 0.9),
        );
      }
    }

    // Success disc popping in (easeOutBack overshoots past 1 on purpose).
    final pop = _discPop.transform(progress.clamp(0.0, 1.0));
    if (pop > 0.0) {
      final radius = discRadius * pop;
      // Cheap halo: a translucent fill, not a blur.
      canvas.drawCircle(
        center,
        radius * 1.25,
        Paint()..color = AppColors.semanticSuccess.withValues(alpha: 0.12),
      );
      canvas.drawCircle(
        center,
        radius,
        Paint()
          ..shader = const LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [AppColors.semanticSuccess, AppColors.accentTeal],
          ).createShader(Rect.fromCircle(center: center, radius: radius)),
      );
    }

    // Checkmark drawing itself on.
    final check = _checkDraw.transform(progress.clamp(0.0, 1.0));
    if (check > 0.0) {
      const r = discRadius;
      final path = Path()
        ..moveTo(center.dx - 0.38 * r, center.dy + 0.02 * r)
        ..lineTo(center.dx - 0.10 * r, center.dy + 0.30 * r)
        ..lineTo(center.dx + 0.42 * r, center.dy - 0.26 * r);
      final metric = path.computeMetrics().first;
      canvas.drawPath(
        metric.extractPath(0.0, metric.length * check),
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 9.0
          ..strokeCap = StrokeCap.round
          ..strokeJoin = StrokeJoin.round
          ..color = Colors.white,
      );
    }
  }

  @override
  bool shouldRepaint(_LoginBurstPainter oldDelegate) =>
      oldDelegate.progress != progress;
}
