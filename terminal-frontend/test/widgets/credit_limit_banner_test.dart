import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/credit_limit_banner.dart';

import '../test_helpers.dart';

/// The banner had no test file of its own until #369 — it was covered only
/// indirectly, through the two screens that place it. That is why nothing
/// noticed it was 91 px tall for two lines that fit on one, and nothing would
/// have noticed its amounts line wrapping without bound at a large type scale.
void main() {
  /// The club's shipped policy — €100.00 with an 80 % band — which is what a
  /// terminal enforces before its first `/sync/config` poll (ADR-0046).
  const policy = CreditLimitPolicy.shipped;

  Future<void> pumpBanner(
    WidgetTester tester, {
    required int balanceCents,
    required int cartCents,
    int? memberLimitCents,
    Size surface = const Size(1280, 400),
    String locale = 'de',
  }) async {
    tester.view.physicalSize = surface;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      createTestApp(
        child: Scaffold(
          body: Column(
            children: [
              CreditLimitBanner(
                check: policy.evaluate(
                  memberLimitCents: memberLimitCents,
                  currentBalanceCents: balanceCents,
                  cartTotalCents: cartCents,
                ),
                locale: locale,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Finder banner() => find.byKey(const Key('credit-limit-banner'));

  group('CreditLimitBanner', () {
    testWidgets('renders nothing while the tab is inside the limit',
        (tester) async {
      await pumpBanner(tester, balanceCents: 1000, cartCents: 250);

      // The contract the screens rely on: they place it unconditionally.
      expect(banner(), findsNothing);
    });

    testWidgets('warns on approach and names all three amounts',
        (tester) async {
      await pumpBanner(tester, balanceCents: 8210, cartCents: 350);

      expect(banner(), findsOneWidget);
      expect(find.text('Du näherst dich deinem Limit.'), findsOneWidget);
      expect(find.textContaining('Aktueller Betrag: 82,10'), findsOneWidget);
      expect(find.textContaining('Warenkorb: 3,50'), findsOneWidget);
      expect(find.textContaining('Maximum: 100,00'), findsOneWidget);
    });

    testWidgets('says what to do once the limit is passed', (tester) async {
      await pumpBanner(tester, balanceCents: 9900, cartCents: 350);

      expect(
        find.text('Limit erreicht – bitte Artikel entfernen, um fortzufahren.'),
        findsOneWidget,
      );
    });

    // #369. The saving is the single line: two lines cost 91 px of a screen
    // whose product grid was already 19 px short of a second row.
    //
    // The surface is 2600, not the kiosk's 1280, and that is a property of the
    // harness rather than of the widget. flutter_test renders in Ahem, whose
    // every glyph is a full em square, so this copy measures roughly twice what
    // a real proportional font gives it — the crossover width where the two
    // runs stop sharing a line is far to the right of where a kiosk sees it.
    // What is being pinned here is the packing behaviour and the one-line
    // height; the production width at which it holds is a separate claim, and
    // the honest place for it is the layout assertion in
    // product_selection_screen_test.dart, which measures the whole screen.
    testWidgets('puts the headline and the amounts on one line when they fit',
        (tester) async {
      // The *blocked* copy, which is the long one — if anything wraps, it is
      // this.
      await pumpBanner(
        tester,
        balanceCents: 9900,
        cartCents: 350,
        surface: const Size(2600, 400),
      );

      final headline = tester.getCenter(
        find.text('Limit erreicht – bitte Artikel entfernen, um fortzufahren.'),
      );
      final amounts = tester.getCenter(
        find.textContaining('Aktueller Betrag:'),
      );

      expect(headline.dy, amounts.dy);
      // One line, not two: the whole point of the change. Two lines at this
      // scale is ~75.
      expect(tester.getSize(banner()).height, lessThan(60));
    });

    // ...and why it is a Wrap and not a Row. A Row here would be a RenderFlex
    // overflow on any narrower kiosk.
    testWidgets('stacks instead of overflowing when they do not fit',
        (tester) async {
      await pumpBanner(
        tester,
        balanceCents: 9900,
        cartCents: 350,
        surface: const Size(640, 400),
      );

      final headline = tester.getCenter(
        find.text('Limit erreicht – bitte Artikel entfernen, um fortzufahren.'),
      );
      final amounts = tester.getCenter(
        find.textContaining('Aktueller Betrag:'),
      );

      expect(headline.dy, lessThan(amounts.dy));
      expect(tester.takeException(), isNull);
    });

    // The type scale is a deployment setting (AppFontSizes.applyConfig), so
    // "it fits" has to hold for every scale a club can dial in, at every
    // width — not just the one this was measured against (#41, #369).
    group('survives any configured type scale', () {
      final scales = <Map<String, double>>[
        {
          'xs': 12.0, 'sm': 13.0, 'base': 14.0,
          'lg': 16.0, 'xl': 18.0, 'xxl': 20.0, 'xxxl': 24.0,
        },
        {
          'xs': 14.0, 'sm': 15.0, 'base': 16.0,
          'lg': 18.0, 'xl': 20.0, 'xxl': 24.0, 'xxxl': 28.0,
        },
        {
          'xs': 16.0, 'sm': 17.0, 'base': 20.0,
          'lg': 22.0, 'xl': 24.0, 'xxl': 26.0, 'xxxl': 30.0,
        },
      ];

      for (final scale in scales) {
        for (final width in [640.0, 1024.0, 1280.0, 1920.0]) {
          for (final blocked in [false, true]) {
            testWidgets(
                'lg ${scale['lg']} at ${width.toInt()}px, '
                '${blocked ? 'blocked' : 'approaching'}', (tester) async {
              final shipped = {
                'xs': AppFontSizes.xs,
                'sm': AppFontSizes.sm,
                'base': AppFontSizes.base,
                'lg': AppFontSizes.lg,
                'xl': AppFontSizes.xl,
                'xxl': AppFontSizes.xxl,
                'xxxl': AppFontSizes.xxxl,
              };
              addTearDown(() => AppFontSizes.applyConfig(shipped));
              AppFontSizes.applyConfig(scale);

              await pumpBanner(
                tester,
                balanceCents: blocked ? 9900 : 8210,
                cartCents: 350,
                surface: Size(width, 400),
              );

              expect(banner(), findsOneWidget);
              expect(tester.takeException(), isNull);
            });
          }
        }
      }
    });

    testWidgets('formats money in the member\'s language', (tester) async {
      await pumpBanner(
        tester,
        balanceCents: 8210,
        cartCents: 350,
        locale: 'en',
      );

      expect(find.textContaining('€82.10'), findsOneWidget);
    });
  });

  /// The banner is measured against the member's *own* ceiling, so it says the
  /// same thing the checkout button and the service-side refusal say
  /// (ADR-0046). A banner reading from the club default while the service
  /// enforced an override would warn the wrong member — or worse, warn nobody
  /// and then refuse at the till.
  group('a member with a ceiling of their own', () {
    testWidgets('is warned inside their own band, not the club one',
        (tester) async {
      // 4,200 of 5,000 is past their 80 % band; against the club's 10,000 it
      // would be silent.
      await pumpBanner(
        tester,
        balanceCents: 4000,
        cartCents: 200,
        memberLimitCents: 5000,
      );

      expect(banner(), findsOneWidget);
    });

    testWidgets('is left alone below their own band', (tester) async {
      await pumpBanner(
        tester,
        balanceCents: 9000,
        cartCents: 500,
        memberLimitCents: 20000,
      );

      expect(banner(), findsNothing,
          reason: 'their ceiling is 200 €, so a 95 € tab is nowhere near it');
    });

    testWidgets('says nothing at all to a member with no ceiling',
        (tester) async {
      await pumpBanner(
        tester,
        balanceCents: 500000,
        cartCents: 100000,
        memberLimitCents: 0,
      );

      expect(banner(), findsNothing);
    });
  });
}
