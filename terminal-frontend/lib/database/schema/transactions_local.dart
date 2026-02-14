import 'package:drift/drift.dart';
import 'members_cache.dart';
import 'products_cache.dart';

class TransactionsLocal extends Table {
  TextColumn get id => text()();
  TextColumn get memberId => text().references(MembersCache, #id)();
  TextColumn get productId => text().nullable().references(ProductsCache, #id)();
  IntColumn get amountCents => integer()();
  TextColumn get transactionType => text()(); // 'purchase' or 'correction'
  TextColumn get notes => text().nullable()();
  TextColumn get createdAt => text()();
  IntColumn get synced => integer().withDefault(Constant(0))();
  TextColumn get dispenserTxId => text().nullable()(); // ESP8266 transaction ID
  IntColumn get dispenserRequested => integer().nullable()(); // Requested quantity
  IntColumn get dispenserActual => integer().nullable()(); // Actual dispensed quantity

  @override
  Set<Column> get primaryKey => {id};
}
