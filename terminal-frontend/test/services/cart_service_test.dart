import 'dart:io';

import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';

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
    late ConfigService configService;
    late Directory configDir;
    late CartService service;

    setUp(() {
      mockRepo = MockTransactionsRepository();
      mockDb = MockClubBarDatabase();
      // A real ConfigService over a scratch directory: the credit limit is the
      // club's configuration now (ADR-0046), and stubbing it away would test
      // the constant this epic removed.
      configDir = Directory.systemTemp.createTempSync('cart-service-config');
      addTearDown(() => configDir.deleteSync(recursive: true));
      configService = ConfigService(configDir: configDir.path);
      service = CartService(
        database: mockDb,
        repository: mockRepo,
        configService: configService,
      );
      // Settled tab by default; the credit-limit tests override it.
      when(() => mockRepo.getEffectiveBalance(any()))
          .thenAnswer((_) async => 0);
    });

    MembersCacheData memberWith({
      int isActive = 1,
      String? dateOfBirth = '1985-06-15',
      int? creditLimitCents,
    }) =>
        MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'John',
          lastName: 'Doe',
          preferredLanguage: 'de',
          isActive: isActive,
          isSepaValid: 1,
          balanceCents: 0,
          updatedAt: DateTime.now().toIso8601String(),
          dateOfBirth: dateOfBirth,
          creditLimitCents: creditLimitCents,
        );

    /// A birth date that makes the member exactly [years] old today, shifted by
    /// [dayOffset]. Anchored on the clock rather than written out, so a
    /// boundary case still means what it says next year.
    String bornForAge(int years, {int dayOffset = 0}) {
      final now = DateTime.now();
      final d = DateTime(now.year - years, now.month, now.day + dayOffset);
      return '${d.year.toString().padLeft(4, '0')}-'
          '${d.month.toString().padLeft(2, '0')}-'
          '${d.day.toString().padLeft(2, '0')}';
    }

    List<CartItem> cartOf({int? minAge, int cents = 350}) => [
          CartItem(
            productId: 'prod-1',
            productName: 'Drink',
            quantity: 1,
            priceCents: cents,
            language: 'de',
            minAge: minAge,
          ),
        ];

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

    /// The gate itself (epic #582 M6, ADR-0045, UC-T12 E7).
    ///
    /// `validateCartBeforeCheckout` is **the authority**. The product grid
    /// greys restricted tiles out, but that is a courtesy: every case below is
    /// written as if the UI had been bypassed entirely, because the cart can
    /// also be carried across a sync that restricts a product mid-session.
    group('Jugendschutz (ADR-0045, UC-T12 E7)', () {
      test('refuses a drink the member is too young for', () async {
        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(dateOfBirth: bornForAge(17)),
          cartOf(minAge: 18),
        );

        expect(valid, isFalse);
        expect(error, equals(TerminalErrorKey.ageRestricted));
      });

      /// The boundary. A member is of age *on* their birthday, and this is the
      /// case an off-by-one gets wrong in the direction a member notices.
      test('allows it on the birthday itself', () async {
        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(dateOfBirth: bornForAge(18)),
          cartOf(minAge: 18),
        );

        expect(valid, isTrue);
        expect(error, isNull);
      });

      test('refuses it the day before the birthday', () async {
        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(dateOfBirth: bornForAge(18, dayOffset: 1)),
          cartOf(minAge: 18),
        );

        expect(valid, isFalse);
        expect(error, equals(TerminalErrorKey.ageRestricted));
      });

      test('allows a comfortably older member', () async {
        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(dateOfBirth: '1985-06-15'),
          cartOf(minAge: 18),
        );

        expect(valid, isTrue);
        expect(error, isNull);
      });

      test('lets anyone buy an unrestricted drink', () async {
        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(dateOfBirth: bornForAge(12)),
          cartOf(minAge: null),
        );

        expect(valid, isTrue, reason: 'a minor may still buy an Apfelschorle');
        expect(error, isNull);
      });

      test('decides the two JuSchG thresholds independently', () async {
        final seventeen = memberWith(dateOfBirth: bornForAge(17));

        final (beerOk, beerError) =
            await service.validateCartBeforeCheckout(seventeen, cartOf(minAge: 16));
        expect(beerOk, isTrue);
        expect(beerError, isNull);

        final (spiritOk, spiritError) =
            await service.validateCartBeforeCheckout(seventeen, cartOf(minAge: 18));
        expect(spiritOk, isFalse);
        expect(spiritError, equals(TerminalErrorKey.ageRestricted));
      });

      /// Rule 3: a null birth date is an **anonymized** member, never "unknown,
      /// allow anyway". There is no fail-open branch to reach.
      test('refuses when the member has no birth date at all', () async {
        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(dateOfBirth: null),
          cartOf(minAge: 16),
        );

        expect(valid, isFalse);
        expect(error, equals(TerminalErrorKey.ageRestricted));
      });

      test('a cached birth date that will not parse refuses too', () async {
        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(dateOfBirth: 'not a date'),
          cartOf(minAge: 16),
        );

        expect(valid, isFalse, reason: 'unreadable is not permission');
        expect(error, equals(TerminalErrorKey.ageRestricted));
      });

      test('one restricted line refuses the whole cart', () async {
        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(dateOfBirth: bornForAge(17)),
          [
            CartItem(
              productId: 'schorle',
              productName: 'Schorle',
              quantity: 2,
              priceCents: 220,
              language: 'de',
            ),
            CartItem(
              productId: 'korn',
              productName: 'Korn',
              quantity: 1,
              priceCents: 250,
              language: 'de',
              minAge: 18,
            ),
          ],
        );

        expect(valid, isFalse);
        expect(error, equals(TerminalErrorKey.ageRestricted));
      });

      /// Ordering matters, and it is a design decision rather than an accident:
      /// a refusal on legal grounds must not be reported as a money problem.
      /// The member who is both over their limit and too young is told the
      /// thing that will not change by paying something off.
      test('reports the age, not the money, when both would block', () async {
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 9900);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(dateOfBirth: bornForAge(15)),
          cartOf(minAge: 18, cents: 900),
        );

        expect(valid, isFalse);
        expect(error, equals(TerminalErrorKey.ageRestricted));
      });

      /// ...but an empty cart is still an empty cart. The age check sits after
      /// that one, so "you have not chosen anything" never gets dressed up as a
      /// legal refusal.
      test('an empty cart is still reported as empty', () async {
        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(dateOfBirth: bornForAge(15)),
          [],
        );

        expect(valid, isFalse);
        expect(error, equals(TerminalErrorKey.cartEmpty));
      });

      test('names the highest age the cart demands', () async {
        final required = service.requiredAgeBlocking(
          memberWith(dateOfBirth: bornForAge(15)),
          [
            CartItem(
              productId: 'beer',
              productName: 'Pils',
              quantity: 1,
              priceCents: 350,
              language: 'de',
              minAge: 16,
            ),
            CartItem(
              productId: 'korn',
              productName: 'Korn',
              quantity: 1,
              priceCents: 250,
              language: 'de',
              minAge: 18,
            ),
          ],
        );

        expect(required, 18,
            reason: 'the refusal should name the strictest limit in the cart');
      });
    });

    group('credit limit (UC-T11 E3, UC-T12)', () {
      // The club default is €100.00 until a /sync/config poll says otherwise —
      // the value the backend seeds its own configuration with, so an
      // untouched club behaves exactly as it did (ADR-0046).

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

      /// The member's own ceiling, where they have one (ADR-0046). This is the
      /// authority — the screens disable the button, but the tab can move under
      /// the member's feet between rendering and tapping Buy, so every case
      /// below has to hold here rather than only in the UI.
      test('a raised override lets a member buy past the club default',
          () async {
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 9900);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(creditLimitCents: 20000),
          cartWorth(500),
        );

        expect(valid, isTrue, reason: 'their ceiling is 200 €, not 100 €');
        expect(error, isNull);
      });

      test('a lowered override blocks a member below the club default',
          () async {
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 4900);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(creditLimitCents: 5000),
          cartWorth(200),
        );

        expect(valid, isFalse);
        expect(error, equals(TerminalErrorKey.balanceLimitExceeded));
      });

      test('a member with no ceiling is never blocked', () async {
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 500000);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(creditLimitCents: 0),
          cartWorth(100000),
        );

        expect(valid, isTrue, reason: '0 means unlimited, not a ceiling of 0');
        expect(error, isNull);
      });

      test('landing exactly on their own ceiling is still allowed', () async {
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 4800);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(creditLimitCents: 5000),
          cartWorth(200),
        );

        expect(valid, isTrue);
        expect(error, isNull);
      });

      test('a club default synced from the backend replaces the seed',
          () async {
        await configService.setCreditLimitPolicy(
          const CreditLimitPolicy(
            defaultLimitCents: 5000,
            warnThresholdPercent: 80,
          ),
        );
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 4900);

        final (valid, error) = await service.validateCartBeforeCheckout(
          memberWith(),
          cartWorth(200),
        );

        expect(valid, isFalse, reason: 'the club lowered its ceiling to 50 €');
        expect(error, equals(TerminalErrorKey.balanceLimitExceeded));
      });

      /// A member the club stopped capping is still capped by their own
      /// override, and the reverse: the two settings are independent.
      test('an override still applies when the club caps nobody', () async {
        await configService.setCreditLimitPolicy(
          const CreditLimitPolicy(
            defaultLimitCents: 0,
            warnThresholdPercent: 80,
          ),
        );
        when(() => mockRepo.getEffectiveBalance(any()))
            .thenAnswer((_) async => 4900);

        expect(
          (await service.validateCartBeforeCheckout(
            memberWith(creditLimitCents: 5000),
            cartWorth(200),
          )).$1,
          isFalse,
        );
        expect(
          (await service.validateCartBeforeCheckout(
            memberWith(),
            cartWorth(200),
          )).$1,
          isTrue,
          reason: 'a member who inherits follows the club, which caps nobody',
        );
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
