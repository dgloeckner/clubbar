import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/widgets/cart_flight.dart';
import 'package:clubbar_terminal/widgets/cart_summary_bar.dart';
import '../test_helpers.dart';

/// Issue #921: the running total is the one number a member watches while
/// they tap, so it counts to its new value and pops when an icon lands in it.
///
/// The bar is on screen for the whole session, so the quiescence rule (#760)
/// matters here as much as anywhere: with nothing landing and nothing
/// changing, it must run no animation at all.
void main() {
  Widget bar({
    required int totalCents,
    Listenable? landingSignal,
    GlobalKey? totalKey,
  }) =>
      createTestApp(
        child: Scaffold(
          body: Align(
            alignment: Alignment.bottomCenter,
            child: CartSummaryBar(
              totalCents: totalCents,
              locale: 'de',
              isCartEmpty: totalCents == 0,
              isCheckoutInFlight: false,
              isBlockedByLimit: false,
              landingSignal: landingSignal,
              totalKey: totalKey,
              onCheckout: () async {},
              onViewCart: () {},
            ),
          ),
        ),
      );

  String total(WidgetTester tester) => tester
      .widget<Text>(find.byKey(const Key('cart-summary-total')))
      .data!;

  double totalScale(WidgetTester tester) => tester
      .widget<ScaleTransition>(find.descendant(
        of: find.byType(PopOnSignal),
        matching: find.byType(ScaleTransition),
      ))
      .scale
      .value;

  group('CartSummaryBar', () {
    testWidgets('runs no animation while nothing is happening',
        (tester) async {
      await tester.pumpWidget(bar(totalCents: 350));
      await tester.pump(const Duration(seconds: 1));

      expect(total(tester), formatPrice(350, 'de'));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('counts to a new total instead of jumping', (tester) async {
      await tester.pumpWidget(bar(totalCents: 350));
      await tester.pumpAndSettle();

      await tester.pumpWidget(bar(totalCents: 700));
      await tester.pump();
      expect(total(tester), formatPrice(350, 'de'));

      await tester.pump(AppAnimations.countUp ~/ 2);
      expect(total(tester), isNot(formatPrice(350, 'de')));
      expect(total(tester), isNot(formatPrice(700, 'de')));

      await tester.pumpAndSettle();
      expect(total(tester), formatPrice(700, 'de'));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('pops when something lands in it', (tester) async {
      final signal = CartLandingSignal();
      addTearDown(signal.dispose);

      await tester.pumpWidget(bar(totalCents: 350, landingSignal: signal));
      await tester.pumpAndSettle();
      expect(totalScale(tester), closeTo(1.0, 0.001));

      signal.landed();
      await tester.pump();
      await tester.pump(AppAnimations.amountPop ~/ 2);
      expect(totalScale(tester), greaterThan(1.0));

      await tester.pumpAndSettle();
      expect(totalScale(tester), closeTo(1.0, 0.001));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('hands the screen somewhere to aim a flight at',
        (tester) async {
      final key = GlobalKey();
      await tester.pumpWidget(bar(totalCents: 350, totalKey: key));
      await tester.pumpAndSettle();

      final box = key.currentContext!.findRenderObject() as RenderBox;
      expect(box.hasSize, isTrue);
      // The target is the total itself, inside the bar.
      final target = box.localToGlobal(Offset.zero) & box.size;
      expect(tester.getRect(find.byType(CartSummaryBar)).contains(target.center),
          isTrue);
    });
  });
}
