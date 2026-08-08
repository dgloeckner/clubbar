import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/quarantine_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';

void main() {
  group('QuarantineProvider', () {
    late ClubBarDatabase db;
    late TransactionsRepository transactionsRepo;
    late QuarantineProvider provider;

    setUp(() async {
      db = ClubBarDatabase.forTesting(NativeDatabase.memory());
      transactionsRepo = TransactionsRepository(db);
      provider = QuarantineProvider(
        transactionsRepo: transactionsRepo,
        membersRepo: MembersRepository(db),
      );

      await db.into(db.membersCache).insert(
            MembersCacheCompanion(
              id: const Value('member-1'),
              cardUid: const Value('CARD-1'),
              firstName: const Value('Max'),
              lastName: const Value('Mustermann'),
              preferredLanguage: const Value('de'),
              isActive: const Value(1),
              isSepaValid: const Value(1),
              updatedAt: const Value('2025-02-01T12:00:00Z'),
            ),
          );
    });

    tearDown(() async {
      await db.close();
    });

    Future<void> insertSale(String id, {int amountCents = 350}) async {
      await db.into(db.transactionsLocal).insert(
            TransactionsLocalCompanion(
              id: Value(id),
              memberId: const Value('member-1'),
              amountCents: Value(amountCents),
              transactionType: const Value('purchase'),
              createdAt: const Value('2025-02-01T20:15:00Z'),
              synced: const Value(0),
            ),
          );
    }

    test('nothing quarantined means no staff warning', () async {
      await insertSale('txn-1');

      await provider.refresh();

      expect(provider.hasFailedSales, isFalse);
      expect(provider.failedSales, isEmpty);
    });

    test('a quarantined sale names the member, the amount and the time',
        () async {
      await insertSale('txn-1', amountCents: 350);
      await transactionsRepo.quarantineTransactions({'txn-1': 'unstorable'});

      await provider.refresh();

      expect(provider.hasFailedSales, isTrue);
      final sale = provider.failedSales.single;
      expect(sale.memberName, equals('Max Mustermann'));
      expect(sale.amountCents, equals(350));
      expect(sale.occurredAt, equals(DateTime.parse('2025-02-01T20:15:00Z')));
      expect(sale.reason, equals('unstorable'));
    });

    test('an anonymized member still yields a reportable sale', () async {
      await db.into(db.membersCache).insert(
            MembersCacheCompanion(
              id: const Value('member-gone'),
              cardUid: const Value('CARD-2'),
              preferredLanguage: const Value('de'),
              isActive: const Value(1),
              isSepaValid: const Value(1),
              updatedAt: const Value('2025-02-01T12:00:00Z'),
            ),
          );
      await db.into(db.transactionsLocal).insert(
            TransactionsLocalCompanion(
              id: const Value('txn-2'),
              memberId: const Value('member-gone'),
              amountCents: const Value(200),
              transactionType: const Value('purchase'),
              createdAt: const Value('2025-02-01T21:00:00Z'),
              synced: const Value(0),
            ),
          );
      await transactionsRepo.quarantineTransactions({'txn-2': 'not_found'});

      await provider.refresh();

      final sale = provider.failedSales.single;
      expect(sale.memberName, isNull);
      expect(sale.memberId, equals('member-gone'));
    });
  });
}
