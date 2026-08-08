import 'package:drift/drift.dart';
import 'members_cache.dart';
import 'products_cache.dart';

class TransactionsLocal extends Table {
  TextColumn get id => text()();
  TextColumn get memberId => text().references(MembersCache, #id)();
  TextColumn get productId => text().nullable().references(ProductsCache, #id)();
  IntColumn get amountCents => integer()();
  TextColumn get transactionType => text()(); // 'purchase', 'storno', or 'payout'
  TextColumn get notes => text().nullable()();
  TextColumn get createdAt => text()();
  IntColumn get synced => integer().withDefault(Constant(0))();
  TextColumn get dispenserTxId => text().nullable()(); // ESP8266 transaction ID
  IntColumn get dispenserRequested => integer().nullable()(); // Requested quantity
  IntColumn get dispenserActual => integer().nullable()(); // Actual dispensed quantity
  TextColumn get sessionId => text().nullable()(); // Groups transactions by login session
  IntColumn get unitPriceCents => integer().nullable()(); // Per-unit price at time of purchase

  /// When the backend permanently refused this row (ISO 8601). Set means the
  /// row has left the sync queue for good: resubmitting it cannot help, so it
  /// is kept for staff to report rather than looping forever (issue #152).
  TextColumn get quarantinedAt => text().nullable()();

  /// Machine-readable rejection code the backend gave (`not_found`,
  /// `unstorable`), or a local reason the row can never be sent.
  TextColumn get quarantineReason => text().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}
