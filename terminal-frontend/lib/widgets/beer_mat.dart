import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'package:clubbar_terminal/utils/tally.dart';

/// The club's Bierdeckel: a cardboard coaster with a printed tally motif and,
/// when a booking has just landed, tonight's items pencilled onto it (#929,
/// move 2).
///
/// German has the idiom ready — *das kommt auf den Deckel*, *einen Strich
/// machen* — and the app already calls the tab a Deckel, so the receipt draws
/// the object the word names instead of a green tick. The printed gates above
/// the fold are obviously *print*: part of the coaster's artwork, in the
/// cardboard's own faded ink. Only the pencil below them says anything, and
/// what it says is one stroke per item on **this** receipt.
///
/// It claims nothing about the month. That is deliberate and load-bearing: a
/// stroke count the receipt cannot vouch for would be read as a billing error
/// at a bar, and the euro figure beside the mat remains the only figure for
/// the tab.
///
/// Cheap by construction, the way #921 requires of the buying loop: one
/// [CustomPainter], strokes and fills only, no blur, no mask filter, no save
/// layer. The pencil animates by shortening each line towards its start —
/// the moral equivalent of the mockup's `stroke-dashoffset` — staggered
/// [_strokeStagger] apart, so five strokes finish inside 1.2 s. Under reduced
/// motion ([MediaQuery.maybeDisableAnimationsOf]) the mat renders finished on
/// its first frame and starts no ticker at all.
class BeerMat extends StatefulWidget {
  const BeerMat({
    required this.size,
    this.strokes = 0,
    this.rimText,
    super.key,
  });

  /// Edge length of the (square) coaster.
  final double size;

  /// How many pencil strokes to draw. Zero is the bare coaster — what the
  /// member bar wears beside the balance, printed motif and nothing else.
  ///
  /// Callers pass [tallyStrokeCount], which sums quantities and caps at
  /// [kTallyMaxStrokes]; the painter clamps again rather than trusting it.
  final int strokes;

  /// The club's name, printed around the rim (`instance_name`, ADR-0034).
  ///
  /// Ignored below [_rimTextMinSize] — the small mat on the member bar is a
  /// glyph, and type that cannot be read is just texture.
  final String? rimText;

  /// How long each stroke takes to draw…
  static const Duration strokeDraw = Duration(milliseconds: 340);

  /// …and how much later than its predecessor each one starts. Five strokes
  /// therefore land in 4 × 120 + 340 = 820 ms.
  static const Duration strokeStagger = Duration(milliseconds: 120);

  /// Below this edge length the rim text is dropped.
  static const double _rimTextMinSize = 140;

  /// How long a mat of [strokes] takes to draw itself out.
  static Duration drawDuration(int strokes) {
    final n = strokes.clamp(0, kTallyMaxStrokes);
    if (n == 0) return Duration.zero;
    return strokeStagger * (n - 1) + strokeDraw;
  }

  @override
  State<BeerMat> createState() => _BeerMatState();
}

// A plain [TickerProviderStateMixin], not the Single variant: a mat whose
// stroke count changes — the same member scanning again on the receipt
// screen — throws the old controller away and builds a new one, which is a
// second ticker from this State's point of view.
class _BeerMatState extends State<BeerMat> with TickerProviderStateMixin {
  AnimationController? _controller;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _startIfNeeded();
  }

  @override
  void didUpdateWidget(BeerMat oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.strokes != widget.strokes) {
      _controller?.dispose();
      _controller = null;
      _startIfNeeded();
    }
  }

  /// A ticker only where there is something to animate: no strokes, or
  /// reduced motion, means no controller and therefore no frames — the same
  /// discipline #760 holds the idle screen to.
  void _startIfNeeded() {
    if (_controller != null) return;
    if (widget.strokes <= 0) return;
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) return;

    _controller = AnimationController(
      duration: BeerMat.drawDuration(widget.strokes),
      vsync: this,
    )..forward();
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final controller = _controller;
    _BeerMatPainter painterFor(double t) => _BeerMatPainter(
          strokes: widget.strokes,
          progress: t,
          rimText: widget.size >= BeerMat._rimTextMinSize
              ? widget.rimText?.trim()
              : null,
        );

    return SizedBox(
      width: widget.size,
      height: widget.size,
      child: controller == null
          // Finished on the first frame: nothing to draw, or reduced motion.
          ? CustomPaint(painter: painterFor(1))
          : AnimatedBuilder(
              animation: controller,
              builder: (context, _) => CustomPaint(
                painter: painterFor(controller.value),
              ),
            ),
    );
  }
}

/// Cardboard, print ink and pencil.
///
/// The colours are the mockup's, and they are the one place in the terminal
/// that does not come from [AppColors]: a beer mat is a physical object in
/// its own material, not a surface in the app's dark palette, and tinting it
/// navy would make it read as a card rather than as cardboard.
const Color _matCard = Color(0xffe6d7b0);
const Color _matRim = Color(0xffc7b48a);
const Color _matRimFaint = Color(0xffcdbd96);
const Color _matGlassRing = Color(0x29785014);
const Color _matPrintInk = Color(0xffbfae86);
const Color _matPencil = Color(0xff1e2a44);
const Color _matRimText = Color(0xff8a7448);

/// Everything below is laid out in the mockup's own 240 × 240 space and
/// scaled to the widget, so the design's proportions survive any size.
const double _designSize = 240;
const Rect _printArea = Rect.fromLTRB(58, 60, 182, 106);
const Rect _pencilArea = Rect.fromLTRB(56, 118, 184, 196);

