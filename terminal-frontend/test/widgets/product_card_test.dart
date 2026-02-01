import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/widgets/product_card.dart';

void main() {
  group('ProductCard', () {
    late ProductDTO testProduct;

    setUp(() {
      testProduct = ProductDTO(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: {'de': 'Bier', 'en': 'Beer'},
        priceCents: 550,
        isActive: true,
        updatedAt: '2024-01-01T00:00:00Z',
      );
    });

    testWidgets('displays product name', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProductCard(
              product: testProduct,
              translatedName: 'Bier',
              onAddPressed: () {},
            ),
          ),
        ),
      );

      expect(find.text('Bier'), findsOneWidget);
    });

    testWidgets('displays price formatted correctly', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProductCard(
              product: testProduct,
              translatedName: 'Bier',
              onAddPressed: () {},
            ),
          ),
        ),
      );

      expect(find.text('€5.50'), findsOneWidget);
    });

    testWidgets('has add button that calls onAddPressed', (WidgetTester tester) async {
      var pressed = false;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProductCard(
              product: testProduct,
              translatedName: 'Bier',
              onAddPressed: () { pressed = true; },
            ),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.add_circle));
      expect(pressed, isTrue);
    });

    testWidgets('displays product image placeholder', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProductCard(
              product: testProduct,
              translatedName: 'Bier',
              onAddPressed: () {},
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.image), findsOneWidget);
    });
  });
}
