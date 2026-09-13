import 'dart:math' as math;
import 'dart:ui' show Offset, Rect;

/// One pencil stroke on the Bierdeckel: where it starts and where it ends,
/// in whatever coordinate space [tallyStrokes] was asked to fit.
class TallyStroke {
  const TallyStroke({
    required this.start,
    required this.end,
    required this.isGate,
  });

  final Offset start;
  final Offset end;

  /// Whether this is the diagonal that closes a gate of five. Drawn last
  /// within its group, the way a Deckel is actually kept.
  final bool isGate;

  @override
  String toString() => 'TallyStroke($start → $end, gate: $isGate)';
}

/// The most strokes a mat will ever draw — three closed gates.
///
/// A round a person carries from a bar does not exceed this; a cart of thirty
/// sauna tokens is a dispenser sale, not an evening, and three closed gates
/// say "a lot" well enough. Beyond it the mat stops rather than shrinking to
/// illegibility.
const int kTallyMaxStrokes = 15;

/// How many strokes a receipt's [quantities] come to.
///
/// **Quantities, not lines.** "2 × Helles" is two strokes; the member bought
/// two beers and the mat says two. Negative or nonsensical quantities are
/// floored at zero, and the whole is capped at [kTallyMaxStrokes].
int tallyStrokeCount(Iterable<int> quantities) {
  var total = 0;
  for (final q in quantities) {
    if (q > 0) total += q;
  }
  return math.min(total, kTallyMaxStrokes);
}

/// Natural geometry, before the fit. One gate is four uprights of
/// [_uprightHeight] spaced [_uprightGap] apart, closed by a diagonal that
/// overhangs [_diagonalOverhang] on each side — the proportions of the
/// mockup's mat, so the fitted result matches the design at one gate.
const double _uprightHeight = 52;
const double _uprightGap = 20;
const double _diagonalOverhang = 12;

/// Air between two gates on the same row, and between two rows.
const double _groupGap = 20;
const double _rowGap = 16;

/// Gates per row. Three gates side by side would be wider than the mat's
/// waist; two and then one below reads as a Deckel that has been kept a
/// while, which is exactly what fifteen strokes means.
const int _groupsPerRow = 2;

/// The strokes for [count] items, laid out as gates of five and fitted into
/// [into].
///
/// The layout is built at its natural size, then scaled down uniformly to fit
/// and centred — never scaled *up*, so a single stroke stays a stroke rather
/// than becoming a fence post. Strokes come back in drawing order: within a
/// group the uprights left to right, then the diagonal.
///
/// Pure geometry with no randomness and no clock, so a test can pin it and a
/// painter can call it every frame without allocating surprises.
List<TallyStroke> tallyStrokes(int count, {required Rect into}) {
  final n = count.clamp(0, kTallyMaxStrokes);
  if (n == 0) return const [];

  // Gates of five, the last one short if the count does not divide.
  final groups = <int>[];
  for (var left = n; left > 0; left -= 5) {
    groups.add(math.min(5, left));
  }

  final rows = <List<int>>[];
  for (var i = 0; i < groups.length; i += _groupsPerRow) {
    rows.add(groups.sublist(i, math.min(i + _groupsPerRow, groups.length)));
  }

  // Natural bounds, so the fit can be computed before anything is placed.
  final rowWidths = rows.map(_rowWidth).toList();
  final naturalWidth = rowWidths.reduce(math.max);
  final naturalHeight =
      rows.length * _uprightHeight + (rows.length - 1) * _rowGap;

  final scale = math.min(
    1.0,
    math.min(into.width / naturalWidth, into.height / naturalHeight),
  );
  final originY = into.center.dy - (naturalHeight * scale) / 2;

  final strokes = <TallyStroke>[];
  for (var r = 0; r < rows.length; r++) {
    final top = originY + r * (_uprightHeight + _rowGap) * scale;
    var x = into.center.dx - (rowWidths[r] * scale) / 2;
    for (final group in rows[r]) {
      strokes.addAll(_group(group, left: x, top: top, scale: scale));
      x += (_groupWidth(group) + _groupGap) * scale;
    }
  }
  return strokes;
}

/// Natural width of a group of [k] strokes (1..5).
double _groupWidth(int k) => k >= 5
    // Four uprights plus the diagonal's overhang on both sides.
    ? 3 * _uprightGap + 2 * _diagonalOverhang
    : (k - 1) * _uprightGap;

double _rowWidth(List<int> groups) =>
    groups.map(_groupWidth).reduce((a, b) => a + b) +
    (groups.length - 1) * _groupGap;

/// One group placed at ([left], [top]), scaled by [scale].
List<TallyStroke> _group(
  int k, {
  required double left,
  required double top,
  required double scale,
}) {
  final h = _uprightHeight * scale;
  final gap = _uprightGap * scale;
  final over = _diagonalOverhang * scale;
  // A closed gate's uprights start one overhang in, so the diagonal's ends
  // land outside them the way a pencil actually crosses a gate.
  final firstUpright = k >= 5 ? left + over : left;

  final uprights = math.min(k, 4);
  final strokes = <TallyStroke>[
    for (var i = 0; i < uprights; i++)
      TallyStroke(
        start: Offset(firstUpright + i * gap, top),
        end: Offset(firstUpright + i * gap, top + h),
        isGate: false,
      ),
  ];
  if (k >= 5) {
    // Bottom-left to top-right, drawn last: the stroke that closes the gate.
    strokes.add(TallyStroke(
      start: Offset(left, top + h * 0.88),
      end: Offset(left + _groupWidth(5) * scale, top + h * 0.12),
      isGate: true,
    ));
  }
  return strokes;
}
