import 'dart:ui' show Rect;

import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/utils/tally.dart';

/// Issue #929, move 2. The mat's geometry is a pure function, so these are
/// the coaster's goldens: the shape of one stroke, of a closed gate of five
/// and of three full gates, pinned by their coordinates rather than by a
/// screenshot. A pixel golden of a hand-drawn pencil line would fail on a
/// font hint or an anti-aliasing change and say nothing about the layout.
void main() {
  const area = Rect.fromLTRB(56, 118, 184, 196);

  group('tallyStrokeCount', () {
    test('sums quantities, it does not count lines', () {
      // "2 × Helles, 2 × Wasser, 1 × Brezel" is five strokes, not three.
      expect(tallyStrokeCount([2, 2, 1]), 5);
      expect(tallyStrokeCount([1]), 1);
    });

    test('sixteen items draw fifteen', () {
      expect(tallyStrokeCount([16]), 15);
      expect(tallyStrokeCount([10, 10, 10]), 15);
      expect(kTallyMaxStrokes, 15);
    });

    test('an empty or nonsensical receipt draws nothing', () {
      expect(tallyStrokeCount(const []), 0);
      expect(tallyStrokeCount([0, -3]), 0);
    });
  });

  group('tallyStrokes', () {
    test('nothing to draw is no strokes at all', () {
      expect(tallyStrokes(0, into: area), isEmpty);
      expect(tallyStrokes(-1, into: area), isEmpty);
    });

    test('one item is one upright, centred, at full size', () {
      final strokes = tallyStrokes(1, into: area);
      expect(strokes, hasLength(1));

      final stroke = strokes.single;
      expect(stroke.isGate, isFalse);
      // Vertical, centred in the pencil area, and not scaled up: a lone
      // stroke stays a stroke rather than becoming a fence post.
      expect(stroke.start.dx, closeTo(area.center.dx, 0.01));
      expect(stroke.end.dx, closeTo(area.center.dx, 0.01));
      expect(stroke.end.dy - stroke.start.dy, closeTo(52, 0.01));
      expect((stroke.start.dy + stroke.end.dy) / 2,
          closeTo(area.center.dy, 0.01));
    });

    test('five items are four uprights closed by a diagonal, drawn last', () {
      final strokes = tallyStrokes(5, into: area);
      expect(strokes, hasLength(5));

      expect(strokes.take(4).every((s) => !s.isGate), isTrue);
      expect(strokes.last.isGate, isTrue);

      // Uprights left to right, evenly spaced.
      final xs = strokes.take(4).map((s) => s.start.dx).toList();
      for (var i = 1; i < xs.length; i++) {
        expect(xs[i] - xs[i - 1], closeTo(20, 0.01));
      }

      // The diagonal runs bottom-left to top-right and overhangs the
      // uprights on both sides — the way a pencil actually crosses a gate.
      final gate = strokes.last;
      expect(gate.start.dx, lessThan(xs.first));
      expect(gate.end.dx, greaterThan(xs.last));
      expect(gate.end.dy, lessThan(gate.start.dy));
    });

    test('a short last group keeps its uprights and gets no diagonal', () {
      // Seven: one closed gate, then two lonely strokes.
      final strokes = tallyStrokes(7, into: area);
      expect(strokes, hasLength(7));
      expect(strokes.where((s) => s.isGate), hasLength(1));
      expect(strokes.indexWhere((s) => s.isGate), 4);
    });

    test('fifteen is three gates, two rows, still inside the mat', () {
      final strokes = tallyStrokes(15, into: area);
      expect(strokes, hasLength(15));
      expect(strokes.where((s) => s.isGate), hasLength(3));

      // Two rows: the tops of the fifteen strokes take exactly two values.
      final tops = strokes
          .where((s) => !s.isGate)
          .map((s) => s.start.dy.toStringAsFixed(2))
          .toSet();
      expect(tops, hasLength(2));

      for (final stroke in strokes) {
        for (final p in [stroke.start, stroke.end]) {
          expect(area.inflate(0.01).contains(p), isTrue,
              reason: 'stroke escaped the pencil area at $p');
        }
      }
    });

    test('the layout is deterministic — the same mat twice over', () {
      expect(
        tallyStrokes(9, into: area).map((s) => s.toString()).toList(),
        tallyStrokes(9, into: area).map((s) => s.toString()).toList(),
      );
    });
  });
}
