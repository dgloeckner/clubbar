import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/widgets/counting_amount.dart';
import '../test_helpers.dart';

/// Issue #921: an amount that counts to its new value.
///
/// The assertions are about the *formatting contract* as much as the motion:
/// every frame goes through `formatPrice`, so a counting amount can never
/// drift from the separator and currency the rest of the terminal writes.
void main() {
  String shown(WidgetTester tester) =>
      tester.widget<Text>(find.byKey(const Key('amount'))).data!;

  Widget host(
    int cents, {
    int? startCents,
    Duration delay = Duration.zero,
    bool disableAnimations = false,
  }) =>
      createTestApp(
        child: MediaQuery(
          data: MediaQueryData(disableAnimations: disableAnimations),
          child: Scaffold(
            body: CountingAmount(
              cents: cents,
              startCents: startCents,
              delay: delay,
              format: (value) => formatPrice(value, 'de'),
              textKey: const Key('amount'),
            ),
          ),
        ),
      );

  group('CountingAmount', () {
    testWidgets('counts up between two values', (tester) async {
      await tester.pumpWidget(host(700, startCents: 350));
      await tester.pump();
      expect(shown(tester), equals(formatPrice(350, 'de')));

      await tester.pump(AppAnimations.countUp ~/ 2);
      final midway = shown(tester);
      expect(midway, isNot(equals(formatPrice(350, 'de'))));
      expect(midway, isNot(equals(formatPrice(700, 'de'))));

      await tester.pumpAndSettle();
      expect(shown(tester), equals(formatPrice(700, 'de')));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('counts down too', (tester) async {
      await tester.pumpWidget(host(700));
      await tester.pumpAndSettle();

      await tester.pumpWidget(host(200));
      await tester.pump(AppAnimations.countUp ~/ 2);
      final midway = shown(tester);
      expect(midway, isNot(equals(formatPrice(700, 'de'))));
      expect(midway, isNot(equals(formatPrice(200, 'de'))));

      await tester.pumpAndSettle();
      expect(shown(tester), equals(formatPrice(200, 'de')));
    });

    testWidgets('a new target mid-count continues from what is on screen',
        (tester) async {
      await tester.pumpWidget(host(1000, startCents: 0));
      await tester.pump();
      await tester.pump(AppAnimations.countUp ~/ 2);
      final atRetarget = shown(tester);

      // Another tap while the first count is still running.
      await tester.pumpWidget(host(1500));
      await tester.pump();
      // The very next frame still reads what it read a moment ago — it did
      // not snap back to where the previous count began.
      expect(shown(tester), equals(atRetarget));

      await tester.pumpAndSettle();
      expect(shown(tester), equals(formatPrice(1500, 'de')));
    });

    testWidgets('waits out its delay before it starts moving', (tester) async {
      await tester.pumpWidget(host(
        900,
        startCents: 100,
        delay: const Duration(milliseconds: 300),
      ));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 250));
      expect(shown(tester), equals(formatPrice(100, 'de')));

      // Past the delay, and one frame further so the controller has ticked.
      await tester.pump(const Duration(milliseconds: 100));
      await tester.pump(const Duration(milliseconds: 100));
      expect(shown(tester), isNot(equals(formatPrice(100, 'de'))));

      await tester.pumpAndSettle();
      expect(shown(tester), equals(formatPrice(900, 'de')));
    });

    testWidgets('reduced motion shows the target on the first frame',
        (tester) async {
      await tester.pumpWidget(
          host(700, startCents: 350, disableAnimations: true));
      await tester.pump();

      expect(shown(tester), equals(formatPrice(700, 'de')));
      expect(tester.hasRunningAnimations, isFalse);

      await tester.pumpWidget(host(1200, disableAnimations: true));
      await tester.pump();
      expect(shown(tester), equals(formatPrice(1200, 'de')));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('an unchanging amount runs no animation at all',
        (tester) async {
      await tester.pumpWidget(host(500));
      await tester.pump(const Duration(seconds: 1));

      expect(shown(tester), equals(formatPrice(500, 'de')));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('uses tabular figures so its neighbours never reflow',
        (tester) async {
      await tester.pumpWidget(host(150));
      await tester.pump();

      final style =
          tester.widget<Text>(find.byKey(const Key('amount'))).style!;
      expect(style.fontFeatures, contains(const FontFeature.tabularFigures()));
    });
  });
}
