// Migrations are exercised against a real on-disk database: the upgrade path is
// what every already-installed terminal takes, and it is the one path a fresh
// `onCreate` never covers.
import 'dart:io';

import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/utils/age.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';

void main() {
  late Directory tempDir;
  late File dbFile;

  setUp(() {
    tempDir = Directory.systemTemp.createTempSync('clubbar_migration_test');
    dbFile = File(p.join(tempDir.path, 'clubbar.sqlite'));
  });

  tearDown(() {
    if (tempDir.existsSync()) tempDir.deleteSync(recursive: true);
  });

  ClubBarDatabase openDatabase() =>
      ClubBarDatabase.forTesting(NativeDatabase(dbFile));

  /// Seed [cardUids] into a fresh database and rewind it to schema [version], so
  /// that the next open runs the upgrade path from there.
  Future<void> seedAtSchemaVersion(
      int version, Map<String, String?> cardUids) async {
    final db = openDatabase();
    for (final entry in cardUids.entries) {
      // Inserted raw rather than through MembersRepository: the point is a row
      // that predates the repository's normalization.
      await db.into(db.membersCache).insert(
            MembersCacheCompanion(
              id: Value(entry.key),
              cardUid: Value(entry.value),
              firstName: Value(entry.key),
              lastName: const Value('Member'),
              preferredLanguage: const Value('de'),
              isActive: const Value(1),
              isSepaValid: const Value(1),
              updatedAt: const Value('2025-02-01T10:00:00Z'),
            ),
          );
    }
    await db.customStatement('PRAGMA user_version = $version');
    await db.close();
  }

  // Issue #18: card UIDs are canonical from now on, but a member synced before
  // the fix keeps their lower-case UID — and stays unscannable — until the
  // backend happens to touch them again. The upgrade rewrites those rows.
  group('schema 8: card UID canonicalization', () {
    test('upper-cases a UID cached before the fix', () async {
      await seedAtSchemaVersion(7, {'member-legacy': 'abcd1234'});

      final db = openDatabase();
      final stored = await db.select(db.membersCache).getSingle();

      expect(stored.cardUid, equals('ABCD1234'));
      await db.close();
    });

    test('the migrated member is found by a scan again', () async {
      await seedAtSchemaVersion(7, {'member-legacy': 'abcd1234'});

      final db = openDatabase();
      final (member, error) =
          await MembersRepository(db).findByCardUid('abcd1234');

      expect(member?.id, equals('member-legacy'));
      expect(error, isNull);
      await db.close();
    });

    test('leaves an already canonical UID and a card-less member alone',
        () async {
      await seedAtSchemaVersion(7, {
        'member-canonical': 'ABCD1234',
        'member-anonymized': null,
      });

      final db = openDatabase();
      final stored = await db.select(db.membersCache).get();

      expect(
        {for (final m in stored) m.id: m.cardUid},
        equals({'member-canonical': 'ABCD1234', 'member-anonymized': null}),
      );
      await db.close();
    });

    test('a case-only duplicate does not abort the upgrade', () async {
      // card_uid is UNIQUE, so upper-casing both of these would collide. The
      // migration skips the loser instead of taking the terminal down on
      // startup; the next sync of either member resolves it.
      await seedAtSchemaVersion(7, {
        'member-upper': 'ABCD1234',
        'member-lower': 'abcd1234',
      });

      final db = openDatabase();
      final stored = await db.select(db.membersCache).get();

      expect(stored.length, equals(2), reason: 'no row is lost');
      final upper = stored.firstWhere((m) => m.id == 'member-upper');
      expect(upper.cardUid, equals('ABCD1234'));
      // The colliding row keeps its old value — still unscannable, but the
      // other member (and the terminal) is unaffected.
      final (found, _) = await MembersRepository(db).findByCardUid('abcd1234');
      expect(found?.id, equals('member-upper'));
      await db.close();
    });

    test('an unknown card is still unknown after the upgrade', () async {
      await seedAtSchemaVersion(7, {'member-legacy': 'abcd1234'});

      final db = openDatabase();
      final (member, error) =
          await MembersRepository(db).findByCardUid('ffff0000');

      expect(member, isNull);
      expect(error, equals(TerminalErrorKey.unknownCard));
      await db.close();
    });
  });

  // Issue #152: a terminal upgraded mid-service carries unsynced sales across
  // the upgrade, and must be able to quarantine one immediately afterwards.
  group('schema 9: quarantine', () {
    test('an unsynced sale survives the upgrade and can be quarantined',
        () async {
      await seedAtSchemaVersion(8, {'member-1': 'ABCD1234'});
      var db = openDatabase();
      await db.into(db.transactionsLocal).insert(
            TransactionsLocalCompanion(
              id: const Value('txn-pending'),
              memberId: const Value('member-1'),
              amountCents: const Value(350),
              transactionType: const Value('purchase'),
              createdAt: const Value('2025-02-01T20:15:00Z'),
              synced: const Value(0),
            ),
          );
      await db.customStatement('PRAGMA user_version = 8');
      await db.close();

      db = openDatabase();
      final repo = TransactionsRepository(db);

      expect((await repo.getUnsyncedTransactions()).map((t) => t.id),
          equals(['txn-pending']));

      await repo.quarantineTransactions({'txn-pending': 'unstorable'});

      expect(await repo.getUnsyncedTransactions(), isEmpty);
      expect((await repo.getQuarantinedTransactions()).single.quarantineReason,
          equals('unstorable'));
      await db.close();
    });
  });

  /// The per-member credit ceiling (ADR-0047, #561).
  ///
  /// The riskiest upgrade in the epic, because terminals upgrade in place while
  /// holding sales that have never reached the backend. Two things therefore
  /// have to hold: the column arrives NULL — "follow the club default", so
  /// every cached member keeps behaving exactly as they did — and nothing in
  /// `transactions_local` is touched on the way.
  ///
  /// A `NOT NULL DEFAULT 0` would have read as "no ceiling for this member" and
  /// quietly granted the whole membership unlimited credit on first launch,
  /// which is the mirror image of the `min_age` trap schema 11 guards against.
  group('schema 12: the per-member credit ceiling', () {
    test('lands NULL, so every cached member follows the club', () async {
      await seedAtSchemaVersion(11, {'member-1': 'ABCD1234'});

      final db = openDatabase();

      expect(
        (await db.select(db.membersCache).getSingle()).creditLimitCents,
        isNull,
      );
      await db.close();
    });

    test('an upgrade preserves transactions this terminal has not uploaded',
        () async {
      await seedAtSchemaVersion(11, {'member-1': 'ABCD1234'});
      var db = openDatabase();
      await db.into(db.transactionsLocal).insert(
            TransactionsLocalCompanion(
              id: const Value('txn-unsynced-1'),
              memberId: const Value('member-1'),
              amountCents: const Value(350),
              transactionType: const Value('purchase'),
              createdAt: const Value('2026-08-20T19:00:00Z'),
              synced: const Value(0),
            ),
          );
      await db.customStatement('PRAGMA user_version = 11');
      await db.close();

      db = openDatabase();
      final repo = TransactionsRepository(db);
      final unsynced = await repo.getUnsyncedTransactions();

      expect(
        unsynced.map((t) => t.id),
        contains('txn-unsynced-1'),
        reason: 'a sale rung before the upgrade must still be uploadable after it',
      );
      expect(unsynced.single.amountCents, equals(350));
      await db.close();
    });

    /// Zero has to survive a round trip through the cache, because it is a
    /// value and not an absence: a member deliberately left uncapped must not
    /// come back reading as "follow the club default".
    test('stores zero and null as the different answers they are', () async {
      final db = openDatabase();
      await db.into(db.membersCache).insert(
            MembersCacheCompanion(
              id: const Value('member-uncapped'),
              preferredLanguage: const Value('de'),
              isActive: const Value(1),
              isSepaValid: const Value(1),
              updatedAt: const Value('2026-08-20T10:00:00Z'),
              creditLimitCents: const Value(0),
            ),
          );
      await db.into(db.membersCache).insert(
            MembersCacheCompanion(
              id: const Value('member-inherits'),
              preferredLanguage: const Value('de'),
              isActive: const Value(1),
              isSepaValid: const Value(1),
              updatedAt: const Value('2026-08-20T10:00:00Z'),
            ),
          );

      final rows = await db.select(db.membersCache).get();

      expect(
        rows.firstWhere((m) => m.id == 'member-uncapped').creditLimitCents,
        equals(0),
      );
      expect(
        rows.firstWhere((m) => m.id == 'member-inherits').creditLimitCents,
        isNull,
      );
      await db.close();
    });
  });

  /// Jugendschutz (epic #582 M6, ADR-0045). Both columns land nullable with no
  /// default, and for a cache written before the upgrade that is exactly right:
  /// nothing already stored is known to carry an age limit, and no member's
  /// birth date is known until the next delta sync delivers it.
  ///
  /// Which way that fails matters. A pre-upgrade product has `min_age` NULL —
  /// unrestricted — so the grid does not go blank on first launch; a
  /// pre-upgrade member has no birth date, so anything that *does* carry a
  /// limit is refused until one sync cycle fills it in. Refusing is the safe
  /// direction, and it is temporary.
  group('schema 11: Jugendschutz columns', () {
    test('adds them to both caches, defaulting to NULL', () async {
      await seedAtSchemaVersion(10, {'member-1': 'ABCD1234'});
      var db = openDatabase();
      await db.into(db.categoriesCache).insert(
            CategoriesCacheCompanion(
              id: const Value('cat-1'),
              names: const Value('{"de":"Getränke"}'),
              isActive: const Value(1),
              updatedAt: const Value('2025-02-01T10:00:00Z'),
            ),
          );
      await db.into(db.productsCache).insert(
            ProductsCacheCompanion(
              id: const Value('prod-1'),
              categoryId: const Value('cat-1'),
              names: const Value('{"de":"Pils"}'),
              priceCents: const Value(350),
              isActive: const Value(1),
              updatedAt: const Value('2025-02-01T10:00:00Z'),
            ),
          );
      await db.customStatement('PRAGMA user_version = 10');
      await db.close();

      db = openDatabase();

      expect((await db.select(db.membersCache).getSingle()).dateOfBirth, isNull);
      expect((await db.select(db.productsCache).getSingle()).minAge, isNull);
      await db.close();
    });

    /// The regression this guards: a NOT NULL default of 0 on `min_age` would
    /// read as "requires age 0", which nobody satisfies — every drink on the
    /// terminal would refuse every member after an upgrade.
    test('a product cached before the upgrade is unrestricted, not age-zero',
        () async {
      await seedAtSchemaVersion(10, {'member-1': 'ABCD1234'});
      var db = openDatabase();
      await db.into(db.categoriesCache).insert(
            CategoriesCacheCompanion(
              id: const Value('cat-1'),
              names: const Value('{"de":"Getränke"}'),
              isActive: const Value(1),
              updatedAt: const Value('2025-02-01T10:00:00Z'),
            ),
          );
      await db.into(db.productsCache).insert(
            ProductsCacheCompanion(
              id: const Value('prod-1'),
              categoryId: const Value('cat-1'),
              names: const Value('{"de":"Pils"}'),
              priceCents: const Value(350),
              isActive: const Value(1),
              updatedAt: const Value('2025-02-01T10:00:00Z'),
            ),
          );
      await db.customStatement('PRAGMA user_version = 10');
      await db.close();

      db = openDatabase();
      final product = await db.select(db.productsCache).getSingle();

      expect(product.minAge, isNull);
      expect(
        mayBuyAtAge(null, product.minAge, DateTime.now()),
        isTrue,
        reason: 'an unrestricted drink stays buyable even with no birth date',
      );
      await db.close();
    });
  });

  // Tombstones for members, categories and products. Nothing cached before this
  // migration is known to be deleted, so every existing row must come through
  // with a NULL `deleted_at` — a non-null default would blank the terminal's
  // whole product grid on the first launch after the upgrade.
  group('schema 10: tombstones', () {
    test('adds deleted_at to all three cache tables, defaulting to NULL',
        () async {
      await seedAtSchemaVersion(9, {'member-1': 'ABCD1234'});
      var db = openDatabase();
      await db.into(db.categoriesCache).insert(
            CategoriesCacheCompanion(
              id: const Value('cat-1'),
              names: const Value('{"de":"Getränke"}'),
              isActive: const Value(1),
              updatedAt: const Value('2025-02-01T10:00:00Z'),
            ),
          );
      await db.into(db.productsCache).insert(
            ProductsCacheCompanion(
              id: const Value('prod-1'),
              categoryId: const Value('cat-1'),
              names: const Value('{"de":"Pils"}'),
              priceCents: const Value(350),
              isActive: const Value(1),
              updatedAt: const Value('2025-02-01T10:00:00Z'),
            ),
          );
      await db.customStatement('PRAGMA user_version = 9');
      await db.close();

      db = openDatabase();

      expect((await db.select(db.membersCache).getSingle()).deletedAt, isNull);
      expect(
          (await db.select(db.categoriesCache).getSingle()).deletedAt, isNull);
      expect((await db.select(db.productsCache).getSingle()).deletedAt, isNull);
      await db.close();
    });

    test('a product cached before the upgrade is still sellable after it',
        () async {
      await seedAtSchemaVersion(9, {'member-1': 'ABCD1234'});
      var db = openDatabase();
      await db.into(db.categoriesCache).insert(
            CategoriesCacheCompanion(
              id: const Value('cat-1'),
              names: const Value('{"de":"Getränke"}'),
              isActive: const Value(1),
              updatedAt: const Value('2025-02-01T10:00:00Z'),
            ),
          );
      await db.into(db.productsCache).insert(
            ProductsCacheCompanion(
              id: const Value('prod-1'),
              categoryId: const Value('cat-1'),
              names: const Value('{"de":"Pils"}'),
              priceCents: const Value(350),
              isActive: const Value(1),
              updatedAt: const Value('2025-02-01T10:00:00Z'),
            ),
          );
      await db.customStatement('PRAGMA user_version = 9');
      await db.close();

      db = openDatabase();
      final grid = await ProductsRepository(db).getActiveCategoriesWithProducts();

      expect(grid.single.$2.single.id, equals('prod-1'));
      await db.close();
    });
  });
}
