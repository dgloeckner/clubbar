import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:clubbar_terminal/app/kiosk_scroll_behavior.dart';
import 'package:clubbar_terminal/utils/kiosk_touch.dart';

/// Why these tests exist.
///
/// Members had to tap a product tile two or three times before it reached the
/// cart. The tile was innocent: a scrollable ancestor was claiming the
/// gesture as a drag, which cancels the tap. Two independent causes, one per
/// group below — both regress silently, because a lost tap looks exactly like
/// a tap that was never made.

/// Scroll metrics for a viewport whose content fits: nothing to scroll.
class _FittingMetrics extends FixedScrollMetrics {
  _FittingMetrics()
      : super(
          minScrollExtent: 0.0,
          maxScrollExtent: 0.0,
          pixels: 0.0,
          viewportDimension: 800.0,
          axisDirection: AxisDirection.down,
          devicePixelRatio: 1.0,
        );
}

void main() {
  group('KioskScrollBehavior', () {
    testWidgets('does not claim gestures when there is nothing to scroll',
        (tester) async {
      late ScrollPhysics physics;
      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) {
              physics = KioskScrollBehavior().getScrollPhysics(context);
              return const SizedBox.shrink();
            },
          ),
        ),
      );

      // AlwaysScrollableScrollPhysics would return true here, keeping a drag
      // recognizer in the arena on a grid that cannot move at all.
      expect(physics.shouldAcceptUserOffset(_FittingMetrics()), isFalse);
    });

    testWidgets('still scrolls a viewport whose content overflows',
        (tester) async {
      late ScrollPhysics physics;
      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) {
              physics = KioskScrollBehavior().getScrollPhysics(context);
              return const SizedBox.shrink();
            },
          ),
        ),
      );

      final overflowing = FixedScrollMetrics(
        minScrollExtent: 0.0,
        maxScrollExtent: 400.0,
        pixels: 0.0,
        viewportDimension: 800.0,
        axisDirection: AxisDirection.down,
        devicePixelRatio: 1.0,
      );
      expect(physics.shouldAcceptUserOffset(overflowing), isTrue);
    });

    testWidgets('drives finger-drag scrolling, not just mouse', (tester) async {
      expect(KioskScrollBehavior().dragDevices,
          contains(PointerDeviceKind.touch));
    });
  });

  group('kKioskTouchSlop', () {
    // A tap on a kiosk panel drifts as the finger rolls off the glass.
    // Flutter's phone-calibrated 18 px is less than that drift, so the grid
    // used to swallow ordinary taps as scrolls.
    const drift = 25.0;

    /// A scrolling grid of tap targets, with [slop] as the platform slop.

    Widget grid(double? slop, void Function(int) onTap) {
      // One MediaQuery, carrying both: nesting a second one below would
      // replace the data wholesale and drop the gesture settings again.
      var data = const MediaQueryData(size: Size(800, 600));
      if (slop != null) {
        data = data.copyWith(
          gestureSettings: DeviceGestureSettings(touchSlop: slop),
        );
      }
      return MediaQuery(
        data: data,
        child: Directionality(
          textDirection: TextDirection.ltr,
          child: ScrollConfiguration(
            behavior: KioskScrollBehavior(),
            child: GridView.count(
              crossAxisCount: 2,
              // Taller than the viewport, so the grid genuinely scrolls and
              // its drag recognizer is in the arena.
              children: List.generate(
                12,
                (i) => GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onTapUp: (_) => onTap(i),
                  child: Center(child: Text('tile $i')),
                ),
              ),
            ),
          ),
        ),
      );
    }

    testWidgets('is larger than the phone default it replaces', (tester) async {
      expect(kKioskTouchSlop, greaterThan(kTouchSlop));
    });

    testWidgets('a tap that drifts is lost at the Flutter default',
        (tester) async {
      final tapped = <int>[];
      await tester.pumpWidget(grid(kTouchSlop, tapped.add));

      final gesture = await tester.startGesture(tester.getCenter(find.text('tile 0')));
      await gesture.moveBy(const Offset(0, drift));
      await gesture.up();
      await tester.pump();

      // This is the bug, reproduced: the scroll took the gesture.
      expect(tapped, isEmpty);
    });

    testWidgets('the same tap lands at the kiosk slop', (tester) async {
      final tapped = <int>[];
      await tester.pumpWidget(grid(kKioskTouchSlop, tapped.add));

      final gesture = await tester.startGesture(tester.getCenter(find.text('tile 0')));
      await gesture.moveBy(const Offset(0, drift));
      await gesture.up();
      await tester.pump();

      expect(tapped, [0]);
    });

    testWidgets('a stationary tap lands, as it always did', (tester) async {
      final tapped = <int>[];
      await tester.pumpWidget(grid(kKioskTouchSlop, tapped.add));

      await tester.tap(find.text('tile 1'));
      await tester.pump();

      expect(tapped, [1]);
    });

    testWidgets('a deliberate swipe still scrolls at the kiosk slop',
        (tester) async {
      final tapped = <int>[];
      await tester.pumpWidget(grid(kKioskTouchSlop, tapped.add));

      // A finger moving across frames, not one teleporting move: the drag
      // recognizer has to out-argue the tile's tap recognizer in the arena,
      // and that is decided per pointer-move event.
      final gesture = await tester.startGesture(
        tester.getCenter(find.byType(GridView)),
      );
      for (var i = 0; i < 4; i++) {
        await gesture.moveBy(const Offset(0, -50));
        await tester.pump();
      }
      await gesture.up();
      await tester.pumpAndSettle();

      final scrolled = tester
          .state<ScrollableState>(find.byType(Scrollable))
          .position
          .pixels;
      expect(scrolled, greaterThan(0.0));
      expect(tapped, isEmpty);
    });
  });
}
