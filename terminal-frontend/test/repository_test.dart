import 'dart:io';
import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/category_dto.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/repository/products_repository.dart';

void main() {
  group('MembersRepository', () {
    late RuderbarDatabase db;
    late MembersRepository repo;

    setUp(() async {
      db = RuderbarDatabase();
      repo = MembersRepository(db);
    });

    tearDown(() async {
      await db.close();
      // Clean up database file
      final file = File('ruderbar_terminal.db');
      if (file.existsSync()) {
        file.deleteSync();
      }
    });

    test('findByCardUid returns null and error for unknown card', () async {
      final (member, error) = await repo.findByCardUid('unknown-card-uid');

      expect(member, isNull);
      expect(error, equals('Unknown card'));
    });

    test('findByCardUid returns member for valid card', () async {
      // Insert test member
      await db.into(db.membersCache).insert(
        MembersCacheCompanion(
          id: const Value('member-1'),
          cardUid: const Value('card-uid-123'),
          firstName: const Value('John'),
          lastName: const Value('Doe'),
          preferredLanguage: const Value('de'),
          isActive: const Value(1),
          isSepaValid: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      final (member, error) = await repo.findByCardUid('card-uid-123');

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
          cardUid: const Value('card-inactive'),
          firstName: const Value('Jane'),
          lastName: const Value('Doe'),
          preferredLanguage: const Value('de'),
          isActive: const Value(0),
          isSepaValid: const Value(1),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      final (member, error) = await repo.findByCardUid('card-inactive');

      expect(member, isNull);
      expect(error, equals('Account inactive'));
    });

    test('findByCardUid returns error for missing SEPA mandate', () async {
      // Insert member without SEPA
      await db.into(db.membersCache).insert(
        MembersCacheCompanion(
          id: const Value('member-no-sepa'),
          cardUid: const Value('card-no-sepa'),
          firstName: const Value('Bob'),
          lastName: const Value('Smith'),
          preferredLanguage: const Value('de'),
          isActive: const Value(1),
          isSepaValid: const Value(0),
          updatedAt: const Value('2025-02-01T12:00:00Z'),
        ),
      );

      final (member, error) = await repo.findByCardUid('card-no-sepa');

      expect(member, isNull);
      expect(error, equals('SEPA mandate missing'));
    });

    test('upsertMembers inserts new members', () async {
      final dtos = [
        MemberDTO(
          id: 'member-1',
          cardUid: 'card-1',
          firstName: 'Alice',
          lastName: 'Johnson',
          preferredLanguage: 'de',
          isActive: true,
          isSepaValid: true,
          updatedAt: '2025-02-01T12:00:00Z',
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
        MemberDTO(
          id: 'member-1',
          cardUid: 'card-1',
          firstName: 'Alice',
          lastName: 'Smith', // Changed
          preferredLanguage: 'en', // Changed
          isActive: false,
          isSepaValid: true,
          updatedAt: '2025-02-02T12:00:00Z',
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
          cardUid: const Value('card-inactive'),
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
    late RuderbarDatabase db;
    late ProductsRepository repo;

    setUp(() async {
      db = RuderbarDatabase();
      repo = ProductsRepository(db);
    });

    tearDown(() async {
      await db.close();
      // Clean up database file
      final file = File('ruderbar_terminal.db');
      if (file.existsSync()) {
        file.deleteSync();
      }
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
          displayOrder: const Value(1),
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
          displayOrder: const Value(1),
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
          displayOrder: const Value(1),
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
          displayOrder: const Value(1),
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
        CategoryDTO(
          id: 'cat-1',
          names: {'de': 'Getränke', 'en': 'Beverages'},
          displayOrder: 1,
          isActive: true,
          updatedAt: '2025-02-01T12:00:00Z',
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
        CategoryDTO(
          id: 'cat-1',
          names: {'de': 'Getränke'},
          displayOrder: 1,
          isActive: true,
          updatedAt: '2025-02-01T12:00:00Z',
        ),
      ];
      await repo.upsertCategories(categoryDtos);

      final dtos = [
        ProductDTO(
          id: 'prod-1',
          categoryId: 'cat-1',
          names: {'de': 'Pils'},
          descriptions: {'de': 'German beer'},
          priceCents: 350,
          isActive: true,
          updatedAt: '2025-02-01T12:00:00Z',
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
          displayOrder: const Value(1),
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
}
