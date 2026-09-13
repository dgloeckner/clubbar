import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/removable_list.dart';
import '../test_helpers.dart';

/// Issue #921: a removed row leaves rather than blinking out.
///
/// The list is deliberately dumb about *why* a row went: the provider has
/// already removed it, and this widget plays out a snapshot. So the tests
/// drive it the way the cart screen does — by handing it a shorter list.
void main() {
  Widget host(
    List<String> items, {
    bool disableAnimations = false,
  }) =>
      createTestApp(
        child: MediaQuery(
          data: MediaQueryData(disableAnimations: disableAnimations),
          child: Scaffold(
            body: RemovableList<String>(
              items: items,
              keyOf: (item) => item,
              itemBuilder: (context, item) => SizedBox(
                height: 60,
                child: Text(item, key: ValueKey('row-$item')),
              ),
            ),
          ),
        ),
      );

  group('RemovableList', () {
    testWidgets('plays a removed row out and closes the gap', (tester) async {
      await tester.pumpWidget(host(['a', 'b', 'c']));
      await tester.pumpAndSettle();
      final untouched = tester.getTopLeft(find.byKey(const ValueKey('row-c')));

      await tester.pumpWidget(host(['a', 'c']));
      await tester.pump();

      // Mid-animation the row is still there and already shrinking.
      expect(find.byKey(const ValueKey('row-b')), findsOneWidget);
      await tester.pump(AppAnimations.lineExit ~/ 2);
      // The row itself still measures 60 — it is the box *around* it that is
      // collapsing, which is what makes the rows below move up.
      final collapsing = tester
          .getSize(find
              .ancestor(
                of: find.byKey(const ValueKey('row-b')),
                matching: find.byType(SizeTransition),
              )
              .first)
          .height;
      expect(collapsing, lessThan(60.0));

      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('row-b')), findsNothing);
      expect(find.byKey(const ValueKey('row-a')), findsOneWidget);
      // The gap closed: what was below has moved up.
      expect(tester.getTopLeft(find.byKey(const ValueKey('row-c'))).dy,
          lessThan(untouched.dy));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('a row on its way out takes no taps', (tester) async {
      await tester.pumpWidget(host(['a', 'b']));
      await tester.pumpAndSettle();

      await tester.pumpWidget(host(['a']));
      await tester.pump();

      final ignoring = find.ancestor(
        of: find.byKey(const ValueKey('row-b')),
        matching: find.byType(IgnorePointer),
      );
      expect(ignoring, findsWidgets);
      await tester.pumpAndSettle();
    });

    testWidgets('a cleared list plays nothing — checkout is not a parade',
        (tester) async {
      await tester.pumpWidget(host(['a', 'b', 'c']));
      await tester.pumpAndSettle();

      await tester.pumpWidget(host([]));
      await tester.pump();

      expect(find.byKey(const ValueKey('row-a')), findsNothing);
      expect(find.byKey(const ValueKey('row-b')), findsNothing);
      expect(find.byKey(const ValueKey('row-c')), findsNothing);
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('reduced motion removes the row on the very next frame',
        (tester) async {
      await tester.pumpWidget(host(['a', 'b'], disableAnimations: true));
      await tester.pumpAndSettle();

      await tester.pumpWidget(host(['a'], disableAnimations: true));
      await tester.pump();

      expect(find.byKey(const ValueKey('row-b')), findsNothing);
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('an idle list runs no animation', (tester) async {
      await tester.pumpWidget(host(['a', 'b']));
      await tester.pump(const Duration(seconds: 1));
      expect(tester.hasRunningAnimations, isFalse);
    });

    testWidgets('a row removed while another is still leaving is fine',
        (tester) async {
      await tester.pumpWidget(host(['a', 'b', 'c']));
      await tester.pumpAndSettle();

      await tester.pumpWidget(host(['a', 'c']));
      await tester.pump(AppAnimations.lineExit ~/ 3);
      await tester.pumpWidget(host(['a']));
      await tester.pump();

      expect(find.byKey(const ValueKey('row-b')), findsOneWidget);
      expect(find.byKey(const ValueKey('row-c')), findsOneWidget);

      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('row-a')), findsOneWidget);
      expect(find.byKey(const ValueKey('row-b')), findsNothing);
      expect(find.byKey(const ValueKey('row-c')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a list torn down mid-exit disposes cleanly', (tester) async {
      await tester.pumpWidget(host(['a', 'b']));
      await tester.pumpAndSettle();
      await tester.pumpWidget(host(['a']));
      await tester.pump(AppAnimations.lineExit ~/ 3);

      await tester.pumpWidget(
        createTestApp(child: const Scaffold(body: SizedBox.shrink())),
      );
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    });
  });
}