/// The printed motif: a closed gate and three more uprights — a Deckel
/// somebody else has been keeping, which is what a coaster's artwork is.
const int _printStrokes = 8;

class _BeerMatPainter extends CustomPainter {
  _BeerMatPainter({
    required this.strokes,
    required this.progress,
    required this.rimText,
  });

  final int strokes;

  /// 0 → 1 across the whole staggered draw.
  final double progress;
  final String? rimText;

  @override
  void paint(Canvas canvas, Size size) {
    final scale = size.shortestSide / _designSize;
    canvas.save();
    canvas.scale(scale);

    const centre = Offset(_designSize / 2, _designSize / 2);
    _paintCardboard(canvas, centre);
    if (rimText != null && rimText!.isNotEmpty) _paintRimText(canvas, centre);
    _paintTally(
      canvas,
      tallyStrokes(_printStrokes, into: _printArea),
      colour: _matPrintInk,
      width: 5,
      fraction: (_) => 1,
      jitter: false,
    );
    if (strokes > 0) _paintPencil(canvas);

    canvas.restore();
  }

  void _paintCardboard(Canvas canvas, Offset centre) {
    canvas.drawCircle(centre, 118, Paint()..color = _matCard);

    final rim = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..color = _matRim;
    canvas.drawCircle(centre, 118, rim);

    // The dashed guide ring, drawn as short arcs: a dashed stroke has no
    // primitive in Flutter, and 96 two-degree ticks are cheaper than a
    // path effect would be.
    final dash = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5
      ..color = _matRim;
    const ticks = 96;
    for (var i = 0; i < ticks; i++) {
      final from = i * 2 * math.pi / ticks;
      canvas.drawArc(
        Rect.fromCircle(center: centre, radius: 108),
        from,
        2 * math.pi / ticks * 0.45,
        false,
        dash,
      );
    }

    canvas.drawCircle(
      centre,
      84,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1
        ..color = _matRimFaint,
    );

    // The ring a wet glass left, off-centre because a glass is put down
    // where it lands.
    canvas.drawCircle(
      const Offset(146, 104),
      52,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 7
        ..color = _matGlassRing,
    );
  }

  /// The club's name around the top of the rim, one glyph at a time.
  ///
  /// Curved type has no shortcut in Flutter: each character is laid out on
  /// its own and the canvas rotated under it. A club name is short and the
  /// mat repaints only while the pencil runs, so the cost is bounded — but
  /// this is why [BeerMat._rimTextMinSize] exists, rather than doing it for
  /// a 20 px glyph nobody can read.
  void _paintRimText(Canvas canvas, Offset centre) {
    final text = rimText!.toUpperCase();
    const radius = 103.0;
    const style = TextStyle(
      color: _matRimText,
      fontSize: 10.5,
      fontWeight: FontWeight.w700,
      letterSpacing: 3.2,
      height: 1,
    );

    final painters = <TextPainter>[];
    var arc = 0.0;
    for (final char in text.characters) {
      final tp = TextPainter(
        text: TextSpan(text: char, style: style),
        textDirection: TextDirection.ltr,
      )..layout();
      painters.add(tp);
      arc += tp.width / radius;
    }
    if (painters.isEmpty) return;

    canvas.save();
    canvas.translate(centre.dx, centre.dy);
    // Centred on top dead centre, reading clockwise.
    canvas.rotate(-math.pi / 2 - arc / 2);
    for (final tp in painters) {
      final step = tp.width / radius;
      canvas.rotate(step / 2);
      canvas.save();
      canvas.translate(0, -radius);
      canvas.rotate(math.pi / 2);
      tp.paint(canvas, Offset(-tp.width / 2, -tp.height / 2));
      canvas.restore();
      canvas.rotate(step / 2);
    }
    canvas.restore();
  }

  /// Tonight's items, in pencil, each one growing from its start point.
  void _paintPencil(Canvas canvas) {
    final drawn = tallyStrokes(strokes, into: _pencilArea);
    final totalMs = BeerMat.drawDuration(strokes).inMilliseconds;
    final elapsed = progress * totalMs;
    _paintTally(
      canvas,
      drawn,
      colour: _matPencil,
      width: 6.5,
      jitter: true,
      fraction: (i) {
        final start = BeerMat.strokeStagger.inMilliseconds * i;
        final t = (elapsed - start) / BeerMat.strokeDraw.inMilliseconds;
        return Curves.easeOutCubic.transform(t.clamp(0.0, 1.0));
      },
    );
  }

  /// Draws [strokes], each shortened to its own [fraction] of the way from
  /// start to end.
  ///
  /// [jitter] gives the pencil its crookedness — a fixed wobble derived from
  /// the stroke's index, never a random one: a mat that redrew itself
  /// differently every frame would shimmer.
  void _paintTally(
    Canvas canvas,
    List<TallyStroke> strokes, {
    required Color colour,
    required double width,
    required double Function(int index) fraction,
    required bool jitter,
  }) {
    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round
      ..strokeWidth = width
      ..color = colour;

    for (var i = 0; i < strokes.length; i++) {
      final t = fraction(i);
      if (t <= 0) continue;
      final stroke = strokes[i];
      final wobble = jitter ? Offset(((i * 7) % 5 - 2) * 0.6, 0) : Offset.zero;
      final start = stroke.start + wobble;
      final end = stroke.end - wobble;
      canvas.drawLine(start, Offset.lerp(start, end, t)!, paint);
    }
  }

  @override
  bool shouldRepaint(_BeerMatPainter old) =>
      old.strokes != strokes ||
      old.progress != progress ||
      old.rimText != rimText;
}
