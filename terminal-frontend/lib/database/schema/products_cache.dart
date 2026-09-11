import 'package:drift/drift.dart';
import 'categories_cache.dart';

class ProductsCache extends Table {
  TextColumn get id => text()();
  TextColumn get categoryId => text().references(CategoriesCache, #id)();
  TextColumn get names => text()(); // JSON: {"de": "...", "en": "..."}
  TextColumn get descriptions => text().nullable()(); // JSON
  IntColumn get priceCents => integer()();
  IntColumn get isActive => integer().withDefault(Constant(1))();
  IntColumn get requiresDispenser => integer().withDefault(Constant(0))(); // 1=requires dispenser, 0=normal product
  /// Minimum legal age to buy this product, or null for unrestricted
  /// (ADR-0045). Compared against the age computed from the member's own
  /// `date_of_birth` at checkout, offline.
  ///
  /// Null is the ordinary state of most of a drinks list. A free integer
  /// rather than a `{16, 18}` enum: JuSchG's two thresholds are German law,
  /// and a club running this elsewhere sets its own numbers.
  IntColumn get minAge => integer().nullable()();

  /// The product's size in whole millilitres, or null when it has no size at
  /// all (ADR-0056) — a Sauna-Token, a Kaffee.
  ///
  /// Language-neutral, like the price and unlike `names`: the card formats it
  /// for the member's own language at draw time, `0,5 l` or `0.5 l`, from one
  /// stored number. It is what lets the tile carry a one-line name with the
  /// size in a badge beneath it.
  ///
  /// Null is not a size of zero, so nothing casts it.
  IntColumn get volumeMl => integer().nullable()();

  TextColumn get iconName => text().nullable()(); // Canonical kebab-case icon name (e.g., "beer-pils")
  TextColumn get updatedAt => text()();

  /// Server tombstone (ISO 8601). Set means the product was deleted in the admin
  /// panel and must be hidden from the purchase UI.
  ///
  /// The row itself is never removed. `transactions_local.product_id` references
  /// it under `PRAGMA foreign_keys = ON` with no `ON DELETE` clause, and synced
  /// transactions are retained indefinitely — so a physical delete would be
  /// refused by SQLite and abort the whole sync cycle. Keeping the row also lets
  /// transaction history and the quarantine banner still name the product.
  TextColumn get deletedAt => text().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}
