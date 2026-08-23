import 'package:drift/drift.dart';

class MembersCache extends Table {
  TextColumn get id => text()();
  TextColumn get cardUid => text().nullable().unique()();
  TextColumn get firstName => text().nullable()();
  TextColumn get lastName => text().nullable()();
  /// The member's date of birth as `YYYY-MM-DD`, for the Jugendschutz check
  /// (ADR-0045).
  ///
  /// The **raw date**, deliberately, and never an age in years: age changes on
  /// a day the server cannot predict a sync for, so a cached number is wrong
  /// from the member's next birthday until this terminal next reaches the
  /// server — which may be weeks. The age is computed here, at checkout, from
  /// this device's clock.
  ///
  /// Null means the member was **anonymized**, never "unknown": the field is
  /// required when a member is created, so there is no third state and no
  /// fail-open branch. An erasure arrives on the ordinary delta sync with this
  /// nulled, which is what takes the date back off every kiosk.
  ///
  /// Never render it, and never derive a rendered age from it (rule 6) — the
  /// screen is read by whoever is standing at the bar.
  TextColumn get dateOfBirth => text().nullable()();

  TextColumn get preferredLanguage => text()();

  /// This member's **own** credit ceiling in cents, or null (ADR-0046).
  ///
  /// An override, never an effective ceiling. Null means "follow the club
  /// default", which arrives separately on `GET /sync/config` and is cached in
  /// `config.json`; zero means a deliberate "no ceiling for this member". The
  /// two are different answers and must never be normalised into one another.
  ///
  /// Resolved at checkout by `CreditLimitPolicy.forMember()` — the terminal
  /// coalesces because it decides in front of the member with nothing
  /// reachable, and because a figure the backend resolved would go stale here
  /// the moment the club changed its default without touching this row.
  IntColumn get creditLimitCents => integer().nullable()();
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
