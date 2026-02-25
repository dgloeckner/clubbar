import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';
import 'package:ruderbar_terminal/services/config_service.dart';

class MockCartService extends Mock implements CartService {}
class MockConfigService extends Mock implements ConfigService {}

void main() {
  late CartProvider cart;
  late MockCartService mockCartService;
  late MockConfigService mockConfigService;

  setUp(() {
    mockCartService = MockCartService();
    mockConfigService = MockConfigService();
    cart = CartProvider(service: mockCartService, config: mockConfigService);
    // Add an item to work with
    cart.addItem('prod1', 'Bier', 200, 3, 'de');
  });

  group('CartProvider.decreaseItem', () {
    test('decreases quantity by 1 when quantity > 1', () {
      cart.decreaseItem('prod1');
      expect(cart.items.first.quantity, 2);
    });

    test('removes item when quantity is 1', () {
      cart.addItem('prod2', 'Cola', 150, 1, 'de');
      cart.decreaseItem('prod2');
      expect(cart.items.any((i) => i.productId == 'prod2'), isFalse);
    });

    test('does nothing for unknown productId', () {
      final countBefore = cart.items.length;
      cart.decreaseItem('unknown');
      expect(cart.items.length, countBefore);
    });
  });
}
