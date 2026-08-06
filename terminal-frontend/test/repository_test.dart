import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/repository/sync_repository.dart';

// Helper to create in-memory test database
ClubBarDatabase createTestDatabase() {
  return ClubBarDatabase.forTesting(
    NativeDatabase.memory(setup: (db) {
      db.execute('PRAGMA foreign_keys = ON');
    }),
  );
}

void main() {
  group('MembersRepository', () {
    late ClubBarDatabase db;
    late MembersRepository repo;

    setUp(() async {
      db = createTestDatabase();
      repo = MembersRepository(db);
    });

    tearDown(() async {
      await db.close();
    });

    test('findByCardUid returns null and error for unknown card', () async {
      final (member, error) = await repo.findByCardUid('unknown-card-uid');

      expect(member, isNull);
      expect(error, equals(TerminalErrorKey.unknownCard));
    });

    test('findByCardUid returns member for valid card', () async {
      // Insert test member
      await db.into(db.membersCache).insert(
        MembersCacheCompanion(
          id: const Value('member-1'),
          cardUid: const Value('CARD-UID-123'),
          firstName: const Value('John'),
          lastName: const Value('Doe'),
          preferredLanguage: const Value('de'),
          isActive: const Value(1),
          isSepaValid: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      final (member, error) = await repo.findByCardUid('CARD-UID-123');

      expect(member, isNotNull);
      expect(member!.id, equals('member-1'));
      expect(member.firstName, equals('John'));
      expect(error, isNull);
    });

    test('findByCardUid returns error for inactive member', () async {
      // Insert inactive member
      await db.into(db.membersCache).insert(
        MembersCacheCompanion(
          id: const Value('member-inactive'),
          cardUid: const Value('CARD-INACTIVE'),
          firstName: const Value('Jane'),
          lastName: const Value('Doe'),
          preferredLanguage: const Value('de'),
          isActive: const Value(0),
          isSepaValid: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      final (member, error) = await repo.findByCardUid('CARD-INACTIVE');

      expect(member, isNull);
      expect(error, equals(TerminalErrorKey.accountInactive));
    });

    test('findByCardUid returns error for missing SEPA mandate', () async {
      // Insert member without SEPA
      await db.into(db.membersCache).insert(
        MembersCacheCompanion(
          id: const Value('member-no-sepa'),
          cardUid: const Value('CARD-NO-SEPA'),
          firstName: const Value('Bob'),
          lastName: const Value('Smith'),
          preferredLanguage: const Value('de'),
          isActive: const Value(1),
          isSepaValid: const Value(0),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      final (member, error) = await repo.findByCardUid('CARD-NO-SEPA');

      expect(member, isNull);
      expect(error, equals(TerminalErrorKey.sepaMissing));
    });

    // Issue #18: the lookup is an exact string match, so before normalization a
    // reader that typed lower-case hex was told "Unknown token" for a member
    // that exists — a failure that depended purely on the reader hardware.
    group('card UID case (issue #18)', () {
      Future<void> seedMember(String cardUid) =>
          repo.upsertMembers([
            Member(
              id: 'member-case',
              cardUid: cardUid,
              firstName: 'Case',
              lastName: 'Insensitive',
              preferredLanguage: 'de',
              isActive: true,
              isSepaValid: true,
              createdAt: DateTime.parse('2025-02-01T12:00:00Z'),
              updatedAt: DateTime.parse('2025-02-01T12:00:00Z'),
            ),
          ]);

      test('upsertMembers stores the UID canonically, whatever case the '
          'backend sent', () async {
        await seedMember('abcd1234');

        final stored = await db.select(db.membersCache).getSingle();
        expect(stored.cardUid, equals('ABCD1234'));
      });

      test('upsertMembers keeps a member without a card card-less', () async {
        await repo.upsertMembers([
          Member(
            id: 'member-anonymized',
            cardUid: null,
            firstName: 'No',
            lastName: 'Card',
            preferredLanguage: 'de',
            isActive: true,
            isSepaValid: true,
            createdAt: DateTime.parse('2025-02-01T12:00:00Z'),
            updatedAt: DateTime.parse('2025-02-01T12:00:00Z'),
          ),
        ]);

        final stored = await db.select(db.membersCache).getSingle();
        expect(stored.cardUid, isNull);
      });

      test('lower-, upper- and mixed-case scans all resolve to the same member',
          () async {
        await seedMember('ABCD1234');

        for (final scanned in ['abcd1234', 'ABCD1234', 'AbCd1234']) {
          final (member, error) = await repo.findByCardUid(scanned);
          expect(member?.id, equals('member-case'),
              reason: 'scan "$scanned" must find the member');
          expect(error, isNull);
        }
      });

      test('a member synced in lower case is still found by an upper-case scan',
          () async {
        await seedMember('abcd1234');

        final (member, error) = await repo.findByCardUid('ABCD1234');

        expect(member?.id, equals('member-case'));
        expect(error, isNull);
      });

      test('the trailing whitespace a reader appends is not part of the UID',
          () async {
        await seedMember('ABCD1234');

        final (member, _) = await repo.findByCardUid(' abcd1234 ');

        expect(member?.id, equals('member-case'));
      });
    });

    test('upsertMembers inserts new members', () async {
      final dtos = [
        Member(
          id: 'member-1',
          cardUid: 'card-1',
          firstName: 'Alice',
          lastName: 'Johnson',
          preferredLanguage: 'de',
          isActive: true,
          isSepaValid: true,
          createdAt: DateTime.parse('2025-02-01T12:00:00Z'),
          updatedAt: DateTime.parse('2025-02-01T12:00:00Z'),
        ),
      ];

      await repo.upsertMembers(dtos);

      final count = await (db.select(db.membersCache)).get();
      expect(count.length, equals(1));
      expect(count.first.firstName, equals('Alice'));
    });

    test('upsertMembers updates existing members', () async {
      // Insert initial member
      await db.into(db.membersCache).insert(
        MembersCacheCompanion(
          id: const Value('member-1'),
          cardUid: const Value('card-1'),
          firstName: const Value('Alice'),
          lastName: const Value('Johnson'),
          preferredLanguage: const Value('de'),
          isActive: const Value(1),
          isSepaValid: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      // Upsert with updated data
      final dtos = [
        Member(
          id: 'member-1',
          cardUid: 'card-1',
          firstName: 'Alice',
          lastName: 'Smith', // Changed
          preferredLanguage: 'en', // Changed
          isActive: false,
          isSepaValid: true,
          createdAt: DateTime.parse('2025-02-01T12:00:00Z'),
          updatedAt: DateTime.parse('2025-02-02T12:00:00Z'),
        ),
      ];

      await repo.upsertMembers(dtos);

      final members = await (db.select(db.membersCache)).get();
      expect(members.length, equals(1));
      expect(members.first.lastName, equals('Smith'));
      expect(members.first.preferredLanguage, equals('en'));
      expect(members.first.isActive, equals(0));
    });

    test('getAllActive returns only active members', () async {
      // Insert active and inactive members - do it one at a time
      await db.into(db.membersCache).insert(
        MembersCacheCompanion(
          id: const Value('member-active'),
          cardUid: const Value('card-active'),
          firstName: const Value('Active'),
          lastName: const Value('Member'),
          preferredLanguage: const Value('de'),
          isActive: const Value(1),
          isSepaValid: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      await db.into(db.membersCache).insert(
        MembersCacheCompanion(
          id: const Value('member-inactive'),
          cardUid: const Value('CARD-INACTIVE'),
          firstName: const Value('Inactive'),
          lastName: const Value('Member'),
          preferredLanguage: const Value('de'),
          isActive: const Value(0),
          isSepaValid: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      final activeMembers = await repo.getAllActive();

      expect(activeMembers.length, equals(1));
      expect(activeMembers.first.firstName, equals('Active'));
    });

    test('clearCache deletes all members', () async {
      // Insert members - do it one at a time
      await db.into(db.membersCache).insert(
        MembersCacheCompanion(
          id: const Value('member-1'),
          cardUid: const Value('card-1'),
          firstName: const Value('Alice'),
          lastName: const Value('Johnson'),
          preferredLanguage: const Value('de'),
          isActive: const Value(1),
          isSepaValid: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      await db.into(db.membersCache).insert(
        MembersCacheCompanion(
          id: const Value('member-2'),
          cardUid: const Value('card-2'),
          firstName: const Value('Bob'),
          lastName: const Value('Smith'),
          preferredLanguage: const Value('en'),
          isActive: const Value(1),
          isSepaValid: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      await repo.clearCache();

      final remaining = await (db.select(db.membersCache)).get();
      expect(remaining, isEmpty);
    });
  });

  group('ProductsRepository', () {
    late ClubBarDatabase db;
    late ProductsRepository repo;

    setUp(() async {
      db = createTestDatabase();
      repo = ProductsRepository(db);
    });

    tearDown(() async {
      await db.close();
    });

    test('getActiveCategoriesWithProducts returns empty list for no data',
        () async {
      final result = await repo.getActiveCategoriesWithProducts();

      expect(result, isEmpty);
    });

    test('getActiveCategoriesWithProducts returns categories with products',
        () async {
      // Insert categories
      await db.into(db.categoriesCache).insert(
        CategoriesCacheCompanion(
          id: const Value('cat-1'),
          names: const Value('{"de":"Getränke","en":"Beverages"}'),
          isActive: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      // Insert products
      await db.into(db.productsCache).insert(
        ProductsCacheCompanion(
          id: const Value('prod-1'),
          categoryId: const Value('cat-1'),
          names: const Value('{"de":"Pils","en":"Pilsner"}'),
          descriptions: const Value(null),
          priceCents: const Value(350),
          isActive: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      final result = await repo.getActiveCategoriesWithProducts();

      expect(result.length, equals(1));
      expect(result[0].$1.id, equals('cat-1'));
      expect(result[0].$2.length, equals(1));
      expect(result[0].$2[0].id, equals('prod-1'));
    });

    test('getActiveCategoriesWithProducts excludes inactive categories',
        () async {
      // Insert inactive category
      await db.into(db.categoriesCache).insert(
        CategoriesCacheCompanion(
          id: const Value('cat-inactive'),
          names: const Value('{"de":"Inaktiv"}'),
          isActive: const Value(0),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      final result = await repo.getActiveCategoriesWithProducts();

      expect(result, isEmpty);
    });

    test('getActiveCategoriesWithProducts excludes inactive products',
        () async {
      // Insert category with inactive product
      await db.into(db.categoriesCache).insert(
        CategoriesCacheCompanion(
          id: const Value('cat-1'),
          names: const Value('{"de":"Getränke"}'),
          isActive: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      await db.into(db.productsCache).insert(
        ProductsCacheCompanion(
          id: const Value('prod-inactive'),
          categoryId: const Value('cat-1'),
          names: const Value('{"de":"Inaktiv"}'),
          descriptions: const Value(null),
          priceCents: const Value(100),
          isActive: const Value(0),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      final result = await repo.getActiveCategoriesWithProducts();

      expect(result, isEmpty);
    });

    test('getProduct returns null for unknown product', () async {
      final product = await repo.getProduct('unknown');

      expect(product, isNull);
    });

    test('getProduct returns product by id', () async {
      // Insert category first (foreign key constraint)
      await db.into(db.categoriesCache).insert(
        CategoriesCacheCompanion(
          id: const Value('cat-1'),
          names: const Value('{"de":"Getränke"}'),
          isActive: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      await db.into(db.productsCache).insert(
        ProductsCacheCompanion(
          id: const Value('prod-1'),
          categoryId: const Value('cat-1'),
          names: const Value('{"de":"Pils"}'),
          descriptions: const Value('{"de":"German beer"}'),
          priceCents: const Value(350),
          isActive: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      final product = await repo.getProduct('prod-1');

      expect(product, isNotNull);
      expect(product!.id, equals('prod-1'));
      expect(product.priceCents, equals(350));
    });

    test('getTranslatedName returns translated name', () {
      final jsonNames = '{"de":"Bier","en":"Beer"}';

      final deName = repo.getTranslatedName(jsonNames, 'de');
      final enName = repo.getTranslatedName(jsonNames, 'en');

      expect(deName, equals('Bier'));
      expect(enName, equals('Beer'));
    });

    test('getTranslatedName falls back to German', () {
      final jsonNames = '{"de":"Bier","en":"Beer"}';

      final frName = repo.getTranslatedName(jsonNames, 'fr');

      expect(frName, equals('Bier'));
    });

    test('getTranslatedName returns Unknown for invalid JSON', () {
      final result = repo.getTranslatedName('invalid json', 'de');

      expect(result, equals('Unknown'));
    });

    test('upsertCategories inserts categories', () async {
      final dtos = [
        Category(
          id: 'cat-1',
          names: {'de': 'Getränke', 'en': 'Beverages'},
          isActive: true,
          createdAt: DateTime.parse('2025-02-01T12:00:00Z'),
          updatedAt: DateTime.parse('2025-02-01T12:00:00Z'),
        ),
      ];

      await repo.upsertCategories(dtos);

      final categories = await (db.select(db.categoriesCache)).get();
      expect(categories.length, equals(1));
      expect(categories.first.id, equals('cat-1'));
    });

    test('upsertProducts inserts products', () async {
      // Insert category first (foreign key constraint)
      final categoryDtos = [
        Category(
          id: 'cat-1',
          names: {'de': 'Getränke'},
          isActive: true,
          createdAt: DateTime.parse('2025-02-01T12:00:00Z'),
          updatedAt: DateTime.parse('2025-02-01T12:00:00Z'),
        ),
      ];
      await repo.upsertCategories(categoryDtos);

      final dtos = [
        Product(
          id: 'prod-1',
          categoryId: 'cat-1',
          names: {'de': 'Pils'},
          descriptions: {'de': 'German beer'},
          priceCents: 350,
          isActive: true,
          createdAt: DateTime.parse('2025-02-01T12:00:00Z'),
          updatedAt: DateTime.parse('2025-02-01T12:00:00Z'),
        ),
      ];

      await repo.upsertProducts(dtos);

      final products = await (db.select(db.productsCache)).get();
      expect(products.length, equals(1));
      expect(products.first.id, equals('prod-1'));
      expect(products.first.priceCents, equals(350));
    });

    test('clearCache deletes all products and categories', () async {
      // Insert category first (foreign key constraint)
      await db.into(db.categoriesCache).insert(
        CategoriesCacheCompanion(
          id: const Value('cat-1'),
          names: const Value('{"de":"Getränke"}'),
          isActive: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      // Insert product with the category
      await db.into(db.productsCache).insert(
        ProductsCacheCompanion(
          id: const Value('prod-1'),
          categoryId: const Value('cat-1'),
          names: const Value('{"de":"Pils"}'),
          descriptions: const Value(null),
          priceCents: const Value(350),
          isActive: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      await repo.clearCache();

      final categories = await (db.select(db.categoriesCache)).get();
      final products = await (db.select(db.productsCache)).get();

      expect(categories, isEmpty);
      expect(products, isEmpty);
    });
  });

  group('TransactionsRepository', () {
    late ClubBarDatabase db;
    late TransactionsRepository repo;

    Future<void> createTestMember(String memberId) async {
      await db.into(db.membersCache).insert(
        MembersCacheCompanion(
          id: Value(memberId),
          cardUid: Value('card-$memberId'),
          firstName: const Value('Test'),
          lastName: const Value('Member'),
          preferredLanguage: const Value('de'),
          isActive: const Value(1),
          isSepaValid: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );
    }

    setUp(() async {
      db = createTestDatabase();
      repo = TransactionsRepository(db);
    });

    tearDown(() async {
      await db.close();
    });

    test('insertTransaction inserts local transaction', () async {
      await createTestMember('member-1');

      await repo.insertTransaction(
        TransactionsLocalData(
          id: 'txn-1',
          memberId: 'member-1',
          productId: null,
          amountCents: -350,
          transactionType: 'PURCHASE',
          notes: null,
          createdAt: '2025-02-01T12:00:00Z',
          synced: 0,
          sessionId: null,
          unitPriceCents: null,
        ),
      );

      final count = await (db.select(db.transactionsLocal)).get();
      expect(count.length, equals(1));
      expect(count.first.transactionType, equals('PURCHASE'));
    });

    test('getEffectiveBalance is the synced balance when nothing is pending',
        () async {
      await createTestMember('member-1');

      final member = await (db.select(db.membersCache)
            ..where((m) => m.id.equals('member-1')))
          .getSingle();

      expect(
        await repo.getEffectiveBalance(member.copyWith(balanceCents: 500)),
        equals(500),
      );
    });

    test('getEffectiveBalance adds unsynced transactions to the synced balance',
        () async {
      await createTestMember('member-1');
      await db.into(db.transactionsLocal).insert(
            TransactionsLocalCompanion(
              id: const Value('txn-synced'),
              memberId: const Value('member-1'),
              amountCents: const Value(1000),
              transactionType: const Value('purchase'),
              createdAt: const Value('2025-02-01T12:00:00Z'),
              synced: const Value(1),
            ),
          );
      await db.into(db.transactionsLocal).insert(
            TransactionsLocalCompanion(
              id: const Value('txn-unsynced'),
              memberId: const Value('member-1'),
              amountCents: const Value(550),
              transactionType: const Value('purchase'),
              createdAt: const Value('2025-02-01T12:01:00Z'),
              synced: const Value(0),
            ),
          );

      final member = await (db.select(db.membersCache)
            ..where((m) => m.id.equals('member-1')))
          .getSingle();
      // The synced 1000 is already reflected in balanceCents by the backend;
      // only the unsynced 550 is added on top.
      final balance = await repo.getEffectiveBalance(
        member.copyWith(balanceCents: 1000),
      );

      expect(balance, equals(1550));
    });

    test('getUnsyncedTransactions returns only unsynced', () async {
      await createTestMember('member-1');

      // Insert synced and unsynced transactions
      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-synced'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-350),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:00:00Z'),
          synced: const Value(1),
        ),
      );

      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-unsynced'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-350),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:01:00Z'),
          synced: const Value(0),
        ),
      );

      final unsynced = await repo.getUnsyncedTransactions();

      expect(unsynced.length, equals(1));
      expect(unsynced.first.id, equals('txn-unsynced'));
    });

    test('getTransaction returns transaction by id', () async {
      await createTestMember('member-1');

      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-1'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-350),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:00:00Z'),
          synced: const Value(0),
        ),
      );

      final txn = await repo.getTransaction('txn-1');

      expect(txn, isNotNull);
      expect(txn!.memberId, equals('member-1'));
      expect(txn.amountCents, equals(-350));
    });

    test('getTransactionsByMember returns member transactions in desc order',
        () async {
      await createTestMember('member-1');

      // Insert transactions for member (older first in insertion)
      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-1'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-350),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:00:00Z'),
          synced: const Value(0),
        ),
      );

      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-2'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-300),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:01:00Z'),
          synced: const Value(0),
        ),
      );

      final txns = await repo.getTransactionsByMember('member-1');

      expect(txns.length, equals(2));
      expect(txns[0].id, equals('txn-2')); // Newest first
      expect(txns[1].id, equals('txn-1')); // Oldest second
    });

    test('getTransactionCount returns total transaction count', () async {
      await createTestMember('member-1');

      // Insert 3 transactions
      for (int i = 0; i < 3; i++) {
        await db.into(db.transactionsLocal).insert(
          TransactionsLocalCompanion(
            id: Value('txn-$i'),
            memberId: const Value('member-1'),
            productId: const Value(null),
            amountCents: const Value(-350),
            transactionType: const Value('PURCHASE'),
            notes: const Value(null),
            createdAt: Value('2025-02-01T12:0$i:00Z'),
            synced: const Value(0),
          ),
        );
      }

      final count = await repo.getTransactionCount();

      expect(count, equals(3));
    });

    test('markAsSynced updates synced flag', () async {
      await createTestMember('member-1');

      // Insert transactions
      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-1'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-350),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:00:00Z'),
          synced: const Value(0),
        ),
      );

      await repo.markAsSynced(['txn-1']);

      final txn = await repo.getTransaction('txn-1');
      expect(txn!.synced, equals(1));
    });

    test('getTotalAmountForMember sums all amounts', () async {
      await createTestMember('member-1');

      // Insert transactions for member
      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-1'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-350),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:00:00Z'),
          synced: const Value(0),
        ),
      );

      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-2'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-300),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:01:00Z'),
          synced: const Value(0),
        ),
      );

      final total = await repo.getTotalAmountForMember('member-1');

      expect(total, equals(-650)); // -350 + -300
    });

    test('clearCache deletes all transactions', () async {
      await createTestMember('member-1');

      // Insert transactions
      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-1'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-350),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:00:00Z'),
          synced: const Value(0),
        ),
      );

      await repo.clearCache();

      final txns = await (db.select(db.transactionsLocal)).get();
      expect(txns, isEmpty);
    });

    group('session queries', () {
      test('getSessionTotal returns sum of abs(amountCents) for session', () async {
        await createTestMember('m1');
        // Insert two transactions with session_id 'sess-1'
        await db.into(db.transactionsLocal).insert(TransactionsLocalCompanion(
          id: const Value('t1'), memberId: const Value('m1'),
          amountCents: const Value(350), transactionType: const Value('purchase'),
          createdAt: const Value('2025-01-01T12:00:00Z'), synced: const Value(0),
          sessionId: const Value('sess-1'), unitPriceCents: const Value(350),
        ));
        await db.into(db.transactionsLocal).insert(TransactionsLocalCompanion(
          id: const Value('t2'), memberId: const Value('m1'),
          amountCents: const Value(500), transactionType: const Value('purchase'),
          createdAt: const Value('2025-01-01T12:00:01Z'), synced: const Value(0),
          sessionId: const Value('sess-1'), unitPriceCents: const Value(500),
        ));
        // Insert transaction with different session (should not be counted)
        await db.into(db.transactionsLocal).insert(TransactionsLocalCompanion(
          id: const Value('t3'), memberId: const Value('m1'),
          amountCents: const Value(999), transactionType: const Value('purchase'),
          createdAt: const Value('2025-01-01T13:00:00Z'), synced: const Value(0),
          sessionId: const Value('sess-2'),
        ));

        final total = await repo.getSessionTotal('sess-1');

        expect(total, 850); // 350 + 500
      });

      test('getSessionDispenserInfo returns dispenser row for session', () async {
        await createTestMember('m1');
        await db.into(db.transactionsLocal).insert(TransactionsLocalCompanion(
          id: const Value('t1'), memberId: const Value('m1'),
          amountCents: const Value(500), transactionType: const Value('purchase'),
          createdAt: const Value('2025-01-01T12:00:00Z'), synced: const Value(0),
          sessionId: const Value('sess-1'),
          dispenserTxId: const Value('disp-tx-1'),
          dispenserRequested: const Value(5),
          dispenserActual: const Value(2),
          unitPriceCents: const Value(500),
        ));

        final info = await repo.getSessionDispenserInfo('sess-1');

        expect(info, isNotNull);
        expect(info!.dispenserRequested, 5);
        expect(info.dispenserActual, 2);
        expect(info.unitPriceCents, 500);
      });

      test('getSessionDispenserInfo returns null when no dispenser row', () async {
        final info = await repo.getSessionDispenserInfo('no-such-session');

        expect(info, isNull);
      });
    });

    test('completeSyncAtomically marks transactions synced and updates balances', () async {
      await createTestMember('member-1');
      await createTestMember('member-2');
      final membersRepo = MembersRepository(db);

      // Insert unsynced transactions
      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-1'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-350),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:00:00Z'),
          synced: const Value(0),
        ),
      );

      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-2'),
          memberId: const Value('member-2'),
          productId: const Value(null),
          amountCents: const Value(-300),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:01:00Z'),
          synced: const Value(0),
        ),
      );

      // Complete sync atomically
      await repo.completeSyncAtomically(
        acceptedIds: ['txn-1', 'txn-2'],
        memberBalances: {'member-1': 4500, 'member-2': 1200},
        membersRepo: membersRepo,
      );

      // Verify transactions marked as synced
      final txn1 = await repo.getTransaction('txn-1');
      expect(txn1!.synced, equals(1));

      final txn2 = await repo.getTransaction('txn-2');
      expect(txn2!.synced, equals(1));

      // Verify member balances updated
      final members = await db.select(db.membersCache).get();
      final m1 = members.firstWhere((m) => m.id == 'member-1');
      final m2 = members.firstWhere((m) => m.id == 'member-2');
      expect(m1.balanceCents, equals(4500));
      expect(m2.balanceCents, equals(1200));
    });

    test('completeSyncAtomically with empty lists is a no-op', () async {
      await createTestMember('member-1');
      final membersRepo = MembersRepository(db);

      await repo.completeSyncAtomically(
        acceptedIds: [],
        memberBalances: {},
        membersRepo: membersRepo,
      );

      // No crash, no changes
      final members = await db.select(db.membersCache).get();
      expect(members.first.balanceCents, equals(0));
    });

    test('deleteTransaction deletes specific transaction', () async {
      await createTestMember('member-1');

      // Insert transactions
      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-1'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-350),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:00:00Z'),
          synced: const Value(0),
        ),
      );

      await db.into(db.transactionsLocal).insert(
        TransactionsLocalCompanion(
          id: const Value('txn-2'),
          memberId: const Value('member-1'),
          productId: const Value(null),
          amountCents: const Value(-300),
          transactionType: const Value('PURCHASE'),
          notes: const Value(null),
          createdAt: const Value('2025-02-01T12:01:00Z'),
          synced: const Value(0),
        ),
      );

      await repo.deleteTransaction('txn-1');

      final txns = await (db.select(db.transactionsLocal)).get();
      expect(txns.length, equals(1));
      expect(txns.first.id, equals('txn-2'));
    });
  });

  group('SyncRepository', () {
    late ClubBarDatabase db;
    late SyncRepository repo;

    setUp(() async {
      db = createTestDatabase();
      repo = SyncRepository(db);
    });

    tearDown(() async {
      await db.close();
    });

    test('setSyncState stores value', () async {
      await repo.setSyncState('test_key', 'test_value');

      final value = await repo.getSyncState('test_key');

      expect(value, equals('test_value'));
    });

    test('getSyncState returns null for unknown key', () async {
      final value = await repo.getSyncState('unknown_key');

      expect(value, isNull);
    });

    test('setSyncState updates existing value', () async {
      await repo.setSyncState('test_key', 'value1');
      await repo.setSyncState('test_key', 'value2');

      final value = await repo.getSyncState('test_key');

      expect(value, equals('value2'));
    });

    test('setLastMembersSyncTime stores timestamp', () async {
      const timestamp = '2025-02-01T12:00:00Z';

      await repo.setLastMembersSyncTime(timestamp);

      final value = await repo.getLastMembersSyncTime();

      expect(value, equals(timestamp));
    });

    test('setLastProductsSyncTime stores timestamp', () async {
      const timestamp = '2025-02-01T12:00:00Z';

      await repo.setLastProductsSyncTime(timestamp);

      final value = await repo.getLastProductsSyncTime();

      expect(value, equals(timestamp));
    });

    test('setLastSyncTime stores timestamp', () async {
      const timestamp = '2025-02-01T12:00:00Z';

      await repo.setLastSyncTime(timestamp);

      final value = await repo.getLastSyncTime();

      expect(value, equals(timestamp));
    });

    test('isSyncNeeded returns true when never synced', () async {
      final needsSync = await repo.isSyncNeeded(syncInterval: Duration(seconds: 60));

      expect(needsSync, isTrue);
    });

    test('isSyncNeeded returns false when recently synced', () async {
      final now = DateTime.now();
      await repo.setLastSyncTime(now.toIso8601String());

      final needsSync = await repo.isSyncNeeded(syncInterval: Duration(minutes: 5));

      expect(needsSync, isFalse);
    });

    test('isSyncNeeded returns true when sync interval exceeded', () async {
      final oneHourAgo = DateTime.now().subtract(Duration(hours: 1));
      await repo.setLastSyncTime(oneHourAgo.toIso8601String());

      final needsSync = await repo.isSyncNeeded(syncInterval: Duration(minutes: 30));

      expect(needsSync, isTrue);
    });

    test('setLastSyncError stores error message', () async {
      const error = 'Network timeout';

      await repo.setLastSyncError(error);

      final value = await repo.getLastSyncError();

      expect(value, equals(error));
    });

    test('clearLastSyncError deletes error message', () async {
      await repo.setLastSyncError('Network timeout');
      await repo.clearLastSyncError();

      final value = await repo.getLastSyncError();

      expect(value, isNull);
    });

    test('getAllSyncState returns all entries', () async {
      await repo.setSyncState('key1', 'value1');
      await repo.setSyncState('key2', 'value2');
      await repo.setSyncState('key3', 'value3');

      final allState = await repo.getAllSyncState();

      expect(allState.length, equals(3));
      expect(allState.any((s) => s.key == 'key1'), isTrue);
      expect(allState.any((s) => s.key == 'key2'), isTrue);
      expect(allState.any((s) => s.key == 'key3'), isTrue);
    });

    test('clearAllSyncState deletes all entries', () async {
      await repo.setSyncState('key1', 'value1');
      await repo.setSyncState('key2', 'value2');

      await repo.clearAllSyncState();

      final allState = await repo.getAllSyncState();

      expect(allState, isEmpty);
    });

    test('getSyncRetryCount returns default 0', () async {
      final count = await repo.getSyncRetryCount();

      expect(count, equals(0));
    });

    test('incrementSyncRetryCount increments counter', () async {
      await repo.incrementSyncRetryCount();
      await repo.incrementSyncRetryCount();

      final count = await repo.getSyncRetryCount();

      expect(count, equals(2));
    });

    test('resetSyncRetryCount resets to 0', () async {
      await repo.incrementSyncRetryCount();
      await repo.incrementSyncRetryCount();
      await repo.resetSyncRetryCount();

      final count = await repo.getSyncRetryCount();

      expect(count, equals(0));
    });
  });
}
