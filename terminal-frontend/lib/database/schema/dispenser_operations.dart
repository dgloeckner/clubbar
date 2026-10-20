import 'package:drift/drift.dart';

/// Tracks in-progress dispenser operations for crash recovery.
///
/// Records are created BEFORE dispensing starts and deleted AFTER
/// transactions are created. If the app crashes in between, recovery
/// service will query the ESP8266 and create missing transactions.
///
/// Reconciliation fields enable periodic background service to:
/// - Detect ESP8266 crashes mid-dispense (dispensed > created)
/// - Prevent interference with active polling operations
/// - Create missing transactions when ESP reports higher count
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

  /// The terminal session the purchase belongs to (ADR-0027).
  ///
  /// Written when the tracking row is created, so that a row billed by the
  /// recovery service days later still carries the session the member bought
  /// in — recovery has no session of its own, and inventing one (or leaving it
  /// null) made recovery rows second-class next to checkout's (#945).
  ///
  /// Nullable because every row written before schema 15 has none.
  TextColumn get sessionId => text().nullable()();

  /// Whether the dispenser has ever answered for this `dispenser_tx_id`
  /// (1=yes, 0=not yet).
  ///
  /// Added here with `session_id` because both belong to one migration
  /// (confirmed by the owner, 2026-09-20). **#947 owns its semantics**: it is
  /// what separates "the POST never arrived, nothing was dispensed" from "the
  /// device lost a transaction it had accepted" when a later `GET` answers
  /// 404. Until #947 lands, nothing reads it.
  IntColumn get acknowledged => integer().withDefault(const Constant(0))();

  // ========== RECONCILIATION FIELDS ==========

  /// How many transactions we've already created for this operation
  /// Used by recovery service to detect missing transactions
  IntColumn get transactionsCreated => integer().withDefault(const Constant(0))();

  /// Last known state from ESP8266 ("dispensing", "done", "error")
  /// Determines when operation can be cleaned up
  TextColumn get lastKnownState => text().nullable()();

  /// Last known dispensed count from ESP8266
  /// Compared against transactionsCreated to detect discrepancies
  IntColumn get lastKnownDispensed => integer().withDefault(const Constant(0))();

  /// Last time we polled ESP8266 (ISO timestamp)
  /// Recovery service skips operations polled within last 30 seconds
  TextColumn get lastPolledAt => text().nullable()();

  /// Whether polling is currently active (1=true, 0=false)
  /// Recovery service skips operations where polling_active = 1
  IntColumn get pollingActive => integer().withDefault(const Constant(0))();

  @override
  Set<Column> get primaryKey => {dispenserTxId};
}
