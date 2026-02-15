import 'package:drift/drift.dart';

/// Tracks in-progress dispenser operations for crash recovery.
///
/// Records are created BEFORE dispensing starts and deleted AFTER
/// transactions are created. If the app crashes in between, recovery
/// service will query the ESP8266 and create missing transactions.
class DispenserOperations extends Table {
  /// ESP8266 transaction ID (primary key)
  TextColumn get dispenserTxId => text()();

  /// Member ID who initiated the purchase
  TextColumn get memberId => text()();

  /// Product ID being purchased (token product)
  TextColumn get productId => text()();

  /// Price per token in cents
  IntColumn get priceCents => integer()();

  /// Number of tokens requested from dispenser
  IntColumn get requestedQty => integer()();

  /// When this operation was started
  TextColumn get createdAt => text()();

  @override
  Set<Column> get primaryKey => {dispenserTxId};
}
