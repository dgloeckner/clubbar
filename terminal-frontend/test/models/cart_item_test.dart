import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/models/cart_item.dart';

void main() {
  group('CartItem', () {
    test('equality is based on productId alone', () {
      final item1 = CartItem(
        productId: '1',
        productName: 'Pils',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      );
      final item2 = CartItem(
        productId: '1',
        productName: 'Pils',
        priceCents: 350,
        quantity: 5,
        language: 'de',
      );

      expect(item1, equals(item2));
      expect(item1.hashCode, equals(item2.hashCode));
    });

    test('items with different productIds are not equal', () {
      final pils = CartItem(
        productId: '1',
        productName: 'Pils',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      );
      final water = CartItem(
        productId: '2',
        productName: 'Wasser',
        priceCents: 150,
        quantity: 1,
        language: 'de',
      );

      expect(pils, isNot(equals(water)));
    });
  });
}
