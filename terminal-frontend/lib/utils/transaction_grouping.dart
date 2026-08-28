import 'package:intl/intl.dart';

import '../models/transaction_list_item.dart';

/// Turns a flat, newest-first transaction list into the shape the member's
/// history is actually read in: **one heading per day, and one line per
/// product** rather than one line per bottle.
///
/// Two things made the flat list hard to read, and they compounded. A checkout
/// writes one immutable row *per unit* (`CartService.createTransaction` inserts
/// once per `item.quantity`), so a round of four beers is four identical rows —
/// same product, same price, same second. And every row repeated the full date,
/// so the eye had to compare timestamps to find where one evening ended and the
/// previous one began.
///
/// So: a heading per day, and inside it one line per product carrying how many.
///
/// ## What collapses, and what must not
///
/// Everything of the same product on the same day, however many visits it took
/// — two beers at 18:00 and two at 22:00 are `4 x Helles`. That is the point:
/// the member is reconciling a day, not an instant, and four lines saying the
/// same word are four lines they must read to learn one number.
///
/// A **storno never merges into the purchase it reverses**. Its wording is its
/// notes rather than a product name, so it groups on its own by construction —
/// and its amount is negative, so a merged line would net two opposite facts
/// into one that states neither.
///
/// **Price is part of what makes a line.** A product's price is frozen per
/// session at purchase time (`unit_price_cents`), so every unit bought in one
/// sitting agrees; a product repriced between two sessions on the same day is
/// the edge case, and it becomes *two* lines — `4 x Helles 10,00` and
/// `2 x Helles 5,60` — rather than one line whose unit price is a claim about
/// units that were not all sold at it. Every line can therefore always state
/// what one costs, which is the number a member checks against the shelf.
///
/// **A mixed sync status shows the least advanced one.** A badge summarising
/// rows of differing status has to pick one, and picking the *settled* end
/// would tell a member their evening is closed while part of it is still
/// queued. Picking the other end overstates only how much is still open, which
/// is the error that corrects itself.
class TransactionDay {
  /// Midnight of the day these entries fall on, in local time.
  final DateTime date;

  /// One entry per product, in the order the products first appear.
  final List<TransactionGroup> entries;

  const TransactionDay({required this.date, required this.entries});

  /// What the day's heading reads: `Fr, 28.08.` in German, `Fri, 28/08` in
  /// English. Numeric on purpose — a heading is scanned rather than read, and
  /// a month name makes every heading a different width.
  String heading(String locale) {
    if (locale != 'de') return DateFormat('E, dd/MM', 'en_GB').format(date);

    // ICU's German data abbreviates the weekday *with* a full stop ("Fr."),
    // which is not how it is written: Duden and DIN 1355 give Mo, Di, Mi, Do,
    // Fr, Sa, So without one. Formatted in two parts rather than by stripping
    // dots from the whole string, since the date half needs its trailing dot.
    final weekday = DateFormat('E', 'de_DE').format(date).replaceAll('.', '');
    return '$weekday, ${DateFormat('dd.MM.', 'de_DE').format(date)}';
  }

  /// The day's total — usually the number the history was opened for.
  int get totalCents => entries.fold(0, (sum, entry) => sum + entry.totalCents);
}

/// One line in the list: a product, and how many of it that day.
class TransactionGroup {
  /// The transactions this line stands for, newest first. Never empty.
  final List<TransactionListItem> items;

  const TransactionGroup(this.items);

  TransactionListItem get first => items.first;
  int get count => items.length;

  /// Decides whether the row shows a `4 x` prefix at all. A leading `1 x` on
  /// every single line is noise on a screen whose whole problem was noise.
  bool get isMultiple => items.length > 1;

  String get details => first.details;
  String? get productIcon => first.productIcon;

  /// The newest of the group. The day heading carries the date, so this exists
  /// for ordering rather than for display.
  DateTime get timestamp => first.timestamp;

  int get totalCents => items.fold(0, (sum, item) => sum + item.amountCents);

  /// The price of one. Always answerable, because price is part of the key
  /// that built this group.
  int get unitCents => first.amountCents;

  /// The least advanced status in the group (see the class doc).
  TransactionSyncStatus get syncStatus => items
      .map((item) => item.syncStatus)
      .reduce((a, b) => a.index < b.index ? a : b);
}

/// Groups [transactions] by local day, and by product within each day.
///
/// Input order is preserved: hand over newest-first and the days come back
/// newest-first, each product line sitting where that product first appeared.
List<TransactionDay> groupTransactionsByDay(
  List<TransactionListItem> transactions,
) {
  // Insertion-ordered, which is what keeps "first appearance" as the order
  // without a second sort — Dart's default Map is a LinkedHashMap.
  final byDay = <DateTime, Map<String, List<TransactionListItem>>>{};

  for (final transaction in transactions) {
    final local = transaction.timestamp.toLocal();
    final day = DateTime(local.year, local.month, local.day);

    final products = byDay.putIfAbsent(day, () => {});
    products.putIfAbsent(_productKey(transaction), () => []).add(transaction);
  }

  return byDay.entries
      .map((day) => TransactionDay(
            date: day.key,
            entries: day.value.values
                .map((items) => TransactionGroup(items))
                .toList(),
          ))
      .toList();
}

/// What makes two rows the same line: the same product at the same price.
///
/// The icon is in the key so that a storno (which has none) can never land on
/// a product's line even if somebody words its note exactly like the product.
/// The amount is in it so that a line can always state a unit price, and so
/// that a storno's negative amount never nets against the purchase it reverses.
String _productKey(TransactionListItem transaction) =>
    '${transaction.details}|${transaction.productIcon ?? ''}|${transaction.amountCents}';
