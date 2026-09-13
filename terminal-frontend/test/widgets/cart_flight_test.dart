import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/cart_flight.dart';
import '../test_helpers.dart';

/// Issue #921: the tap's receipt — a copy of the tapped icon arcs into the
/// running total.
///
/// The assertions here are the constraints the issue calls non-negotiable:
/// the sprite starts where the icon was, it ends inside the bar, it is gone
/// when its duration is up, it never takes a tap, and reduced motion launches
/// nothing at all.
void main() {
  const from = Rect.fromLTWH(100, 500, 60, 60);
  const to = Rect.fromLTWH(600, 700, 80, 40);

  /// A host with a button under the whole screen, so "does the sprite eat a
  /// tap" is answerable rather than assumed.
  Widget host({
    required void Function(BuildContext context) onReady,
    bool disableAnimations = false,
    VoidCallback? onTapBelow,
  }) {
    return createTestApp(
      child: MediaQuery(
        data: MediaQueryData(disableAnimations: disableAnimations),
        child: Scaffold(
          body: Builder(
            builder: (context) => GestureDetector(
              onTap: onTapBelow,
              behavior: HitTestBehavior.opaque,
              child: Center(
                child: ElevatedButton(
                  onPressed: () => onReady(context),
                  child: const Text('launch'),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Finder sprite() => find.byKey(const Key('cart-flight'));

  /// The pop's current scale. Scoped to the widget under test: a `Scaffold`
  /// brings its own [ScaleTransition] (the floating action button's), which a
  /// bare `find.byType` would pick up as well.
  double popScale(WidgetTester tester, Type popType) {
    return tester
        .widget<ScaleTransition>(find.descendant(
          of: find.byType(popType),
          matching: find.byType(ScaleTransition),
        ))
        .scale
        .value;
  }

  /// Where the sprite's icon is drawn right now — the centre of the
  /// [Positioned] the builder emits.
  Offset spriteCentre(WidgetTester tester) {
    final box = tester.renderObject<RenderBox>(
      find.descendant(of: sprite(), matching: find.byType(Opacity)).first,
    );
    return box.localToGlobal(box.size.center(Offset.zero));
  }

  group('CartFlight', () {
    testWidgets('launches one sprite that travels from the tile to the bar',
        (tester) async {
      var landed = 0;
      await tester.pumpWidget(host(
        onReady: (context) => CartFlight.launch(
          context,
          from: from,
          to: to,
          onLanded: () => landed++,
        ),
      ));

      await tester.tap(find.text('launch'));
      await tester.pump();

      expect(sprite(), findsOneWidget);
      // The first frame is the icon still on the tile.
      expect((spriteCentre(tester) - from.center).distance, lessThan(4.0));

      // Three quarters of the way through it is much closer to the bar than
      // to the tile — the arc's shape is not asserted, only that it travels.
      await tester.pump(AppAnimations.cartFlight * 0.75);
      final travelling = spriteCentre(tester);
      expect((travelling - to.center).distance,
          lessThan((travelling - from.center).distance));

      // The number reacts as the icon disappears *into* it, not after: the
      // landing is announced before the flight is over.
      await tester.pump(AppAnimations.cartFlight * 0.1);
      expect(landed, equals(1));

      await tester.pump(AppAnimations.cartFlight);
      await tester.pumpAndSettle();
      expect(sprite(), findsNothing);
      expect(landed, equals(1));
      expect(tester.hasRunningAnimations, isFalse);
      expect(CartFlight.inFlight, equals(0));
    });

    testWidgets('never takes a tap meant for the screen underneath',
        (tester) async {
      var tapsBelow = 0;
      await tester.pumpWidget(host(
        onTapBelow: () => tapsBelow++,
        onReady: (context) =>
            CartFlight.launch(context, from: from, to: to),
      ));

      await tester.tap(find.text('launch'));
      await tester.pump();
      expect(sprite(), findsOneWidget);

      final ignorePointer = tester.widget<IgnorePointer>(
        find.descendant(of: sprite(), matching: find.byType(IgnorePointer)),
      );
      expect(ignorePointer.ignoring, isTrue);

      // A tap mid-flight lands on the screen, not on the decoration.
      await tester.tapAt(const Offset(20, 20));
      await tester.pump();
      expect(tapsBelow, equals(1));

      await tester.pumpAndSettle();
    });

    testWidgets('caps how many sprites are in the air at once', (tester) async {
      var launched = 0;
      await tester.pumpWidget(host(
        onReady: (context) {
          for (var i = 0; i < CartFlight.maxInFlight + 4; i++) {
            if (CartFlight.launch(context, from: from, to: to)) launched++;
          }
        },
      ));

      await tester.tap(find.text('launch'));
      await tester.pump();

      expect(launched, equals(CartFlight.maxInFlight));
      expect(sprite(), findsNWidgets(CartFlight.maxInFlight));

      await tester.pumpAndSettle();
      expect(sprite(), findsNothing);
      expect(CartFlight.inFlight, equals(0));
    });

    testWidgets('reduced motion launches nothing and says so', (tester) async {
      bool? result;
      var landed = 0;
      await tester.pumpWidget(host(
        disableAnimations: true,
        onReady: (context) => result = CartFlight.launch(
          context,
          from: from,
          to: to,
          onLanded: () => landed++,
        ),
      ));

      await tester.tap(find.text('launch'));
      await tester.pump();

      expect(result, isFalse);
      expect(sprite(), findsNothing);
      expect(landed, equals(0));

      // Nothing was scheduled — the only thing that settles here is the
      // button's own ink response.
      await tester.pumpAndSettle();
      expect(sprite(), findsNothing);
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('a sprite outliving its screen neither throws nor leaks',
        (tester) async {
      await tester.pumpWidget(host(
        onReady: (context) =>
            CartFlight.launch(context, from: from, to: to),
      ));
      await tester.tap(find.text('launch'));
      await tester.pump();
      expect(sprite(), findsOneWidget);

      // The route underneath goes away mid-flight — a logout, a scan by the
      // next member, a trip to /cart.
      await tester.pumpWidget(createTestApp(
        child: const Scaffold(body: SizedBox.shrink()),
      ));
      await tester.pumpAndSettle();

      expect(sprite(), findsNothing);
      expect(tester.takeException(), isNull);
      expect(CartFlight.inFlight, equals(0));
    });
  });

  group('PopOnSignal', () {
    testWidgets('runs no animation until something lands', (tester) async {
      final signal = CartLandingSignal();
      addTearDown(signal.dispose);

      await tester.pumpWidget(createTestApp(
        child: Scaffold(
          body: PopOnSignal(signal: signal, child: const Text('12,00 €')),
        ),
      ));
      await tester.pump(const Duration(seconds: 1));
      expect(tester.hasRunningAnimations, isFalse);

      signal.landed();
      await tester.pump();
      await tester.pump(AppAnimations.amountPop ~/ 2);

      expect(popScale(tester, PopOnSignal), greaterThan(1.0));

      await tester.pumpAndSettle();
      expect(popScale(tester, PopOnSignal), closeTo(1.0, 0.001));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('reduced motion never pops', (tester) async {
      final signal = CartLandingSignal();
      addTearDown(signal.dispose);

      await tester.pumpWidget(createTestApp(
        child: MediaQuery(
          data: const MediaQueryData(disableAnimations: true),
          child: Scaffold(
            body: PopOnSignal(signal: signal, child: const Text('12,00 €')),
          ),
        ),
      ));

      signal.landed();
      await tester.pump();
      await tester.pump(AppAnimations.amountPop ~/ 2);

      expect(popScale(tester, PopOnSignal), closeTo(1.0, 0.001));
      expect(tester.hasRunningAnimations, isFalse);
    });
  });

  group('PopOnChange', () {
    Widget counter(int value, {bool disableAnimations = false}) =>
        createTestApp(
          child: MediaQuery(
            data: MediaQueryData(disableAnimations: disableAnimations),
            child: Scaffold(
              body: PopOnChange(value: value, child: Text('${value}x')),
            ),
          ),
        );

    testWidgets('bumps when the number changes, and not before',
        (tester) async {
      await tester.pumpWidget(counter(1));
      await tester.pump(const Duration(seconds: 1));
      expect(tester.hasRunningAnimations, isFalse);

      await tester.pumpWidget(counter(2));
      await tester.pump();
      await tester.pump(AppAnimations.amountPop ~/ 2);
      expect(popScale(tester, PopOnChange), greaterThan(1.0));

      await tester.pumpAndSettle();
      expect(popScale(tester, PopOnChange), closeTo(1.0, 0.001));
    });

    testWidgets('reduced motion never bumps', (tester) async {
      await tester.pumpWidget(counter(1, disableAnimations: true));
      await tester.pumpWidget(counter(2, disableAnimations: true));
      await tester.pump();
      await tester.pump(AppAnimations.amountPop ~/ 2);

      expect(popScale(tester, PopOnChange), closeTo(1.0, 0.001));
      expect(tester.hasRunningAnimations, isFalse);
    });
  });
}
