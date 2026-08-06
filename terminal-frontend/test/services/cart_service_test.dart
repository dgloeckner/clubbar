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

class FakeMembersCacheData extends Fake implements MembersCacheData {}

void main() {
  setUpAll(() {
    registerFallbackValue(FakeMembersCacheData());
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
      // Settled tab by default; the credit-limit tests override it.
      when(() => mockRepo.getEffectiveBalance(any()))
          .thenAnswer((_) async => 0);
    });

    MembersCacheData memberWith({int isActive = 1}) => MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'John',
          lastName: 'Doe',
          preferredLanguage: 'de',
          isActive: isActive,
          isSepaValid: 1,
          balanceCents: 0,
          updatedAt: DateTime.now().toIso8601String(),
        );

    List<CartItem> cartWorth(int cents) => [
          CartItem(
            productId: 'prod-1',
            productName: 'Beer',
            quantity: 1,
            priceCents: cents,
            language: 'de',
          ),
        ];

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

    group('credit limit (UC-T11 E3, UC-T12)', () {
      // The configured limit is €100.00 (AppConfig.balanceLimitCents).

      test('blocks a checkout that would push the tab past the limit',
          () async {
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 9500);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(),
          cartWorth(600),
        );

        expect(valid, isFalse);
        expect(error, equals(TerminalErrorKey.balanceLimitExceeded));
      });

      test('allows a checkout that lands exactly on the limit', () async {
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 9500);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(),
          cartWorth(500),
        );

        expect(valid, isTrue);
        expect(error, isNull);
      });

      test('allows a checkout one cent under the limit', () async {
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 9500);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(),
          cartWorth(499),
        );

        expect(valid, isTrue);
        expect(error, isNull);
      });

      test('blocks a member already over the limit', () async {
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 12000);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(),
          cartWorth(100),
        );

        expect(valid, isFalse);
        expect(error, equals(TerminalErrorKey.balanceLimitExceeded));
      });

      test('counts unsynced transactions — the effective tab, not the synced one',
          () async {
        // A terminal that has been offline has the purchases only locally;
        // enforcing against member.balanceCents alone would let the tab run.
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 9900);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(),
          cartWorth(500),
        );

        expect(valid, isFalse);
        expect(error, equals(TerminalErrorKey.balanceLimitExceeded));
        verify(() => mockRepo.getEffectiveBalance(any())).called(1);
      });

      test('an inactive member is reported as inactive, not over limit',
          () async {
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 50000);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(isActive: 0),
          cartWorth(500),
        );

        expect(valid, isFalse);
        expect(error, equals(TerminalErrorKey.accountInactive));
      });

      test('checkCreditLimit sums the whole cart, quantities included',
          () async {
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 2000);

        final check = await service.checkCreditLimit(memberWith(), [
          CartItem(
            productId: 'prod-1',
            productName: 'Beer',
            quantity: 3,
            priceCents: 550,
            language: 'de',
          ),
          CartItem(
            productId: 'prod-2',
            productName: 'Water',
            quantity: 2,
            priceCents: 200,
            language: 'de',
          ),
        ]);

        expect(check.cartTotalCents, 2050);
        expect(check.currentBalanceCents, 2000);
        expect(check.projectedBalanceCents, 4050);
        expect(check.blocksCheckout, isFalse);
      });
    });
  });
}
