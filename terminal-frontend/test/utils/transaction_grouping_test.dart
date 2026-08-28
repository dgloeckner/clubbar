import 'package:clubbar_terminal/models/transaction_list_item.dart';
import 'package:clubbar_terminal/utils/transaction_grouping.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';

TransactionListItem tx({
  required String details,
  required DateTime at,
  int amountCents = 250,
  String? icon = 'beer-pils',
  TransactionSyncStatus status = TransactionSyncStatus.open,
  String id = '',
}) {
  return TransactionListItem(
    id: id.isEmpty ? '$details-${at.toIso8601String()}' : id,
    timestamp: at,
    details: details,
    amountCents: amountCents,
    syncStatus: status,
    productIcon: icon,
  );
}

void main() {
  setUpAll(() async {
    await initializeDateFormatting('de_DE');
    await initializeDateFormatting('en_GB');
  });

  group('groupTransactionsByDay', () {
    test('collapses a round of four into one line of four', () {
      final at = DateTime(2026, 8, 28, 19, 42);
      final days = groupTransactionsByDay([
        tx(details: 'Helles', at: at),
        tx(details: 'Helles', at: at),
        tx(details: 'Helles', at: at),
        tx(details: 'Helles', at: at),
      ]);

      expect(days, hasLength(1));
      expect(days.single.entries, hasLength(1));

      final line = days.single.entries.single;
      expect(line.count, 4);
      expect(line.isMultiple, isTrue);
      expect(line.details, 'Helles');
      expect(line.totalCents, 1000);
      expect(line.unitCents, 250);
    });

    // The clarification that shaped this: aggregation is per product per *day*,
    // not per checkout. Two visits to the bar are one line.
    test('aggregates the same product across separate visits that day', () {
      final days = groupTransactionsByDay([
        tx(details: 'Helles', at: DateTime(2026, 8, 28, 22, 10)),
        tx(details: 'Helles', at: DateTime(2026, 8, 28, 22, 10)),
        tx(details: 'Wasser', at: DateTime(2026, 8, 28, 20, 5), icon: 'water'),
        tx(details: 'Helles', at: DateTime(2026, 8, 28, 18, 0)),
        tx(details: 'Helles', at: DateTime(2026, 8, 28, 18, 0)),
      ]);

      expect(days, hasLength(1));
      expect(days.single.entries.map((e) => e.details), ['Helles', 'Wasser']);
      expect(days.single.entries.first.count, 4);
      expect(days.single.entries.first.totalCents, 1000);
    });

    test('splits days at local midnight, newest first', () {
      final days = groupTransactionsByDay([
        tx(details: 'Helles', at: DateTime(2026, 8, 28, 0, 5)),
        tx(details: 'Helles', at: DateTime(2026, 8, 27, 23, 55)),
      ]);

      expect(days, hasLength(2));
      expect(days.first.date, DateTime(2026, 8, 28));
      expect(days.last.date, DateTime(2026, 8, 27));
    });

    test('does not merge a storno into the product it reverses', () {
      final at = DateTime(2026, 8, 28, 19, 42);
      final days = groupTransactionsByDay([
        tx(details: 'Helles', at: at, amountCents: -250, icon: null),
        tx(details: 'Helles', at: at),
        tx(details: 'Helles', at: at),
      ]);

      final entries = days.single.entries;
      expect(entries, hasLength(2));
      expect(entries.first.count, 1);
      expect(entries.first.totalCents, -250);
      expect(entries.last.count, 2);
      expect(entries.last.totalCents, 500);
    });

    // Price is frozen per session, so this only happens when a product is
    // repriced between two sessions on the same day — the edge case that
    // decided the key. Two lines, each able to state what one cost.
    test('a repriced product becomes two lines, not one averaged one', () {
      final days = groupTransactionsByDay([
        tx(details: 'Helles', at: DateTime(2026, 8, 28, 21, 0), amountCents: 280),
        tx(details: 'Helles', at: DateTime(2026, 8, 28, 21, 0), amountCents: 280),
        tx(details: 'Helles', at: DateTime(2026, 8, 28, 18, 0), amountCents: 250),
      ]);

      final entries = days.single.entries;
      expect(entries, hasLength(2));
      expect(entries.first.count, 2);
      expect(entries.first.unitCents, 280);
      expect(entries.first.totalCents, 560);
      expect(entries.last.count, 1);
      expect(entries.last.unitCents, 250);
      expect(days.single.totalCents, 810);
    });

    test('a mixed-status line reports the least advanced status', () {
      final at = DateTime(2026, 8, 28, 19, 42);
      final days = groupTransactionsByDay([
        tx(details: 'Helles', at: at, status: TransactionSyncStatus.settled),
        tx(details: 'Helles', at: at, status: TransactionSyncStatus.unsynced),
      ]);

      expect(days.single.entries.single.syncStatus, TransactionSyncStatus.unsynced);
    });

    test('day total sums every line, storno included', () {
      final at = DateTime(2026, 8, 28, 19, 42);
      final days = groupTransactionsByDay([
        tx(details: 'Helles', at: at),
        tx(details: 'Helles', at: at),
        tx(details: 'Storno Helles', at: at, amountCents: -250, icon: null),
      ]);

      expect(days.single.totalCents, 250);
    });

    test('empty in, empty out', () {
      expect(groupTransactionsByDay([]), isEmpty);
    });
  });

  group('day heading', () {
    final day = TransactionDay(date: DateTime(2026, 8, 28), entries: const []);

    test('German reads as the sketch asked: Fr, 28.08.', () {
      expect(day.heading('de'), 'Fr, 28.08.');
    });

    test('English keeps the numeric shape in its own order', () {
      expect(day.heading('en'), 'Fri, 28/08');
    });
  });
}
