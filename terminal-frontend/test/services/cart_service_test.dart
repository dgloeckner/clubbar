import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/services/cart_service.dart';

class MockTransactionsRepository extends Mock
    implements TransactionsRepository {}

class MockClubBarDatabase extends Mock implements ClubBarDatabase {}

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
      sessionId: null,
      unitPriceCents: null,
    ));
    registerFallbackValue(TransactionsLocalCompanion(
      id: const Value('fallback'),
      memberId: const Value('fallback-member'),
      amountCents: const Value(0),
      transactionType: const Value('purchase'),
      createdAt: const Value('2024-01-01T00:00:00.000Z'),
      synced: const Value(0),
    ));
  });

  group('CartService', () {
    late MockTransactionsRepository mockRepo;
    late MockClubBarDatabase mockDb;
    late CartService service;

    setUp(() {
      mockRepo = MockTransactionsRepository();
      mockDb = MockClubBarDatabase();
      service = CartService(
        database: mockDb,
        repository: mockRepo,
      );
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

      when(() => mockRepo.insertTransactionCompanion(any()))
          .thenAnswer((_) async {});

      final (txnId, error) = await service.createTransaction(
        member,
        items,
        sessionId: 'test-session-uuid',
      );

      expect(txnId, isNotNull);
      expect(error, isNull);
      // 2x Beer + 1x Wine = 3 individual transactions
      verify(() => mockRepo.insertTransactionCompanion(any())).called(3);
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

      when(() => mockRepo.insertTransactionCompanion(any()))
          .thenThrow(Exception('Database error'));

      final (txnId, error) = await service.createTransaction(
        member,
        items,
        sessionId: 'test-session',
      );

      expect(txnId, isNull);
      expect(error, isNotNull);
    });

    test('createTransaction passes sessionId to each transaction', () async {
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

      when(() => mockRepo.insertTransactionCompanion(any()))
          .thenAnswer((_) async {});

      final items = [
        CartItem(
          productId: 'prod-1',
          productName: 'Beer',
          quantity: 2,
          priceCents: 350,
          language: 'de',
        ),
      ];

      final (txnId, error) = await service.createTransaction(
        member,
        items,
        sessionId: 'test-session-uuid',
      );

      expect(txnId, isNotNull);
      expect(error, isNull);
      verify(() => mockRepo.insertTransactionCompanion(any())).called(2);
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
      expect(error, equals(TerminalErrorKey.accountInactive));
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
      expect(error, equals(TerminalErrorKey.cartEmpty));
    });
  });
}
