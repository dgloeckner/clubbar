import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/shopping_cart.dart';

void main() {
  group('ShoppingCart', () {
    late ShoppingCart cart;

    setUp(() {
      cart = ShoppingCart();
    });

    test('starts empty', () {
      expect(cart.items.isEmpty, isTrue);
      expect(cart.itemCount, equals(0));
      expect(cart.totalCents, equals(0));
    });

    test('adds item to cart', () {
      final item = CartItem(
        productId: '1',
        productName: 'Pils',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      );

      cart.addItem(item);

      expect(cart.items.length, equals(1));
      expect(cart.itemCount, equals(1));
      expect(cart.totalCents, equals(350));
    });

    test('increments quantity for duplicate product', () {
      final item = CartItem(
        productId: '1',
        productName: 'Pils',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      );

      cart.addItem(item);
      cart.addItem(item);

      expect(cart.items.length, equals(1));
      expect(cart.items.first.quantity, equals(2));
      expect(cart.totalCents, equals(700));
    });

    test('calculates line totals correctly', () {
      final item1 = CartItem(
        productId: '1',
        productName: 'Pils',
        priceCents: 350,
        quantity: 2,
        language: 'de',
      );
      final item2 = CartItem(
        productId: '2',
        productName: 'Radler',
        priceCents: 300,
        quantity: 1,
        language: 'de',
      );

      cart.addItem(item1);
      cart.addItem(item2);

      expect(cart.totalCents, equals(1000)); // (350*2) + (300*1)
      expect(cart.itemCount, equals(3)); // 2 + 1
    });

    test('removes item from cart', () {
      final item = CartItem(
        productId: '1',
        productName: 'Pils',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      );

      cart.addItem(item);
      expect(cart.items.length, equals(1));

      cart.removeItem('1');
      expect(cart.items.isEmpty, isTrue);
    });

    test('updates quantity', () {
      final item = CartItem(
        productId: '1',
        productName: 'Pils',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      );

      cart.addItem(item);
      cart.updateQuantity('1', 3);

      expect(cart.items.first.quantity, equals(3));
      expect(cart.totalCents, equals(1050));
    });

    test('removes item when quantity set to 0', () {
      final item = CartItem(
        productId: '1',
        productName: 'Pils',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      );

      cart.addItem(item);
      cart.updateQuantity('1', 0);

      expect(cart.items.isEmpty, isTrue);
    });

    test('clears entire cart', () {
      cart.addItem(CartItem(
        productId: '1',
        productName: 'Pils',
        priceCents: 350,
        quantity: 2,
        language: 'de',
      ));
      cart.addItem(CartItem(
        productId: '2',
        productName: 'Radler',
        priceCents: 300,
        quantity: 1,
        language: 'de',
      ));

      expect(cart.items.length, equals(2));
      cart.clear();
      expect(cart.items.isEmpty, isTrue);
      expect(cart.totalCents, equals(0));
    });

    test('notifies listeners on changes', () async {
      var notifyCount = 0;
      cart.addListener(() => notifyCount++);

      final item = CartItem(
        productId: '1',
        productName: 'Pils',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      );

      cart.addItem(item);
      expect(notifyCount, equals(1));

      cart.updateQuantity('1', 2);
      expect(notifyCount, equals(2));

      cart.clear();
      expect(notifyCount, equals(3));
    });

    test('cart item equality based on productId', () {
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

      expect(item1, equals(item2)); // Same productId = equal
    });
  });
}
