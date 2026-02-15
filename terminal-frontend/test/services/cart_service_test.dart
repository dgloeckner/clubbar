import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';

class MockTransactionsRepository extends Mock
    implements TransactionsRepository {}

void main() {
  setUpAll(() {
    registerFallbackValue(TransactionsLocalData(
      id: 'test-id',
      memberId: 'test-member',
      productId: null,
      amountCents: 0,
      transactionType: 'purchase',
      notes: null,
      createdAt: DateTime.now().toIso8601String(),
      synced: 0,
    ));
  });

  group('CartService', () {
    late MockTransactionsRepository mockRepo;
    late CartService service;

    setUp(() {
      mockRepo = MockTransactionsRepository();
      service = CartService(repository: mockRepo);
    });

    test('createTransaction persists transaction with items', () async {
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

      final items = [
        CartItem(
          productId: 'prod-1',
          productName: 'Beer',
          quantity: 2,
          priceCents: 500,
          language: 'de',
        ),
        CartItem(
          productId: 'prod-2',
          productName: 'Wine',
          quantity: 1,
          priceCents: 300,
          language: 'de',
        ),
      ];

      when(() => mockRepo.insertTransaction(any()))
          .thenAnswer((_) async {});

      final (txnId, error) = await service.createTransaction(member, items);

      expect(txnId, isNotNull);
      expect(error, isNull);
      // 2x Beer + 1x Wine = 3 individual transactions
      verify(() => mockRepo.insertTransaction(any())).called(3);
    });

    test('createTransaction returns error when repository fails', () async {
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

      final items = [
        CartItem(
          productId: 'prod-1',
          productName: 'Beer',
          quantity: 1,
          priceCents: 500,
          language: 'de',
        ),
      ];

      when(() => mockRepo.insertTransaction(any()))
          .thenThrow(Exception('Database error'));

      final (txnId, error) = await service.createTransaction(member, items);

      expect(txnId, isNull);
      expect(error, isNotNull);
    });

    test('validateCartBeforeCheckout returns valid for active member',
        () async {
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

      final items = [
        CartItem(
          productId: 'prod-1',
          productName: 'Beer',
          quantity: 1,
          priceCents: 500,
          language: 'de',
        ),
      ];

      final (valid, error) =
          await service.validateCartBeforeCheckout(member, items);

      expect(valid, isTrue);
      expect(error, isNull);
    });

    test('validateCartBeforeCheckout returns error when member inactive',
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

      final items = [
        CartItem(
          productId: 'prod-1',
          productName: 'Beer',
          quantity: 1,
          priceCents: 500,
          language: 'de',
        ),
      ];

      final (valid, error) =
          await service.validateCartBeforeCheckout(member, items);

      expect(valid, isFalse);
      expect(error, contains('inactive'));
    });

    test('validateCartBeforeCheckout returns error for empty cart', () async {
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

      final (valid, error) =
          await service.validateCartBeforeCheckout(member, []);

      expect(valid, isFalse);
      expect(error, contains('empty'));
    });
  });
}
