import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';
import 'package:ruderbar_terminal/services/config_service.dart';

class MockCartService extends Mock implements CartService {}
class MockConfigService extends Mock implements ConfigService {}
class MockBuildContext extends Mock implements BuildContext {}

void main() {
  setUpAll(() {
    registerFallbackValue(CartItem(
      productId: 'test-prod',
      productName: 'Test',
      quantity: 1,
      priceCents: 100,
      language: 'de',
    ));
    registerFallbackValue(MembersCacheData(
      id: 'test-member',
      cardUid: null,
      firstName: 'Test',
      lastName: 'User',
      preferredLanguage: 'de',
      isActive: 1,
      isSepaValid: 1,
      balanceCents: 0,
      updatedAt: DateTime.now().toIso8601String(),
    ));
  });

  group('CartProvider', () {
    late MockCartService mockService;
    late MockConfigService mockConfig;
    late CartProvider provider;

    setUp(() {
      mockService = MockCartService();
      mockConfig = MockConfigService();
      when(() => mockConfig.dispenserEnabled).thenReturn(false);
      provider = CartProvider(service: mockService, config: mockConfig);
    });

    test('initial state is empty', () {
      expect(provider.items, isEmpty);
      expect(provider.itemCount, equals(0));
      expect(provider.total, equals(0));
      expect(provider.isLoading, isFalse);
      expect(provider.lastError, isNull);
    });

    test('addItem adds product to cart and updates total', () {
      provider.addItem('prod-1', 'Beer', 500, 2, 'de');

      expect(provider.items, hasLength(1));
      expect(provider.itemCount, equals(2));
      expect(provider.total, equals(1000));
    });

    test('addItem accumulates quantities for same product', () {
      provider.addItem('prod-1', 'Beer', 500, 1, 'de');
      provider.addItem('prod-1', 'Beer', 500, 2, 'de');

      expect(provider.items, hasLength(1));
      expect(provider.itemCount, equals(3));
      expect(provider.total, equals(1500));
    });

    test('removeItem removes product from cart', () {
      provider.addItem('prod-1', 'Beer', 500, 2, 'de');
      provider.removeItem('prod-1');

      expect(provider.items, isEmpty);
      expect(provider.itemCount, equals(0));
      expect(provider.total, equals(0));
    });

    test('updateQuantity changes item quantity', () {
      provider.addItem('prod-1', 'Beer', 500, 1, 'de');
      provider.updateQuantity('prod-1', 3);

      expect(provider.itemCount, equals(3));
      expect(provider.total, equals(1500));
    });

    test('checkout creates transaction and clears cart', () async {
      final member = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: DateTime.now().toIso8601String(),
      );

      final mockContext = MockBuildContext();
      provider.addItem('prod-1', 'Beer', 500, 1, 'de');

      when(() => mockService.validateCartBeforeCheckout(any(), any()))
          .thenAnswer((_) async => (true, null));
      when(() => mockService.createTransaction(any(), any()))
          .thenAnswer((_) async => ('txn-123', null));

      await provider.checkout(mockContext, member);

      expect(provider.items, isEmpty);
      expect(provider.total, equals(0));
      expect(provider.lastError, isNull);
    });

    test('checkout handles validation error without clearing cart', () async {
      final member = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 0,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: DateTime.now().toIso8601String(),
      );

      final mockContext = MockBuildContext();
      provider.addItem('prod-1', 'Beer', 500, 1, 'de');

      when(() => mockService.validateCartBeforeCheckout(any(), any()))
          .thenAnswer((_) async => (false, 'Member inactive'));

      await provider.checkout(mockContext, member);

      expect(provider.items, hasLength(1)); // Cart unchanged
      expect(provider.lastError, equals('Member inactive'));
    });

    test('clearCart empties cart', () {
      provider.addItem('prod-1', 'Beer', 500, 2, 'de');
      expect(provider.items, isNotEmpty);

      provider.clearCart();

      expect(provider.items, isEmpty);
      expect(provider.total, equals(0));
    });
  });
}
