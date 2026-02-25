import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';
import 'package:ruderbar_terminal/services/config_service.dart';
import 'package:ruderbar_terminal/services/sound_service.dart';

class MockCartService extends Mock implements CartService {}
class MockConfigService extends Mock implements ConfigService {}
class MockBuildContext extends Mock implements BuildContext {}
class MockSoundService extends Mock implements SoundService {}

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
    registerFallbackValue(SoundEvent.productAdd);
  });

  group('CartProvider', () {
    late MockCartService mockService;
    late MockConfigService mockConfig;
    late MockSoundService mockSoundService;
    late CartProvider provider;

    setUp(() {
      mockService = MockCartService();
      mockConfig = MockConfigService();
      mockSoundService = MockSoundService();
      when(() => mockConfig.dispenserEnabled).thenReturn(false);
      when(() => mockSoundService.play(any())).thenAnswer((_) async {});
      provider = CartProvider(service: mockService, config: mockConfig, soundService: mockSoundService);
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
      when(() => mockService.createTransaction(any(), any(), sessionId: any(named: 'sessionId')))
          .thenAnswer((_) async => ('txn-123', null));

      await provider.checkout(mockContext, member, 'test-session-id');

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

      await provider.checkout(mockContext, member, 'test-session-id');

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

  group('CartProvider sounds', () {
    late MockCartService mockService;
    late MockConfigService mockConfig;
    late MockSoundService mockSoundService;
    late CartProvider provider;

    setUp(() {
      mockService = MockCartService();
      mockConfig = MockConfigService();
      mockSoundService = MockSoundService();
      when(() => mockConfig.dispenserEnabled).thenReturn(false);
      when(() => mockSoundService.play(any())).thenAnswer((_) async {});
      provider = CartProvider(service: mockService, config: mockConfig, soundService: mockSoundService);
    });

    test('plays productAdd when item added to cart', () {
      provider.addItem('prod-1', 'Beer', 500, 1, 'de');
      verify(() => mockSoundService.play(SoundEvent.productAdd)).called(1);
    });

    test('plays productAdd again when same item quantity increased', () {
      provider.addItem('prod-1', 'Beer', 500, 1, 'de');
      provider.addItem('prod-1', 'Beer', 500, 1, 'de');
      verify(() => mockSoundService.play(SoundEvent.productAdd)).called(2);
    });

    test('plays quantityChange when item quantity decreased (item stays)', () {
      provider.addItem('prod-1', 'Beer', 500, 2, 'de');
      clearInteractions(mockSoundService);
      provider.decreaseItem('prod-1');
      verify(() => mockSoundService.play(SoundEvent.quantityChange)).called(1);
      verifyNever(() => mockSoundService.play(SoundEvent.productRemove));
    });

    test('plays productRemove when decreaseItem removes last unit', () {
      provider.addItem('prod-1', 'Beer', 500, 1, 'de');
      clearInteractions(mockSoundService);
      provider.decreaseItem('prod-1');
      verify(() => mockSoundService.play(SoundEvent.productRemove)).called(1);
    });

    test('plays productRemove when removeItem called', () {
      provider.addItem('prod-1', 'Beer', 500, 2, 'de');
      clearInteractions(mockSoundService);
      provider.removeItem('prod-1');
      verify(() => mockSoundService.play(SoundEvent.productRemove)).called(1);
    });

    test('plays checkoutSuccess when checkout succeeds', () async {
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
      clearInteractions(mockSoundService);
      when(() => mockService.validateCartBeforeCheckout(any(), any()))
          .thenAnswer((_) async => (true, null));
      when(() => mockService.createTransaction(any(), any(), sessionId: any(named: 'sessionId')))
          .thenAnswer((_) async => ('txn-123', null));

      await provider.checkout(mockContext, member, 'test-session-id');

      verify(() => mockSoundService.play(SoundEvent.checkoutSuccess)).called(1);
      verifyNever(() => mockSoundService.play(SoundEvent.checkoutError));
    });

    test('plays checkoutError when validation fails', () async {
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
      clearInteractions(mockSoundService);
      when(() => mockService.validateCartBeforeCheckout(any(), any()))
          .thenAnswer((_) async => (false, 'Member inactive'));

      await provider.checkout(mockContext, member, 'test-session-id');

      verify(() => mockSoundService.play(SoundEvent.checkoutError)).called(1);
      verifyNever(() => mockSoundService.play(SoundEvent.checkoutSuccess));
    });
  });
}
