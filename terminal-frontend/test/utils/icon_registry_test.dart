import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/utils/icon_registry.dart';

void main() {
  group('getProductIcon', () {
    testWidgets('unknown icon name renders the neutral glyph, not a beer', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(
        MaterialApp(home: getProductIcon('no-such-icon')),
      );

      expect(find.byType(SvgPicture), findsNothing);
      expect(find.byIcon(Icons.shopping_bag_outlined), findsOneWidget);
    });

    testWidgets('null icon name renders the neutral glyph', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(MaterialApp(home: getProductIcon(null)));

      expect(find.byType(SvgPicture), findsNothing);
      expect(find.byIcon(Icons.shopping_bag_outlined), findsOneWidget);
    });

    testWidgets('known canonical name still resolves to an SVG asset', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(MaterialApp(home: getProductIcon('beer-pils')));

      expect(find.byType(SvgPicture), findsOneWidget);
      expect(find.byIcon(Icons.shopping_bag_outlined), findsNothing);
    });

    testWidgets('known legacy name still resolves to an SVG asset', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(MaterialApp(home: getProductIcon('PilsIcon')));

      expect(find.byType(SvgPicture), findsOneWidget);
      expect(find.byIcon(Icons.shopping_bag_outlined), findsNothing);
    });
  });

  group('getCategoryIcon', () {
    testWidgets('unknown icon name renders the neutral category glyph', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(
        MaterialApp(home: getCategoryIcon('no-such-icon')),
      );

      expect(find.byType(SvgPicture), findsNothing);
      expect(find.byIcon(Icons.category), findsOneWidget);
    });

    testWidgets('known name still resolves to an SVG asset', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(MaterialApp(home: getCategoryIcon('beer-pils')));

      expect(find.byType(SvgPicture), findsOneWidget);
      expect(find.byIcon(Icons.category), findsNothing);
    });

    // Issue #299: a category chip used to be able to wear only a product's
    // icon (e.g. Sauna wore a "sauna token" coin), because the category
    // lookup simply delegated to the product one with no dedicated set.
    group('dedicated category icons (#299)', () {
      for (final name in [
        'category-folder',
        'category-tags',
        'category-layers',
        'category-list',
        'category-generic',
      ]) {
        testWidgets('"$name" resolves to a dedicated category asset', (
          WidgetTester tester,
        ) async {
          await tester.pumpWidget(MaterialApp(home: getCategoryIcon(name)));

          expect(find.byType(SvgPicture), findsOneWidget);
          expect(find.byIcon(Icons.category), findsNothing);
        });
      }

      testWidgets(
          'a product-icon name set on a category still resolves (existing deployments)',
          (WidgetTester tester) async {
        await tester.pumpWidget(
          MaterialApp(home: getCategoryIcon('sauna-token')),
        );

        expect(find.byType(SvgPicture), findsOneWidget);
        expect(find.byIcon(Icons.category), findsNothing);
      });
    });
  });
}
