import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/styled_components/product_card.dart';
import '../test_helpers.dart';

void main() {
  ProductsCacheData product({int requiresDispenser = 0, int? volumeMl}) =>
      ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: jsonEncode({'de': 'Sauna-Token'}),
        descriptions: null,
        priceCents: 200,
        isActive: 1,
        requiresDispenser: requiresDispenser,
        iconName: 'SaunaTokenIcon',
        volumeMl: volumeMl,
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

  /// The card as the grid hands it to a tile, in a box of the height the
  /// solver computed for that name size — which is how a real row is laid out.
  Future<void> pumpTile(
    WidgetTester tester, {
    required String name,
    int? volumeMl,
    double nameFontSize = 26,
  }) {
    const metrics = ProductCard.metrics;
    final priceFontSize =
        metrics.priceFontSize(nameFontSize, AppFontSizes.xxl);

    return tester.pumpWidget(
      createTestApp(
        child: Scaffold(
          body: SizedBox(
            width: 240,
            height: metrics.tileHeight(
                nameFontSize, AppFontSizes.xxl, nameFontSize),
            child: ProductCard(
              product: product(volumeMl: volumeMl),
              productName: name,
              locale: 'de',
              onTap: () {},
              nameFontSize: nameFontSize,
              priceFontSize: priceFontSize,
              volumeFontSize: metrics.volumeFontSize(nameFontSize),
              iconSize: metrics.iconSize(nameFontSize, nameFontSize),
            ),
          ),
        ),
      ),
    );
  }

  /// Where the price sits in the tile — the number this whole arrangement
  /// exists to hold steady.
  double priceTop(WidgetTester tester) =>
      tester.getTopLeft(find.textContaining('2,00')).dy;

  /// The regression from the branch this work is stacked on. A one-line name
  /// used to render shorter than a two-line one, and with the column centred
  /// that shifted the price below it — prices at different heights across a
  /// row. Commit 2b4d50b5 fixed it by pinning the name box; #878 replaces the
  /// second name line with a **reserved volume row**, which has to hold the
  /// same invariant or the fix is undone.
  group('ProductCard keeps every price at the same height', () {
    testWidgets('a product with a volume and one without agree',
        (WidgetTester tester) async {
      await pumpTile(tester, name: 'Weizenbier', volumeMl: 500);
      final withVolume = priceTop(tester);

      await pumpTile(tester, name: 'Weizenbier', volumeMl: null);
      final withoutVolume = priceTop(tester);

      expect(withoutVolume, closeTo(withVolume, 0.01),
          reason: 'the volume row is reserved whether or not it is filled');
    });

    testWidgets('a short name and a long one agree',
        (WidgetTester tester) async {
      await pumpTile(tester, name: 'Cola', volumeMl: 330);
      final short = priceTop(tester);

      await pumpTile(tester, name: 'Apfelschorle', volumeMl: 330);
      final long = priceTop(tester);

      expect(long, closeTo(short, 0.01));
    });

    testWidgets('a volume and a long name together still agree',
        (WidgetTester tester) async {
      // Both variations at once, which is the row a real category produces.
      await pumpTile(tester, name: 'Cola', volumeMl: null);
      final plain = priceTop(tester);

      await pumpTile(tester, name: 'Apfelschorle', volumeMl: 1500);
      final both = priceTop(tester);

      expect(both, closeTo(plain, 0.01));
    });
  });

  /// The badge itself (ADR-0056).
  group('ProductCard volume badge', () {
    testWidgets('draws the size in the member\'s own notation',
        (WidgetTester tester) async {
      await pumpTile(tester, name: 'Weizenbier', volumeMl: 500);

      // German: a decimal comma, and a no-break space before the unit.
      expect(find.text('0,5\u00a0l'), findsOneWidget);
    });

    testWidgets('draws nothing at all for a product with no size',
        (WidgetTester tester) async {
      await pumpTile(tester, name: 'Sauna-Token', volumeMl: null);

      // Not a dash, not an empty pill: a Sauna-Token simply has no size.
      expect(find.textContaining(' l'), findsNothing);
      expect(find.textContaining(' ml'), findsNothing);
    });

    testWidgets('is quieter than the name and the price',
        (WidgetTester tester) async {
      await pumpTile(tester, name: 'Weizenbier', volumeMl: 500);

      final badge = tester.widget<Text>(find.text('0,5\u00a0l')).style!;
      final name = tester.widget<Text>(find.text('Weizenbier')).style!;
      final price = tester.widget<Text>(find.textContaining('2,00')).style!;

      // The member finds the drink by name, checks the price, and confirms the
      // size last — so the badge must not compete with either.
      expect(badge.fontSize!, lessThan(name.fontSize!));
      expect(badge.fontSize!, lessThan(price.fontSize!));
      expect(badge.color, AppColors.textSecondary);
    });
  });

  /// The price is the loudest thing on the tile now (#878), and it is loud
  /// through its pill rather than by outgrowing the name — #369's finding that
  /// a member picks by name still holds.
  group('ProductCard price pill', () {
    testWidgets('sits on its own fill, with a border, in the tinted cyan',
        (WidgetTester tester) async {
      await pumpTile(tester, name: 'Weizenbier', volumeMl: 500);

      final price = tester.widget<Text>(find.textContaining('2,00')).style!;
      expect(price.color, AppColors.infoOnTint);
      expect(price.fontWeight, FontWeight.w900);

      final pill = tester.widget<Container>(
        find
            .ancestor(
              of: find.textContaining('2,00'),
              matching: find.byType(Container),
            )
            .first,
      );
      final decoration = pill.decoration as BoxDecoration;
      expect(decoration.color, AppColors.bgPricePill);
      expect(decoration.border, isNotNull);
    });

    testWidgets('grows with the name once 0.9 x the name clears its floor',
        (WidgetTester tester) async {
      // At the floor the price sits on `xxl`; at the ceiling it is 0.9 x the
      // name, which is the recommendation #878 left open for review.
      await pumpTile(tester, name: 'Cola', nameFontSize: 46.5);
      final atCeiling =
          tester.widget<Text>(find.textContaining('2,00')).style!.fontSize!;

      await pumpTile(tester, name: 'Cola', nameFontSize: 20);
      final atFloor =
          tester.widget<Text>(find.textContaining('2,00')).style!.fontSize!;

      expect(atCeiling, closeTo(0.9 * 46.5, 1e-9));
      expect(atFloor, AppFontSizes.xxl,
          reason: 'never below the price floor');
    });
  });
}
