import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/sound_service.dart';

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
          .thenAnswer((_) async => (false, TerminalErrorKey.accountInactive));

      await provider.checkout(mockContext, member, 'test-session-id');

      expect(provider.items, hasLength(1)); // Cart unchanged
      expect(provider.lastErrorKey, equals(TerminalErrorKey.accountInactive));
    });

    // Acceptance criterion (#57): a member who taps checkout twice against the
    // same failure must be told twice — the second rejection has to reach the
    // UI as its own event, not be swallowed as "unchanged state".
    test('repeating the same checkout failure produces a second display event',
        () async {
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
          .thenAnswer((_) async => (false, TerminalErrorKey.accountInactive));

      var notifications = 0;
      provider.addListener(() => notifications++);

      await provider.checkout(mockContext, member, 'test-session-id');
      final first = provider.lastError;

      await provider.checkout(mockContext, member, 'test-session-id');
      final second = provider.lastError;

      expect(first!.key, equals(second!.key));
      expect(first, isNot(equals(second)));
      expect(second.sequence, greaterThan(first.sequence));
      expect(notifications, greaterThanOrEqualTo(2));
    });

    test('clearError drops a displayed checkout failure', () async {
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

      when(() => mockService.validateCartBeforeCheckout(any(), any()))
          .thenAnswer((_) async => (false, TerminalErrorKey.accountInactive));

      provider.addItem('prod-1', 'Beer', 500, 1, 'de');
      await provider.checkout(MockBuildContext(), member, 'test-session-id');
      expect(provider.lastError, isNotNull);

      provider.clearError();

      expect(provider.lastError, isNull);
    });

    test('clearCart empties cart', () {
      provider.addItem('prod-1', 'Beer', 500, 2, 'de');
      expect(provider.items, isNotEmpty);

      provider.clearCart();

      expect(provider.items, isEmpty);
      expect(provider.total, equals(0));
    });
  });

  group('CartProvider checkout re-entrancy', () {
    late MockCartService mockService;
    late MockConfigService mockConfig;
    late MockSoundService mockSoundService;
    late CartProvider provider;

    final member = MembersCacheData(
      id: 'member-1',
      cardUid: 'card-123',
      firstName: 'John',
      lastName: 'Doe',
      preferredLanguage: 'de',
      isActive: 1,
      isSepaValid: 1,
      balanceCents: 0,
      updatedAt: '2025-02-01T10:00:00Z',
    );

    setUp(() {
      mockService = MockCartService();
      mockConfig = MockConfigService();
      mockSoundService = MockSoundService();
      when(() => mockConfig.dispenserEnabled).thenReturn(false);
      when(() => mockSoundService.play(any())).thenAnswer((_) async {});
      provider = CartProvider(
        service: mockService,
        config: mockConfig,
        soundService: mockSoundService,
      );
    });

    test('second checkout while first is in flight creates no transaction',
        () async {
      // Gate the transaction creation so the first checkout stays in flight
      // until we decide to release it — this models the slow DB / dispenser
      // window during which a member can double-tap the button.
      final gate = Completer<void>();
      var createTransactionCalls = 0;

      when(() => mockService.validateCartBeforeCheckout(any(), any()))
          .thenAnswer((_) async => (true, null));
      when(() => mockService.createTransaction(any(), any(),
          sessionId: any(named: 'sessionId'))).thenAnswer((_) async {
        createTransactionCalls++;
        await gate.future;
        return ('txn-123', null);
      });

      provider.addItem('prod-1', 'Beer', 500, 2, 'de');
      final mockContext = MockBuildContext();

      // First tap — starts the checkout but does not complete it yet.
      final first = provider.checkout(mockContext, member, 'session-1');
      expect(provider.isLoading, isTrue);

      // Second tap while the first checkout is still awaiting.
      await provider.checkout(mockContext, member, 'session-2');

      // The re-entrant call must be a no-op: no second submission, the cart
      // is untouched and the in-flight session is not overwritten.
      expect(createTransactionCalls, equals(1));
      expect(provider.items, hasLength(1));
      expect(provider.lastSessionId, equals('session-1'));

      gate.complete();
      await first;

      // The original checkout still completes normally, exactly once.
      expect(createTransactionCalls, equals(1));
      expect(provider.items, isEmpty);
      expect(provider.isLoading, isFalse);
      expect(provider.lastError, isNull);
      verify(() => mockSoundService.play(SoundEvent.checkoutSuccess)).called(1);
    });

    test('rapid double checkout awaited together submits the cart once',
        () async {
      var createTransactionCalls = 0;

      when(() => mockService.validateCartBeforeCheckout(any(), any()))
          .thenAnswer((_) async => (true, null));
      when(() => mockService.createTransaction(any(), any(),
          sessionId: any(named: 'sessionId'))).thenAnswer((_) async {
        createTransactionCalls++;
        // Yield so a concurrent checkout would interleave here.
        await Future<void>.delayed(const Duration(milliseconds: 10));
        return ('txn-123', null);
      });

      provider.addItem('prod-1', 'Beer', 500, 1, 'de');
      final mockContext = MockBuildContext();

      await Future.wait([
        provider.checkout(mockContext, member, 'session-1'),
        provider.checkout(mockContext, member, 'session-1'),
      ]);

      expect(createTransactionCalls, equals(1));
      expect(provider.items, isEmpty);
      expect(provider.isLoading, isFalse);
    });

    test('checkout can run again after the previous one finished', () async {
      when(() => mockService.validateCartBeforeCheckout(any(), any()))
          .thenAnswer((_) async => (true, null));
      when(() => mockService.createTransaction(any(), any(),
              sessionId: any(named: 'sessionId')))
          .thenAnswer((_) async => ('txn-123', null));

      final mockContext = MockBuildContext();

      provider.addItem('prod-1', 'Beer', 500, 1, 'de');
      await provider.checkout(mockContext, member, 'session-1');
      expect(provider.items, isEmpty);

      provider.addItem('prod-2', 'Water', 150, 1, 'de');
      await provider.checkout(mockContext, member, 'session-2');

      expect(provider.items, isEmpty);
      expect(provider.lastSessionId, equals('session-2'));
      verify(() => mockService.createTransaction(any(), any(),
          sessionId: any(named: 'sessionId'))).called(2);
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
          .thenAnswer((_) async => (false, TerminalErrorKey.accountInactive));

      await provider.checkout(mockContext, member, 'test-session-id');

      verify(() => mockSoundService.play(SoundEvent.checkoutError)).called(1);
      verifyNever(() => mockSoundService.play(SoundEvent.checkoutSuccess));
    });
  });
}
