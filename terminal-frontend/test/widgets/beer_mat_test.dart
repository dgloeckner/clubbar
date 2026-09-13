import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/config/app_config.dart';
import 'package:clubbar_terminal/utils/tally.dart';
import 'package:clubbar_terminal/widgets/beer_mat.dart';

/// Issue #929, move 2. The mat's *geometry* is pinned in
/// `test/utils/tally_test.dart`; what is left to hold here is the widget's
/// behaviour — that it draws itself out once and then stops, that reduced
/// motion skips the pencil entirely, and that a bare mat starts no ticker at
/// all (the discipline #760 holds the idle screen to, applied to a widget the
/// member bar wears for a whole session).
void main() {
  Future<void> pumpMat(
    WidgetTester tester, {
    required double size,
    int strokes = 0,
    String? rimText,
    bool disableAnimations = false,
  }) {
    return tester.pumpWidget(
      MediaQuery(
        data: MediaQueryData(disableAnimations: disableAnimations),
        child: Directionality(
          textDirection: TextDirection.ltr,
          child: Center(
            child: BeerMat(size: size, strokes: strokes, rimText: rimText),
          ),
        ),
      ),
    );
  }

  testWidgets('takes exactly the size it is given', (tester) async {
    await pumpMat(tester, size: 260, strokes: 5, rimText: 'FRGS Ruderbar');
    await tester.pumpAndSettle();

    expect(tester.getSize(find.byType(BeerMat)), const Size(260, 260));
  });

  testWidgets('a bare mat produces no frames', (tester) async {
    // What the member bar wears: printed motif, no pencil. A ticker here
    // would run for as long as a session lasts, for nothing.
    await pumpMat(tester, size: 20);
    await tester.pump();

    expect(SchedulerBinding.instance.hasScheduledFrame, isFalse);
    expect(tester.binding.transientCallbackCount, 0);
  });

  testWidgets('the pencil draws itself out, then stops', (tester) async {
    await pumpMat(tester, size: 260, strokes: 5);

    // Mid-stroke the controller is still ticking…
    await tester.pump(BeerMat.pencilLeadIn + const Duration(milliseconds: 100));
    expect(tester.binding.transientCallbackCount, greaterThan(0));

    // …and once the last stroke has landed, nothing is left running.
    await tester.pumpAndSettle();
    expect(tester.binding.transientCallbackCount, 0);
  });

  test('one pencil: strokes are drawn one after another, never two at once',
      () {
    final starts = BeerMat.strokeStarts(5);
    final strokes = tallyStrokes(5, into: const Rect.fromLTWH(0, 0, 100, 100));

    // A beat first, so the eye is on the mat before anything moves.
    expect(starts.first, BeerMat.pencilLeadIn);
    for (var i = 1; i < starts.length; i++) {
      expect(
        starts[i] - starts[i - 1],
        BeerMat.strokeLength(strokes[i - 1]) + BeerMat.strokeLift,
        reason: 'stroke $i starts once stroke ${i - 1} is down '
            'and the hand has lifted',
      );
    }
    expect(
      BeerMat.drawDuration(5),
      starts.last + BeerMat.gateDraw,
      reason: 'the diagonal is drawn last, and the mat is done when it is',
    );
  });

  test('the diagonal is the longer line, and takes longer', () {
    expect(BeerMat.gateDraw, greaterThan(BeerMat.strokeDraw));
    final strokes = tallyStrokes(5, into: const Rect.fromLTWH(0, 0, 100, 100));
    expect(strokes.last.isGate, isTrue);
    expect(BeerMat.strokeLength(strokes.last), BeerMat.gateDraw);
    expect(BeerMat.strokeLength(strokes.first), BeerMat.strokeDraw);
  });

  testWidgets("even fifteen strokes finish inside the receipt's dwell",
      (tester) async {
    // Slower than the buying loop's motion, and allowed to be: nothing waits
    // on the pencil, a tap dismisses the receipt mid-draw, and even the cap
    // lands before the receipt would leave on its own.
    expect(
      BeerMat.drawDuration(15),
      lessThan(AppConfig.receiptAutoReturnDelay),
    );
    expect(BeerMat.drawDuration(0), Duration.zero);

    await pumpMat(tester, size: 260, strokes: 15);
    await tester.pump(BeerMat.drawDuration(15));
    await tester.pumpAndSettle();
    expect(tester.binding.transientCallbackCount, 0);
  });

  testWidgets('reduced motion skips the pencil and starts no ticker',
      (tester) async {
    await pumpMat(tester, size: 260, strokes: 15, disableAnimations: true);
    await tester.pump();

    expect(SchedulerBinding.instance.hasScheduledFrame, isFalse);
    expect(tester.binding.transientCallbackCount, 0);
    // Nothing is mid-animation: the mat is simply finished.
    expect(tester.getSize(find.byType(BeerMat)), const Size(260, 260));
  });

  testWidgets('a changed stroke count redraws from the start',
      (tester) async {
    await pumpMat(tester, size: 260, strokes: 1);
    await tester.pumpAndSettle();
    expect(tester.binding.transientCallbackCount, 0);

    await pumpMat(tester, size: 260, strokes: 5);
    await tester.pump(const Duration(milliseconds: 100));
    expect(tester.binding.transientCallbackCount, greaterThan(0));
    await tester.pumpAndSettle();
  });

  testWidgets('renders at every size without overflowing or throwing',
      (tester) async {
    for (final size in [20.0, 120.0, 260.0]) {
      for (final strokes in [0, 1, 5, 15]) {
        await pumpMat(
          tester,
          size: size,
          strokes: strokes,
          rimText: 'FRGS Ruderbar',
          disableAnimations: true,
        );
        await tester.pump();
        expect(tester.takeException(), isNull,
            reason: 'size $size, $strokes strokes');
      }
    }
  });
}
