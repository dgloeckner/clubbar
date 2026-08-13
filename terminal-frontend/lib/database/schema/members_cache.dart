import 'package:drift/drift.dart';

class MembersCache extends Table {
  TextColumn get id => text()();
  TextColumn get cardUid => text().nullable().unique()();
  TextColumn get firstName => text().nullable()();
  TextColumn get lastName => text().nullable()();
  TextColumn get preferredLanguage => text()();
  IntColumn get isActive => integer().withDefault(Constant(1))();
  IntColumn get isSepaValid => integer()();
  IntColumn get balanceCents => integer().withDefault(const Constant(0))();
  TextColumn get updatedAt => text()();

  /// Server tombstone (ISO 8601). Set means the member was anonymized (GDPR
  /// erasure); their card must scan as unknown.
  ///
  /// The row itself is never removed — see the same field on `ProductsCache`.
  /// `transactions_local.member_id` references it, and deleting the row used to
  /// throw `FOREIGN KEY constraint failed` out of the first step of the sync
  /// cycle, wedging every later step with it.
  TextColumn get deletedAt => text().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}
