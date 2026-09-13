import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter_test/flutter_test.dart';
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

    // Mid-draw the controller is still ticking…
    await tester.pump(const Duration(milliseconds: 200));
    expect(tester.binding.transientCallbackCount, greaterThan(0));

    // …and once the last stroke has landed, nothing is left running.
    await tester.pumpAndSettle();
    expect(tester.binding.transientCallbackCount, 0);
  });

  testWidgets('five strokes finish inside 1.2 s (#921 motion budget)',
      (tester) async {
    expect(
      BeerMat.drawDuration(5).inMilliseconds,
      lessThanOrEqualTo(1200),
    );
    // Fifteen is the cap, and even that stays inside the receipt's dwell.
    expect(BeerMat.drawDuration(15).inMilliseconds, lessThan(3000));
    expect(BeerMat.drawDuration(0), Duration.zero);

    await pumpMat(tester, size: 260, strokes: 5);
    await tester.pump(BeerMat.drawDuration(5));
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
