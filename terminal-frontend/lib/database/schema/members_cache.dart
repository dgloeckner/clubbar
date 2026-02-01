import 'package:drift/drift.dart';

class MembersCache extends Table {
  TextColumn get id => text()();
  TextColumn get cardUid => text().nullable().unique()();
  TextColumn get firstName => text().nullable()();
  TextColumn get lastName => text().nullable()();
  TextColumn get preferredLanguage => text()();
  IntColumn get isActive => integer().withDefault(Constant(1))();
  IntColumn get isSepaValid => integer()();
  TextColumn get updatedAt => text()();

  @override
  Set<Column> get primaryKey => {id};
}
