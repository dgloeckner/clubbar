import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/widgets/cart_item_row.dart';

void main() {
  group('CartItemRow', () {
    late CartItem testItem;

    setUp(() {
      testItem = CartItem(
        productId: 'prod-1',
        productName: 'Bier',
        priceCents: 550,
        quantity: 2,
        language: 'de',
      );
    });

    testWidgets('displays product quantity', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: testItem,
              onRemovePressed: () {},
              onQuantityChanged: (_) {},
            ),
          ),
        ),
      );

      expect(find.text('2'), findsWidgets);
    });

    testWidgets('displays product name', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: testItem,
              onRemovePressed: () {},
              onQuantityChanged: (_) {},
            ),
          ),
        ),
      );

      expect(find.text('Bier'), findsOneWidget);
    });

    testWidgets('displays line total price', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: testItem,
              onRemovePressed: () {},
              onQuantityChanged: (_) {},
            ),
          ),
        ),
      );

      expect(find.text('€11.00'), findsOneWidget);
    });

    testWidgets('calls onRemovePressed when delete tapped', (WidgetTester tester) async {
      var removed = false;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: testItem,
              onRemovePressed: () {
                removed = true;
              },
              onQuantityChanged: (_) {},
            ),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.delete));
      expect(removed, isTrue);
    });

    testWidgets('calls onQuantityChanged when quantity increased', (WidgetTester tester) async {
      int? newQuantity;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: testItem,
              onRemovePressed: () {},
              onQuantityChanged: (q) {
                newQuantity = q;
              },
            ),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.add));
      expect(newQuantity, equals(3));
    });

    testWidgets('calls onQuantityChanged when quantity decreased', (WidgetTester tester) async {
      int? newQuantity;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: testItem,
              onRemovePressed: () {},
              onQuantityChanged: (q) {
                newQuantity = q;
              },
            ),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.remove));
      expect(newQuantity, equals(1));
    });

    testWidgets('does not decrease quantity below 1', (WidgetTester tester) async {
      var callCount = 0;
      final singleItem = CartItem(
        productId: 'prod-1',
        productName: 'Bier',
        priceCents: 550,
        quantity: 1,
        language: 'de',
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: CartItemRow(
              item: singleItem,
              onRemovePressed: () {},
              onQuantityChanged: (_) {
                callCount++;
              },
            ),
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.remove));
      expect(callCount, equals(0));
    });
  });
}
