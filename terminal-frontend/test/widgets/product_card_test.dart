import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/styled_components/product_card.dart';
import '../test_helpers.dart';

void main() {
  ProductsCacheData product({int requiresDispenser = 0}) => ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Sauna-Token'}),
        descriptions: null,
        priceCents: 200,
        isActive: 1,
        requiresDispenser: requiresDispenser,
        iconName: 'SaunaTokenIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      );

  Future<void> pumpCard(
    WidgetTester tester, {
    required VoidCallback onTap,
    bool enabled = true,
    String? unavailableNote,
  }) {
    return tester.pumpWidget(
      createTestApp(
        child: Scaffold(
          body: SizedBox(
            width: 240,
            height: 240,
            child: ProductCard(
              product: product(requiresDispenser: 1),
              productName: 'Sauna-Token',
              locale: 'de',
              onTap: onTap,
              enabled: enabled,
              unavailableNote: unavailableNote,
            ),
          ),
        ),
      ),
    );
  }

  group('ProductCard', () {
    testWidgets('an enabled card adds the product on tap',
        (WidgetTester tester) async {
      var taps = 0;
      await pumpCard(tester, onTap: () => taps++);

      await tester.tap(find.byType(ProductCard));
      await tester.pumpAndSettle();

      expect(taps, equals(1));
    });

    // Issue #31: a token whose dispenser is offline stays on the grid so the
    // member can see it exists, but it must not reach the cart.
    testWidgets('a disabled card ignores taps', (WidgetTester tester) async {
      var taps = 0;
      await pumpCard(
        tester,
        onTap: () => taps++,
        enabled: false,
        unavailableNote: 'Token-Ausgabe zurzeit nicht verfügbar',
      );

      await tester.tap(find.byType(ProductCard));
      await tester.pumpAndSettle();

      expect(taps, equals(0));
    });

    testWidgets('a disabled card explains why it cannot be bought',
        (WidgetTester tester) async {
      await pumpCard(
        tester,
        onTap: () {},
        enabled: false,
        unavailableNote: 'Token-Ausgabe zurzeit nicht verfügbar',
      );

      expect(find.text('Token-Ausgabe zurzeit nicht verfügbar'), findsOneWidget);
      expect(find.text('Sauna-Token'), findsOneWidget);
    });

    testWidgets('an enabled card shows no unavailability note',
        (WidgetTester tester) async {
      await pumpCard(tester, onTap: () {});

      expect(
        find.text('Token-Ausgabe zurzeit nicht verfügbar'),
        findsNothing,
      );
    });
  });

  // Member feedback: product names were too small. The name was `xl` under a
  // price at `xxl` — the amount louder than the thing it is the amount for —
  // on a 7" panel read standing up.
  group('ProductCard name is the tile\'s headline (member feedback)', () {
    testWidgets('the name is set at least as large as the price',
        (WidgetTester tester) async {
      await pumpCard(tester, onTap: () {});

      final name = tester.widget<Text>(find.text('Sauna-Token')).style!;
      final price = tester
          .widget<Text>(find.textContaining('2,00'))
          .style!;

      // On its own the card sets the floor; the grid hands it the size it
      // solved for the category.
      expect(name.fontSize, AppFontSizes.productNameFloor);
      expect(name.fontSize!, greaterThanOrEqualTo(price.fontSize!),
          reason: 'a member picks by name; the price is read second');
      expect(name.fontWeight, FontWeight.w700);
    });
  });
}
