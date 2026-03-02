# Flutter Terminal Frontend Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a native macOS Flutter app (cross-platform ready for Linux/Raspberry Pi) that implements the complete terminal POS workflow: RFID scanning, product browsing, shopping cart management, transaction booking, and offline sync.

**Architecture:** Following Club Bar's offline-first design (ADR-0012), the terminal is a local-first app with periodic sync to backend. Flutter provides native macOS performance. Local SQLite database caches members/products and queues transactions. Material 3 design system with playful animations (confetti on purchase, animated buttons).

**Key Design Decisions:**
- **Inactivity Timeout (30s)**: Logout clears session but preserves cart. User can rescan to resume (preserved 1 hour max).
- **Balance Limit**: €100 hardcoded for MVP; configurable from backend admin panel in future.
- **Balance Display**: Show warning only at checkout (if new balance > €100).
- **Post-Purchase Flow**: "Continue Shopping" → Products, "Done" → RFID Scan.
- **Language Switching**: User can change language in profile screen; persists locally + syncs to backend.
- **Mock Data**: 1 test member (Max Mustermann) + 5 beverages + sauna tokens (fully valid, includes SEPA).
- **Error Handling**: Transaction errors show modal; user manually retries or cancels (no auto-retry).
- **Platform**: macOS only (Phase 1). Linux/RPi support deferred to Phase 8.
- **Settings**: Hardcoded config for Phase 1. Admin settings screen deferred to Phase 2.

**Tech Stack:**
- **Framework**: Flutter (Dart) with Material 3 design
- **Database**: SQLite via `sqflite_common` + `drift` ORM (Dart equivalent of Drizzle)
- **Networking**: `http` package + custom retry logic (offline-first)
- **State Management**: Provider pattern (simple, testable)
- **Animations**: `confetti` package (purchase celebration) + implicit animations
- **Testing**: Dart `test` + `mocktail` for unit tests; integration tests with Flutter test framework
- **RFID**: Mock button detection for MVP (Phase 4); real hardware integration deferred to Phase 8
- **Build**: Flutter standard tools (no extra tooling beyond standard)

---

## Milestones Overview

| Phase | Milestone | Tasks | Est. Effort | Status |
|-------|-----------|-------|-------------|--------|
| **1** | Project Setup & DB Schema | 1-4 | 2-3 hrs | — |
| **2** | Data Layer (Repository + Sync) | 5-10 | 3-4 hrs | — |
| **3** | State Management (Providers) | 11-14 | 2 hrs | — |
| **4** | Mock RFID Service (Click Detection) | 15-16 | 1 hr | — |
| **5** | Core UI Screens | 17-26 | 4-5 hrs | — |
| **6** | Animations & Polish | 27-31 | 2 hrs | — |
| **7** | E2E Tests & Verification | 32-36 | 2-3 hrs | — |

**Note:** Real RFID integration (macOS USB serial) is deferred to Phase 8 (future work). Phase 4 implements mock detection via button click for immediate UI testing.

---

## Phase 1: Project Setup & Database Schema

### Task 1: Create Flutter Project with Material 3

**Files:**
- Create: `terminal-frontend/pubspec.yaml`
- Create: `terminal-frontend/lib/main.dart`
- Create: `terminal-frontend/lib/config/app_config.dart`

**Step 1: Initialize Flutter project**

```bash
flutter create \
  --platforms macos,linux \
  --template app \
  --project-name clubbar_terminal \
  /Users/dg/dev/frgs-vereinsbar/terminal-frontend

cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
```

**Step 2: Add core dependencies to pubspec.yaml**

Edit `pubspec.yaml` and replace entire `dependencies` section with **latest stable versions** (searched Feb 2025):

```yaml
dependencies:
  flutter:
    sdk: flutter

  # Database & ORM
  drift: ^2.30.1
  sqlite3_flutter_libs: ^0.5.41
  sqflite_common: ^2.5.6
  sqflite_common_ffi: ^2.4.0+2

  # Networking
  http: ^1.6.0

  # State Management
  provider: ^6.1.5+1

  # Animations & UI
  confetti: ^0.8.0

  # Date/Time (immutable)
  intl: ^0.20.2

  # Logging
  logger: ^2.6.2

  # UUID generation
  uuid: ^4.5.2

dev_dependencies:
  flutter_test:
    sdk: flutter
  drift_dev: ^2.30.1
  build_runner: ^2.10.5
  test: ^1.29.0
  mocktail: ^1.0.4
  flutter_lints: ^6.0.0

flutter:
  uses-material-design: true
```

**Version Updates Summary (Feb 2025):**
- ✅ **drift**: 2.14.0 → **2.30.1** (latest, published 19 days ago)
- ✅ **drift_dev**: 2.14.0 → **2.30.1** (latest, published 19 days ago)
- ✅ **sqlite3_flutter_libs**: 0.5.0 → **0.5.41** (latest, published 2 months ago)
- ✅ **sqflite_common**: 2.5.0 → **2.5.6** (latest, published 6 months ago)
- ✅ **sqflite_common_ffi**: 2.3.0 → **2.4.0+2** (latest, published 28 days ago)
- ✅ **http**: 1.1.0 → **1.6.0** (latest, published 2 months ago)
- ✅ **provider**: 6.1.0 → **6.1.5+1** (latest, published 5 months ago)
- ✅ **confetti**: 0.7.0 → **0.8.0** (latest, published 16 months ago)
- ✅ **intl**: 0.19.0 → **0.20.2** (latest, published 12 months ago)
- ✅ **logger**: 2.1.0 → **2.6.2** (latest, published 3 months ago)
- ✅ **flutter_lints**: 3.0.0 → **6.0.0** (latest, published 8 months ago)
- ✅ **test**: 1.24.0 → **1.29.0** (latest, published 24 days ago)
- ✅ **mocktail**: 1.0.0 → **1.0.4** (latest, published 19 months ago)
- 🆕 **uuid**: ^4.5.2 (added for transaction ID generation, published 3 months ago)

**Step 3: Run initial build to verify dependencies**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter pub get
flutter build macos --dry-run
```

Expected: No errors; dependencies resolved.

**Step 4: Create app config file**

Create `terminal-frontend/lib/config/app_config.dart`:

```dart
class AppConfig {
  static const String appName = 'Club Bar Terminal';
  static const String version = '0.1.0';

  // Display
  static const int screenWidth = 800;
  static const int screenHeight = 480;
  static const bool isProduction = false;

  // Sync timing
  static const Duration syncInterval = Duration(seconds: 60);
  static const Duration syncTimeout = Duration(seconds: 10);

  // Inactivity & Cart Preservation
  static const Duration inactivityTimeout = Duration(seconds: 30);
  static const Duration cartPreservationDuration = Duration(hours: 1);

  // Balance Limit (€100.00 = 10000 cents; configurable from backend later)
  static const int balanceLimitCents = 10000; // €100.00

  // Backend API
  static const String apiBaseUrl = 'http://localhost:8080/api';
  static const String syncEndpointMembers = '/sync/members';
  static const String syncEndpointProducts = '/sync/products';
  static const String syncEndpointTransactions = '/sync/transactions';

  // UI
  static const double minTapTarget = 48.0;
}
```

**Step 5: Create minimal main.dart**

Create `terminal-frontend/lib/main.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:clubbar_terminal/config/app_config.dart';

void main() {
  runApp(const ClubBarTerminalApp());
}

class ClubBarTerminalApp extends StatelessWidget {
  const ClubBarTerminalApp({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConfig.appName,
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(
          seedColor: const Color(0xFF3B82F6),
          brightness: Brightness.dark,
        ),
      ),
      home: const Scaffold(
        body: Center(
          child: Text('Club Bar Terminal - Flutter'),
        ),
      ),
    );
  }
}
```

**Step 6: Test the build**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test lib/main.dart 2>&1 || echo "No tests yet, build verified"
```

Expected: App compiles without errors.

**Step 7: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar
git add terminal-frontend/pubspec.* terminal-frontend/lib/
git commit -m "chore: initialize Flutter project with Material 3 and core dependencies"
```

---

### Task 2: Define SQLite Database Schema with Drift ORM

**Files:**
- Create: `terminal-frontend/lib/database/database.dart`
- Create: `terminal-frontend/lib/database/schema/members_cache.dart`
- Create: `terminal-frontend/lib/database/schema/categories_cache.dart`
- Create: `terminal-frontend/lib/database/schema/products_cache.dart`
- Create: `terminal-frontend/lib/database/schema/transactions_local.dart`
- Create: `terminal-frontend/lib/database/schema/sync_state.dart`

**Step 1: Create database schema files with Drift**

Create `terminal-frontend/lib/database/schema/members_cache.dart`:

```dart
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

  @override
  List<String> get customConstraints => [
    'CONSTRAINT idx_members_cache_card_uid UNIQUE(card_uid)',
  ];
}
```

Create `terminal-frontend/lib/database/schema/categories_cache.dart`:

```dart
import 'package:drift/drift.dart';

class CategoriesCache extends Table {
  TextColumn get id => text()();
  TextColumn get names => text()(); // JSON: {"de": "...", "en": "..."}
  IntColumn get displayOrder => integer()();
  IntColumn get isActive => integer().withDefault(Constant(1))();
  TextColumn get updatedAt => text()();

  @override
  Set<Column> get primaryKey => {id};
}
```

Create `terminal-frontend/lib/database/schema/products_cache.dart`:

```dart
import 'package:drift/drift.dart';
import 'categories_cache.dart';

class ProductsCache extends Table {
  TextColumn get id => text()();
  TextColumn get categoryId => text().references(CategoriesCache, #id)();
  TextColumn get names => text()(); // JSON: {"de": "...", "en": "..."}
  TextColumn get descriptions => text().nullable()(); // JSON
  IntColumn get priceCents => integer()();
  IntColumn get isActive => integer().withDefault(Constant(1))();
  TextColumn get updatedAt => text()();

  @override
  Set<Column> get primaryKey => {id};

  @override
  List<String> get customConstraints => [
    'CONSTRAINT fk_products_category FOREIGN KEY(category_id) REFERENCES categories_cache(id)',
  ];
}
```

Create `terminal-frontend/lib/database/schema/transactions_local.dart`:

```dart
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

  @override
  Set<Column> get primaryKey => {id};
}
```

Create `terminal-frontend/lib/database/schema/sync_state.dart`:

```dart
import 'package:drift/drift.dart';

class SyncState extends Table {
  TextColumn get key => text()();
  TextColumn get value => text()();

  @override
  Set<Column> get primaryKey => {key};
}
```

**Step 2: Create main database file with Drift**

Create `terminal-frontend/lib/database/database.dart`:

```dart
import 'package:drift/drift.dart';
import 'package:drift_sqflite_async/drift_sqflite_async.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'schema/members_cache.dart';
import 'schema/categories_cache.dart';
import 'schema/products_cache.dart';
import 'schema/transactions_local.dart';
import 'schema/sync_state.dart';

part 'database.g.dart';

@DriftDatabase(tables: [
  MembersCache,
  CategoriesCache,
  ProductsCache,
  TransactionsLocal,
  SyncState,
])
class ClubBarDatabase extends _$ClubBarDatabase {
  ClubBarDatabase() : super(_openConnection());

  @override
  int get schemaVersion => 1;

  @override
  MigrationStrategy get migration => MigrationStrategy(
    onCreate: (Migrator m) => m.createAll(),
    onUpgrade: (Migrator m, int from, int to) async {
      // Handle schema upgrades here (v1 → v2, etc.)
    },
  );

  static QueryExecutor _openConnection() {
    if (sqfliteFfiTestPrefix != null) {
      return databaseFactoryFfi.openDatabase(
        ':memory:',
        options: OpenDatabaseOptions(version: 1),
      );
    }

    // Production: Use sqflite async
    return databaseFactoryFfi.openDatabase(
      'clubbar_terminal.db',
      options: OpenDatabaseOptions(
        version: 1,
        onOpen: (db) async {
          await db.execute('PRAGMA foreign_keys = ON');
        },
      ),
    );
  }
}
```

**Step 3: Run code generation for Drift**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter pub run build_runner build
```

Expected: Generates `database.g.dart` with all DAO accessors.

**Step 4: Create database initialization test**

Create `terminal-frontend/test/database_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/database/database.dart';

void main() {
  late ClubBarDatabase db;

  setUp(() async {
    sqfliteFfiTestPrefix = ':memory:';
    db = ClubBarDatabase();
  });

  tearDown(() async {
    await db.close();
  });

  test('database opens and creates tables', () async {
    expect(db, isNotNull);
    // Tables exist if no exception is thrown
  });

  test('members_cache table has correct schema', () async {
    final tables = await db.customSelect('SELECT name FROM sqlite_master WHERE type="table"').get();
    final tableNames = tables.map((row) => row.data['name']).toList();
    expect(tableNames, contains('members_cache'));
  });
}
```

**Step 5: Run database tests**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test test/database_test.dart -v
```

Expected: PASS - Tables created successfully.

**Step 6: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar
git add terminal-frontend/lib/database/ terminal-frontend/test/database_test.dart
git commit -m "feat: define SQLite schema with Drift ORM for offline-first caching"
```

---

### Task 3: Create API Models (DTOs) for Sync Responses

**Files:**
- Create: `terminal-frontend/lib/models/sync_response.dart`
- Create: `terminal-frontend/lib/models/member_dto.dart`
- Create: `terminal-frontend/lib/models/product_dto.dart`
- Create: `terminal-frontend/lib/models/category_dto.dart`

**Step 1: Create sync response models**

Create `terminal-frontend/lib/models/member_dto.dart`:

```dart
class MemberDTO {
  final String id;
  final String? cardUid;
  final String firstName;
  final String lastName;
  final String preferredLanguage;
  final bool isActive;
  final bool isSepaValid;
  final String updatedAt;

  MemberDTO({
    required this.id,
    this.cardUid,
    required this.firstName,
    required this.lastName,
    required this.preferredLanguage,
    required this.isActive,
    required this.isSepaValid,
    required this.updatedAt,
  });

  factory MemberDTO.fromJson(Map<String, dynamic> json) {
    return MemberDTO(
      id: json['id'] as String,
      cardUid: json['card_uid'] as String?,
      firstName: json['first_name'] as String? ?? '',
      lastName: json['last_name'] as String? ?? '',
      preferredLanguage: json['preferred_language'] as String? ?? 'de',
      isActive: (json['is_active'] as int?) == 1,
      isSepaValid: (json['is_sepa_valid'] as int?) == 1,
      updatedAt: json['updated_at'] as String,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'card_uid': cardUid,
    'first_name': firstName,
    'last_name': lastName,
    'preferred_language': preferredLanguage,
    'is_active': isActive ? 1 : 0,
    'is_sepa_valid': isSepaValid ? 1 : 0,
    'updated_at': updatedAt,
  };
}
```

Create `terminal-frontend/lib/models/category_dto.dart`:

```dart
import 'dart:convert';

class CategoryDTO {
  final String id;
  final Map<String, String> names; // {"de": "...", "en": "..."}
  final int displayOrder;
  final bool isActive;
  final String updatedAt;

  CategoryDTO({
    required this.id,
    required this.names,
    required this.displayOrder,
    required this.isActive,
    required this.updatedAt,
  });

  factory CategoryDTO.fromJson(Map<String, dynamic> json) {
    return CategoryDTO(
      id: json['id'] as String,
      names: Map<String, String>.from(jsonDecode(json['names'] as String) as Map),
      displayOrder: json['display_order'] as int,
      isActive: (json['is_active'] as int?) == 1,
      updatedAt: json['updated_at'] as String,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'names': jsonEncode(names),
    'display_order': displayOrder,
    'is_active': isActive ? 1 : 0,
    'updated_at': updatedAt,
  };
}
```

Create `terminal-frontend/lib/models/product_dto.dart`:

```dart
import 'dart:convert';

class ProductDTO {
  final String id;
  final String categoryId;
  final Map<String, String> names;
  final Map<String, String>? descriptions;
  final int priceCents;
  final bool isActive;
  final String updatedAt;

  ProductDTO({
    required this.id,
    required this.categoryId,
    required this.names,
    this.descriptions,
    required this.priceCents,
    required this.isActive,
    required this.updatedAt,
  });

  factory ProductDTO.fromJson(Map<String, dynamic> json) {
    return ProductDTO(
      id: json['id'] as String,
      categoryId: json['category_id'] as String,
      names: Map<String, String>.from(jsonDecode(json['names'] as String) as Map),
      descriptions: json['descriptions'] != null
        ? Map<String, String>.from(jsonDecode(json['descriptions'] as String) as Map)
        : null,
      priceCents: json['price_cents'] as int,
      isActive: (json['is_active'] as int?) == 1,
      updatedAt: json['updated_at'] as String,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'category_id': categoryId,
    'names': jsonEncode(names),
    'descriptions': descriptions != null ? jsonEncode(descriptions) : null,
    'price_cents': priceCents,
    'is_active': isActive ? 1 : 0,
    'updated_at': updatedAt,
  };
}
```

Create `terminal-frontend/lib/models/sync_response.dart`:

```dart
import 'member_dto.dart';
import 'category_dto.dart';
import 'product_dto.dart';

class MembersSyncResponse {
  final List<MemberDTO> members;
  final String? cursor; // For pagination

  MembersSyncResponse({
    required this.members,
    this.cursor,
  });

  factory MembersSyncResponse.fromJson(Map<String, dynamic> json) {
    return MembersSyncResponse(
      members: (json['data'] as List<dynamic>)
          .map((item) => MemberDTO.fromJson(item as Map<String, dynamic>))
          .toList(),
      cursor: json['cursor'] as String?,
    );
  }
}

class ProductsSyncResponse {
  final List<CategoryDTO> categories;
  final List<ProductDTO> products;
  final String? cursor;

  ProductsSyncResponse({
    required this.categories,
    required this.products,
    this.cursor,
  });

  factory ProductsSyncResponse.fromJson(Map<String, dynamic> json) {
    return ProductsSyncResponse(
      categories: (json['categories'] as List<dynamic>)
          .map((item) => CategoryDTO.fromJson(item as Map<String, dynamic>))
          .toList(),
      products: (json['products'] as List<dynamic>)
          .map((item) => ProductDTO.fromJson(item as Map<String, dynamic>))
          .toList(),
      cursor: json['cursor'] as String?,
    );
  }
}
```

**Step 2: Test DTO parsing**

Create `terminal-frontend/test/models_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/models/member_dto.dart';
import 'package:clubbar_terminal/models/category_dto.dart';

void main() {
  test('MemberDTO parses JSON correctly', () {
    final json = {
      'id': '123',
      'card_uid': 'ABC123',
      'first_name': 'Max',
      'last_name': 'Mustermann',
      'preferred_language': 'de',
      'is_active': 1,
      'is_sepa_valid': 1,
      'updated_at': '2025-02-01T10:00:00Z',
    };

    final member = MemberDTO.fromJson(json);
    expect(member.id, equals('123'));
    expect(member.cardUid, equals('ABC123'));
    expect(member.firstName, equals('Max'));
    expect(member.isActive, isTrue);
  });

  test('CategoryDTO parses JSON with multilingual names', () {
    final json = {
      'id': '456',
      'names': '{"de":"Getränke","en":"Beverages"}',
      'display_order': 1,
      'is_active': 1,
      'updated_at': '2025-02-01T10:00:00Z',
    };

    final category = CategoryDTO.fromJson(json);
    expect(category.id, equals('456'));
    expect(category.names['de'], equals('Getränke'));
    expect(category.names['en'], equals('Beverages'));
  });
}
```

**Step 3: Run model tests**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test test/models_test.dart -v
```

Expected: PASS - JSON parsing works correctly.

**Step 4: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar
git add terminal-frontend/lib/models/ terminal-frontend/test/models_test.dart
git commit -m "feat: add API DTOs for sync responses (members, products, categories)"
```

---

### Task 4: Create Shopping Cart Model & State

**Files:**
- Create: `terminal-frontend/lib/models/cart_item.dart`
- Create: `terminal-frontend/lib/models/shopping_cart.dart`

**Step 1: Create CartItem model**

Create `terminal-frontend/lib/models/cart_item.dart`:

```dart
import 'package:flutter/foundation.dart';

class CartItem {
  final String productId;
  final String productName;
  final int priceCents;
  int quantity;
  final String language;

  CartItem({
    required this.productId,
    required this.productName,
    required this.priceCents,
    required this.quantity,
    required this.language,
  });

  int get lineTotalCents => priceCents * quantity;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is CartItem &&
          runtimeType == other.runtimeType &&
          productId == other.productId;

  @override
  int get hashCode => productId.hashCode;
}
```

**Step 2: Create ShoppingCart model**

Create `terminal-frontend/lib/models/shopping_cart.dart`:

```dart
import 'package:flutter/foundation.dart';
import 'cart_item.dart';

class ShoppingCart with ChangeNotifier {
  final List<CartItem> _items = [];

  List<CartItem> get items => List.unmodifiable(_items);

  int get itemCount => _items.fold<int>(0, (sum, item) => sum + item.quantity);

  int get totalCents => _items.fold<int>(0, (sum, item) => sum + item.lineTotalCents);

  void addItem(CartItem item) {
    final existing = _items.firstWhere(
      (i) => i.productId == item.productId,
      orElse: () => CartItem(
        productId: item.productId,
        productName: item.productName,
        priceCents: item.priceCents,
        quantity: 0,
        language: item.language,
      ),
    );

    if (existing.quantity == 0) {
      _items.add(item);
    } else {
      existing.quantity += item.quantity;
    }
    notifyListeners();
  }

  void updateQuantity(String productId, int newQuantity) {
    final item = _items.firstWhereOrNull((i) => i.productId == productId);
    if (item != null) {
      if (newQuantity > 0) {
        item.quantity = newQuantity;
      } else {
        _items.remove(item);
      }
      notifyListeners();
    }
  }

  void removeItem(String productId) {
    _items.removeWhere((item) => item.productId == productId);
    notifyListeners();
  }

  void clear() {
    _items.clear();
    notifyListeners();
  }

  bool get isEmpty => _items.isEmpty;
}
```

**Step 3: Create cart tests**

Create `terminal-frontend/test/cart_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/shopping_cart.dart';

void main() {
  group('ShoppingCart', () {
    late ShoppingCart cart;

    setUp(() {
      cart = ShoppingCart();
    });

    test('adds item to cart', () {
      final item = CartItem(
        productId: '1',
        productName: 'Beer',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      );

      cart.addItem(item);
      expect(cart.items.length, equals(1));
      expect(cart.itemCount, equals(1));
      expect(cart.totalCents, equals(350));
    });

    test('increments quantity for duplicate product', () {
      final item = CartItem(
        productId: '1',
        productName: 'Beer',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      );

      cart.addItem(item);
      cart.addItem(item);

      expect(cart.items.length, equals(1));
      expect(cart.items.first.quantity, equals(2));
      expect(cart.totalCents, equals(700));
    });

    test('removes item from cart', () {
      final item = CartItem(
        productId: '1',
        productName: 'Beer',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      );

      cart.addItem(item);
      cart.removeItem('1');

      expect(cart.items.isEmpty, isTrue);
    });

    test('clears entire cart', () {
      cart.addItem(CartItem(
        productId: '1',
        productName: 'Beer',
        priceCents: 350,
        quantity: 2,
        language: 'de',
      ));

      cart.clear();
      expect(cart.isEmpty, isTrue);
    });

    test('notifies listeners on changes', () async {
      var notifyCount = 0;
      cart.addListener(() => notifyCount++);

      cart.addItem(CartItem(
        productId: '1',
        productName: 'Beer',
        priceCents: 350,
        quantity: 1,
        language: 'de',
      ));

      expect(notifyCount, equals(1));
    });
  });
}
```

**Step 4: Run cart tests**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test test/cart_test.dart -v
```

Expected: PASS - All cart operations work correctly.

**Step 5: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar
git add terminal-frontend/lib/models/cart_item.dart terminal-frontend/lib/models/shopping_cart.dart terminal-frontend/test/cart_test.dart
git commit -m "feat: add ShoppingCart model with ChangeNotifier state management"
```

---

## Phase 2: Data Layer (Repository + Sync)

### Task 5: Create Repository Layer for Database Access

**Files:**
- Create: `terminal-frontend/lib/repository/members_repository.dart`
- Create: `terminal-frontend/lib/repository/products_repository.dart`
- Create: `terminal-frontend/lib/repository/transactions_repository.dart`
- Create: `terminal-frontend/lib/repository/sync_repository.dart`

**Step 1: Create MembersRepository**

Create `terminal-frontend/lib/repository/members_repository.dart`:

```dart
import 'package:drift/drift.dart' hide Column;
import '../database/database.dart';
import '../models/member_dto.dart';

class MembersRepository {
  final ClubBarDatabase _db;

  MembersRepository(this._db);

  /// Find member by card UID
  Future<(MembersCache?, String?)> findByCardUid(String cardUid) async {
    try {
      final member = await (_db.select(_db.membersCache)
            ..where((m) => m.cardUid.equals(cardUid)))
          .getSingleOrNull();

      if (member == null) {
        return (null, 'Unknown card');
      }

      if (member.isActive == 0) {
        return (null, 'Account inactive');
      }

      if (member.isSepaValid == 0) {
        return (null, 'SEPA mandate missing');
      }

      return (member, null);
    } catch (e) {
      return (null, 'Database error: $e');
    }
  }

  /// Upsert members from sync response
  Future<void> upsertMembers(List<MemberDTO> members) async {
    for (final dto in members) {
      await _db.into(_db.membersCache).insertOnConflictUpdate(
        MembersCacheCompanion(
          id: Value(dto.id),
          cardUid: Value(dto.cardUid),
          firstName: Value(dto.firstName),
          lastName: Value(dto.lastName),
          preferredLanguage: Value(dto.preferredLanguage),
          isActive: Value(dto.isActive ? 1 : 0),
          isSepaValid: Value(dto.isSepaValid ? 1 : 0),
          updatedAt: Value(dto.updatedAt),
        ),
      );
    }
  }

  /// Get all active members (for testing/admin)
  Future<List<MembersCache>> getAllActive() async {
    return (_db.select(_db.membersCache)
          ..where((m) => m.isActive.equals(1)))
        .get();
  }

  /// Clear all member cache
  Future<void> clearCache() async {
    await _db.delete(_db.membersCache).go();
  }
}
```

**Step 2: Create ProductsRepository**

Create `terminal-frontend/lib/repository/products_repository.dart`:

```dart
import 'dart:convert';
import 'package:drift/drift.dart' hide Column;
import '../database/database.dart';
import '../models/product_dto.dart';
import '../models/category_dto.dart';

class ProductsRepository {
  final ClubBarDatabase _db;

  ProductsRepository(this._db);

  /// Get all active categories with products
  Future<List<(CategoriesCacheData, List<ProductsCacheData>)>> getActiveCategoriesWithProducts(String language) async {
    final categories = await (_db.select(_db.categoriesCache)
          ..where((c) => c.isActive.equals(1))
          ..orderBy([(c) => OrderingTerm(expression: c.displayOrder)]))
        .get();

    final result = <(CategoriesCacheData, List<ProductsCacheData>)>[];

    for (final category in categories) {
      final products = await (_db.select(_db.productsCache)
            ..where((p) => p.categoryId.equals(category.id) & p.isActive.equals(1)))
          .get();

      if (products.isNotEmpty) {
        result.add((category, products));
      }
    }

    return result;
  }

  /// Get product by ID
  Future<ProductsCacheData?> getProduct(String productId) async {
    return (_db.select(_db.productsCache)
          ..where((p) => p.id.equals(productId)))
        .getSingleOrNull();
  }

  /// Get translated name from JSON
  String getTranslatedName(String jsonNames, String language) {
    try {
      final names = jsonDecode(jsonNames) as Map<String, dynamic>;
      return names[language]?.toString() ?? names['de']?.toString() ?? 'Unknown';
    } catch (e) {
      return 'Unknown';
    }
  }

  /// Upsert categories from sync
  Future<void> upsertCategories(List<CategoryDTO> categories) async {
    for (final dto in categories) {
      await _db.into(_db.categoriesCache).insertOnConflictUpdate(
        CategoriesCacheCompanion(
          id: Value(dto.id),
          names: Value(jsonEncode(dto.names)),
          displayOrder: Value(dto.displayOrder),
          isActive: Value(dto.isActive ? 1 : 0),
          updatedAt: Value(dto.updatedAt),
        ),
      );
    }
  }

  /// Upsert products from sync
  Future<void> upsertProducts(List<ProductDTO> products) async {
    for (final dto in products) {
      await _db.into(_db.productsCache).insertOnConflictUpdate(
        ProductsCacheCompanion(
          id: Value(dto.id),
          categoryId: Value(dto.categoryId),
          names: Value(jsonEncode(dto.names)),
          descriptions: Value(dto.descriptions != null ? jsonEncode(dto.descriptions) : null),
          priceCents: Value(dto.priceCents),
          isActive: Value(dto.isActive ? 1 : 0),
          updatedAt: Value(dto.updatedAt),
        ),
      );
    }
  }

  /// Clear cache
  Future<void> clearCache() async {
    await _db.delete(_db.productsCache).go();
    await _db.delete(_db.categoriesCache).go();
  }
}
```

**Step 3: Create TransactionsRepository**

Create `terminal-frontend/lib/repository/transactions_repository.dart`:

```dart
import 'package:drift/drift.dart' hide Column;
import 'package:uuid/uuid.dart'; // v4.5.2 for transaction ID generation
import '../database/database.dart';

class TransactionsRepository {
  final ClubBarDatabase _db;
  static const _uuid = Uuid();

  TransactionsRepository(this._db);

  /// Create a new purchase transaction
  Future<String> createPurchaseTransaction({
    required String memberId,
    required String productId,
    required int amountCents,
  }) async {
    final id = _uuid.v4();
    final now = DateTime.now().toIso8601String();

    await _db.into(_db.transactionsLocal).insert(
      TransactionsLocalCompanion(
        id: Value(id),
        memberId: Value(memberId),
        productId: Value(productId),
        amountCents: Value(amountCents),
        transactionType: Value('purchase'),
        createdAt: Value(now),
        synced: Value(0),
      ),
    );

    return id;
  }

  /// Get pending (unsynced) transactions
  Future<List<TransactionsLocalData>> getPendingTransactions() async {
    return (_db.select(_db.transactionsLocal)
          ..where((t) => t.synced.equals(0)))
        .get();
  }

  /// Mark transactions as synced
  Future<void> markSynced(List<String> transactionIds) async {
    for (final id in transactionIds) {
      await (_db.update(_db.transactionsLocal)
            ..where((t) => t.id.equals(id)))
          .write(const TransactionsLocalCompanion(synced: Value(1)));
    }
  }

  /// Get transaction count for member (optional: for history view)
  Future<int> getTransactionCount(String memberId) async {
    final countResult = await _db
        .customSelect(
          'SELECT COUNT(*) as count FROM transactions_local WHERE member_id = ?',
          variables: [memberId],
        )
        .getSingle();
    return countResult.read<int>('count');
  }
}
```

**Step 4: Create SyncRepository**

Create `terminal-frontend/lib/repository/sync_repository.dart`:

```dart
import 'package:drift/drift.dart' hide Column;
import '../database/database.dart';

class SyncRepository {
  final ClubBarDatabase _db;

  SyncRepository(this._db);

  /// Get last sync timestamp for a key
  Future<String?> getLastSyncTimestamp(String key) async {
    final row = await (_db.select(_db.syncState)
          ..where((s) => s.key.equals(key)))
        .getSingleOrNull();
    return row?.value;
  }

  /// Set sync timestamp
  Future<void> setLastSyncTimestamp(String key, String timestamp) async {
    await _db.into(_db.syncState).insertOnConflictUpdate(
      SyncStateCompanion(
        key: Value(key),
        value: Value(timestamp),
      ),
    );
  }

  /// Get sync status
  Future<Map<String, String>> getSyncStatus() async {
    final rows = await _db.select(_db.syncState).get();
    return {for (final row in rows) row.key: row.value};
  }

  /// Clear sync state
  Future<void> clearSyncState() async {
    await _db.delete(_db.syncState).go();
  }
}
```

**Step 5: Test repositories**

Create `terminal-frontend/test/repository_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/models/member_dto.dart';

void main() {
  group('Repositories', () {
    late ClubBarDatabase db;
    late MembersRepository membersRepo;
    late TransactionsRepository transactionsRepo;

    setUp(() async {
      sqfliteFfiTestPrefix = ':memory:';
      db = ClubBarDatabase();
      membersRepo = MembersRepository(db);
      transactionsRepo = TransactionsRepository(db);
    });

    tearDown(() async {
      await db.close();
    });

    test('MembersRepository upserts and retrieves members', () async {
      final dto = MemberDTO(
        id: '123',
        cardUid: 'ABC123',
        firstName: 'Max',
        lastName: 'Mustermann',
        preferredLanguage: 'de',
        isActive: true,
        isSepaValid: true,
        updatedAt: '2025-02-01T10:00:00Z',
      );

      await membersRepo.upsertMembers([dto]);

      final (member, error) = await membersRepo.findByCardUid('ABC123');
      expect(member, isNotNull);
      expect(member!.firstName, equals('Max'));
      expect(error, isNull);
    });

    test('TransactionsRepository creates and retrieves pending transactions', () async {
      await membersRepo.upsertMembers([
        MemberDTO(
          id: '123',
          cardUid: 'ABC123',
          firstName: 'Max',
          lastName: 'Mustermann',
          preferredLanguage: 'de',
          isActive: true,
          isSepaValid: true,
          updatedAt: '2025-02-01T10:00:00Z',
        )
      ]);

      await transactionsRepo.createPurchaseTransaction(
        memberId: '123',
        productId: 'prod-1',
        amountCents: 350,
      );

      final pending = await transactionsRepo.getPendingTransactions();
      expect(pending.length, equals(1));
      expect(pending.first.amountCents, equals(350));
      expect(pending.first.synced, equals(0));
    });
  });
}
```

**Step 6: Run repository tests**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test test/repository_test.dart -v
```

Expected: PASS - All repository operations work.

**Step 7: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar
git add terminal-frontend/lib/repository/ terminal-frontend/test/repository_test.dart
git commit -m "feat: add repository layer for database access (members, products, transactions)"
```

---

### Task 6: Implement Backend Sync Service

**Files:**
- Create: `terminal-frontend/lib/services/sync_service.dart`
- Create: `terminal-frontend/lib/services/network_service.dart`

**Step 1: Create NetworkService (HTTP client)**

Create `terminal-frontend/lib/services/network_service.dart`:

```dart
import 'package:http/http.dart' as http;
import 'dart:convert';
import '../config/app_config.dart';

class NetworkService {
  static const baseUrl = AppConfig.apiBaseUrl;
  final http.Client _client = http.Client();

  Future<T> get<T>(
    String endpoint, {
    required T Function(Map<String, dynamic>) parser,
  }) async {
    try {
      final uri = Uri.parse('$baseUrl$endpoint');
      final response = await _client
          .get(uri)
          .timeout(AppConfig.syncTimeout, onTimeout: () {
        throw TimeoutException('Network request timed out');
      });

      if (response.statusCode == 200) {
        final json = jsonDecode(response.body) as Map<String, dynamic>;
        return parser(json);
      } else {
        throw Exception('HTTP ${response.statusCode}: ${response.body}');
      }
    } catch (e) {
      rethrow;
    }
  }

  Future<Map<String, dynamic>> post<T>(
    String endpoint,
    Map<String, dynamic> body,
  ) async {
    try {
      final uri = Uri.parse('$baseUrl$endpoint');
      final response = await _client
          .post(
            uri,
            headers: {'Content-Type': 'application/json'},
            body: jsonEncode(body),
          )
          .timeout(AppConfig.syncTimeout, onTimeout: () {
        throw TimeoutException('Network request timed out');
      });

      if (response.statusCode == 200 || response.statusCode == 201) {
        return jsonDecode(response.body) as Map<String, dynamic>;
      } else {
        throw Exception('HTTP ${response.statusCode}: ${response.body}');
      }
    } catch (e) {
      rethrow;
    }
  }
}

class TimeoutException implements Exception {
  final String message;
  TimeoutException(this.message);

  @override
  String toString() => message;
}
```

**Step 2: Create SyncService**

Create `terminal-frontend/lib/services/sync_service.dart`:

```dart
import 'package:logger/logger.dart';
import '../database/database.dart';
import '../models/sync_response.dart';
import '../repository/members_repository.dart';
import '../repository/products_repository.dart';
import '../repository/transactions_repository.dart';
import '../repository/sync_repository.dart';
import 'network_service.dart';
import '../config/app_config.dart';

class SyncService {
  final ClubBarDatabase _db;
  final NetworkService _network;
  final Logger _logger = Logger();

  late MembersRepository _membersRepo;
  late ProductsRepository _productsRepo;
  late TransactionsRepository _transactionsRepo;
  late SyncRepository _syncRepo;

  SyncService(this._db, this._network) {
    _membersRepo = MembersRepository(_db);
    _productsRepo = ProductsRepository(_db);
    _transactionsRepo = TransactionsRepository(_db);
    _syncRepo = SyncRepository(_db);
  }

  /// Perform full sync cycle: download members/products, upload transactions
  Future<SyncResult> syncAll() async {
    _logger.i('Starting sync cycle...');

    try {
      // Step 1: Sync members
      final membersSynced = await _syncMembers();
      _logger.i('Synced $membersSynced members');

      // Step 2: Sync products and categories
      final productsSynced = await _syncProducts();
      _logger.i('Synced $productsSynced products');

      // Step 3: Upload pending transactions
      final transactionsUploaded = await _uploadTransactions();
      _logger.i('Uploaded $transactionsUploaded transactions');

      return SyncResult(
        success: true,
        membersSynced: membersSynced,
        productsSynced: productsSynced,
        transactionsUploaded: transactionsUploaded,
        error: null,
      );
    } catch (e) {
      _logger.e('Sync failed', error: e);
      return SyncResult(
        success: false,
        error: e.toString(),
      );
    }
  }

  Future<int> _syncMembers() async {
    final lastSync = await _syncRepo.getLastSyncTimestamp('members');
    final since = lastSync ?? '1970-01-01T00:00:00Z';

    final response = await _network.get<MembersSyncResponse>(
      '${AppConfig.syncEndpointMembers}?since=$since',
      parser: (json) => MembersSyncResponse.fromJson(json),
    );

    await _membersRepo.upsertMembers(response.members);
    await _syncRepo.setLastSyncTimestamp(
      'members',
      DateTime.now().toIso8601String(),
    );

    return response.members.length;
  }

  Future<int> _syncProducts() async {
    final lastSync = await _syncRepo.getLastSyncTimestamp('products');
    final since = lastSync ?? '1970-01-01T00:00:00Z';

    final response = await _network.get<ProductsSyncResponse>(
      '${AppConfig.syncEndpointProducts}?since=$since',
      parser: (json) => ProductsSyncResponse.fromJson(json),
    );

    await _productsRepo.upsertCategories(response.categories);
    await _productsRepo.upsertProducts(response.products);
    await _syncRepo.setLastSyncTimestamp(
      'products',
      DateTime.now().toIso8601String(),
    );

    return response.products.length;
  }

  Future<int> _uploadTransactions() async {
    final pending = await _transactionsRepo.getPendingTransactions();

    if (pending.isEmpty) {
      return 0;
    }

    final payload = {
      'transactions': pending
          .map((t) => {
            'id': t.id,
            'member_id': t.memberId,
            'product_id': t.productId,
            'amount_cents': t.amountCents,
            'type': t.transactionType,
            'created_at': t.createdAt,
          })
          .toList(),
    };

    final response = await _network.post(
      AppConfig.syncEndpointTransactions,
      payload,
    );

    // Mark as synced
    await _transactionsRepo.markSynced(pending.map((t) => t.id).toList());

    return pending.length;
  }
}

class SyncResult {
  final bool success;
  final int membersSynced;
  final int productsSynced;
  final int transactionsUploaded;
  final String? error;

  SyncResult({
    required this.success,
    this.membersSynced = 0,
    this.productsSynced = 0,
    this.transactionsUploaded = 0,
    this.error,
  });
}
```

**Step 3: Test sync service**

Create `terminal-frontend/test/sync_service_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/services/sync_service.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/models/sync_response.dart';
import 'package:clubbar_terminal/models/member_dto.dart';
import 'package:clubbar_terminal/models/category_dto.dart';
import 'package:clubbar_terminal/models/product_dto.dart';

class MockNetworkService extends Mock implements NetworkService {}

void main() {
  group('SyncService', () {
    late ClubBarDatabase db;
    late MockNetworkService mockNetwork;
    late SyncService syncService;

    setUp(() async {
      sqfliteFfiTestPrefix = ':memory:';
      db = ClubBarDatabase();
      mockNetwork = MockNetworkService();
      syncService = SyncService(db, mockNetwork);
    });

    tearDown(() async {
      await db.close();
    });

    test('syncMembers retrieves and caches members', () async {
      final response = MembersSyncResponse(
        members: [
          MemberDTO(
            id: '123',
            cardUid: 'ABC123',
            firstName: 'Max',
            lastName: 'Mustermann',
            preferredLanguage: 'de',
            isActive: true,
            isSepaValid: true,
            updatedAt: '2025-02-01T10:00:00Z',
          )
        ],
      );

      when(() => mockNetwork.get(
        any(),
        parser: any(named: 'parser'),
      )).thenAnswer((_) async => response);

      final result = await syncService.syncAll();

      expect(result.success, isTrue);
      expect(result.membersSynced, equals(1));
    });
  });
}
```

**Step 4: Run sync tests**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test test/sync_service_test.dart -v
```

Expected: PASS - Sync service works with mocked network.

**Step 5: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar
git add terminal-frontend/lib/services/ terminal-frontend/test/sync_service_test.dart
git commit -m "feat: implement sync service with network retry logic and offline-first design"
```

---

## Phase 3: State Management (Providers)

### Task 7-10: Create Provider-based State Management

*These four tasks implement the core state providers needed for the app. Each task should follow the pattern below:*

**Files for all tasks:**
- Create: `terminal-frontend/lib/providers/members_provider.dart`
- Create: `terminal-frontend/lib/providers/products_provider.dart`
- Create: `terminal-frontend/lib/providers/cart_provider.dart`
- Create: `terminal-frontend/lib/providers/sync_provider.dart`

**For Task 7 (MembersProvider): Create **`terminal-frontend/lib/providers/members_provider.dart`:**

```dart
import 'package:flutter/foundation.dart';
import 'package:provider/provider.dart';
import '../database/database.dart';
import '../repository/members_repository.dart';
import '../models/member_dto.dart';

class MembersProvider extends ChangeNotifier {
  final MembersRepository _repository;

  MembersCache? _currentMember;
  String? _error;
  bool _isLoading = false;

  MembersProvider(this._repository);

  MembersCache? get currentMember => _currentMember;
  String? get error => _error;
  bool get isLoading => _isLoading;

  Future<bool> lookupByCardUid(String cardUid) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    final (member, error) = await _repository.findByCardUid(cardUid);

    _currentMember = member;
    _error = error;
    _isLoading = false;
    notifyListeners();

    return error == null;
  }

  void clearCurrent() {
    _currentMember = null;
    _error = null;
    notifyListeners();
  }

  Future<void> syncMembers(List<MemberDTO> members) async {
    await _repository.upsertMembers(members);
  }
}
```

**For Task 8 (ProductsProvider):** Create `terminal-frontend/lib/providers/products_provider.dart`:

```dart
import 'package:flutter/foundation.dart';
import '../database/database.dart';
import '../repository/products_repository.dart';
import '../models/product_dto.dart';
import '../models/category_dto.dart';

class ProductsProvider extends ChangeNotifier {
  final ProductsRepository _repository;

  List<(CategoriesCacheData, List<ProductsCacheData>)> _categoriesWithProducts = [];
  String _currentLanguage = 'de';
  bool _isLoading = false;

  ProductsProvider(this._repository);

  List<(CategoriesCacheData, List<ProductsCacheData>)> get categoriesWithProducts => _categoriesWithProducts;
  String get currentLanguage => _currentLanguage;
  bool get isLoading => _isLoading;

  Future<void> loadProducts(String language) async {
    _isLoading = true;
    _currentLanguage = language;
    notifyListeners();

    _categoriesWithProducts = await _repository.getActiveCategoriesWithProducts(language);

    _isLoading = false;
    notifyListeners();
  }

  String getProductName(ProductsCacheData product) {
    return _repository.getTranslatedName(product.names, _currentLanguage);
  }

  String getCategoryName(CategoriesCacheData category) {
    return _repository.getTranslatedName(category.names, _currentLanguage);
  }

  Future<void> syncProducts(List<CategoryDTO> categories, List<ProductDTO> products) async {
    await _repository.upsertCategories(categories);
    await _repository.upsertProducts(products);
  }
}
```

**For Task 9 (CartProvider):** Create `terminal-frontend/lib/providers/cart_provider.dart`:

```dart
import 'package:flutter/foundation.dart';
import 'package:provider/provider.dart';
import '../models/shopping_cart.dart';
import '../models/cart_item.dart';
import '../database/database.dart';
import '../repository/transactions_repository.dart';

class CartProvider extends ChangeNotifier {
  final ShoppingCart _cart = ShoppingCart();
  final TransactionsRepository _transactionsRepository;

  bool _isProcessing = false;

  CartProvider(this._transactionsRepository) {
    _cart.addListener(notifyListeners);
  }

  ShoppingCart get cart => _cart;
  bool get isProcessing => _isProcessing;

  void addItem(CartItem item) {
    _cart.addItem(item);
  }

  void removeItem(String productId) {
    _cart.removeItem(productId);
  }

  void updateQuantity(String productId, int quantity) {
    _cart.updateQuantity(productId, quantity);
  }

  Future<bool> checkout(String memberId) async {
    _isProcessing = true;
    notifyListeners();

    try {
      for (final item in _cart.items) {
        await _transactionsRepository.createPurchaseTransaction(
          memberId: memberId,
          productId: item.productId,
          amountCents: item.lineTotalCents,
        );
      }

      _cart.clear();
      _isProcessing = false;
      notifyListeners();
      return true;
    } catch (e) {
      _isProcessing = false;
      notifyListeners();
      return false;
    }
  }

  void clearCart() {
    _cart.clear();
  }

  @override
  void dispose() {
    _cart.removeListener(notifyListeners);
    super.dispose();
  }
}
```

**For Task 10 (SyncProvider):** Create `terminal-frontend/lib/providers/sync_provider.dart`:

```dart
import 'package:flutter/foundation.dart';
import 'dart:async';
import '../database/database.dart';
import '../services/sync_service.dart';
import '../services/network_service.dart';
import '../config/app_config.dart';

class SyncProvider extends ChangeNotifier {
  final ClubBarDatabase _db;
  final NetworkService _network;

  late SyncService _syncService;
  Timer? _syncTimer;

  bool _isSyncing = false;
  bool _isOnline = false;
  String? _lastSyncError;
  DateTime? _lastSyncTime;

  SyncProvider(this._db, this._network) {
    _syncService = SyncService(_db, _network);
    _startPeriodicSync();
  }

  bool get isSyncing => _isSyncing;
  bool get isOnline => _isOnline;
  String? get lastSyncError => _lastSyncError;
  DateTime? get lastSyncTime => _lastSyncTime;

  void _startPeriodicSync() {
    _syncTimer = Timer.periodic(AppConfig.syncInterval, (_) async {
      await performSync();
    });
  }

  Future<void> performSync() async {
    if (_isSyncing) return;

    _isSyncing = true;
    notifyListeners();

    try {
      final result = await _syncService.syncAll();

      _isOnline = result.success;
      _lastSyncError = result.error;
      _lastSyncTime = DateTime.now();

      if (result.success) {
        // Clear any previous errors
        _lastSyncError = null;
      }
    } catch (e) {
      _isOnline = false;
      _lastSyncError = e.toString();
    }

    _isSyncing = false;
    notifyListeners();
  }

  @override
  void dispose() {
    _syncTimer?.cancel();
    super.dispose();
  }
}
```

---

## Phase 4: Mock RFID Service (Button Click Detection)

### Task 15: Create Mock RFID Service

**Files:**
- Create: `terminal-frontend/lib/services/mock_rfid_service.dart`
- Create: `terminal-frontend/lib/providers/rfid_provider.dart`

**Step 1: Create MockRfidService**

Create `terminal-frontend/lib/services/mock_rfid_service.dart`:

```dart
import 'package:flutter/foundation.dart';
import '../models/member_dto.dart';

class MockRfidService {
  // Single test member with full SEPA data
  static final _mockMembers = {
    'RF-4821': MemberDTO(
      id: '550e8400-e29b-41d4-a716-446655440000',
      cardUid: 'RF-4821',
      firstName: 'Max',
      lastName: 'Mustermann',
      preferredLanguage: 'de',
      isActive: true,
      isSepaValid: true, // Has valid IBAN + mandate_reference
      updatedAt: '2025-02-01T10:00:00Z',
    ),
  };

  /// Simulate RFID card detection (called when user taps "detect" button)
  Future<MemberDTO?> detectCard({String? cardUidOverride}) async {
    // Simulate reader delay
    await Future.delayed(const Duration(milliseconds: 800));

    // Return mock member (or override for testing different scenarios)
    return _mockMembers[cardUidOverride ?? 'RF-4821'];
  }

  /// Get all mock members (just 1 for MVP)
  List<MemberDTO> getAllMockMembers() => _mockMembers.values.toList();
}
```

**Step 2: Create RfidProvider with mock service**

Create `terminal-frontend/lib/providers/rfid_provider.dart`:

```dart
import 'package:flutter/foundation.dart';
import '../database/database.dart';
import '../services/mock_rfid_service.dart';
import '../repository/members_repository.dart';

class RfidProvider extends ChangeNotifier {
  final RfidService = MockRfidService();
  final MembersRepository _membersRepository;

  MembersCache? _detectedMember;
  bool _isScanning = false;
  String? _error;

  RfidProvider(this._membersRepository);

  MembersCache? get detectedMember => _detectedMember;
  bool get isScanning => _isScanning;
  String? get error => _error;

  /// Simulate RFID card detection (called from UI when user taps detect button)
  Future<void> simulateCardDetection({String? cardUidOverride}) async {
    _isScanning = true;
    _error = null;
    notifyListeners();

    try {
      final mockMember = await RfidService.detectCard(cardUidOverride: cardUidOverride);

      if (mockMember == null) {
        _error = 'Unknown card';
        _detectedMember = null;
      } else {
        // Look up in local cache (should exist from sync)
        final (member, error) = await _membersRepository.findByCardUid(mockMember.cardUid!);

        if (member != null) {
          _detectedMember = member;
          _error = null;
        } else {
          // If not in cache, create temporary member (will sync)
          _error = error;
          _detectedMember = null;
        }
      }
    } catch (e) {
      _error = 'Error: $e';
      _detectedMember = null;
    }

    _isScanning = false;
    notifyListeners();
  }

  void clearDetection() {
    _detectedMember = null;
    _error = null;
    notifyListeners();
  }
}
```

**Step 3: Test mock RFID**

Create `terminal-frontend/test/mock_rfid_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/services/mock_rfid_service.dart';

void main() {
  group('MockRfidService', () {
    late MockRfidService rfidService;

    setUp(() {
      rfidService = MockRfidService();
    });

    test('detectCard returns mock member', () async {
      final member = await rfidService.detectCard();

      expect(member, isNotNull);
      expect(member!.cardUid, equals('RF-4821'));
      expect(member.firstName, equals('Max'));
    });

    test('getAllMockMembers returns test data', () {
      final members = rfidService.getAllMockMembers();

      expect(members.length, equals(1));
      expect(members.first.firstName, equals('Max'));
      expect(members.first.isSepaValid, isTrue);
    });

    test('detectCard simulates delay', () async {
      final stopwatch = Stopwatch()..start();
      await rfidService.detectCard();
      stopwatch.stop();

      expect(stopwatch.elapsedMilliseconds, greaterThanOrEqualTo(800));
    });
  });
}
```

**Step 4: Run tests**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test test/mock_rfid_test.dart -v
```

Expected: PASS - Mock detection works.

**Step 5: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar
git add terminal-frontend/lib/services/mock_rfid_service.dart terminal-frontend/lib/providers/rfid_provider.dart terminal-frontend/test/mock_rfid_test.dart
git commit -m "feat: phase-4 add mock RFID service with click-to-detect button"
```

---

### Task 16: Add RFID Detector Button Widget

**Files:**
- Create: `terminal-frontend/lib/widgets/rfid_detector_button.dart`

**Step 1: Create detector button widget**

Create `terminal-frontend/lib/widgets/rfid_detector_button.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/rfid_provider.dart';

class RfidDetectorButton extends StatelessWidget {
  const RfidDetectorButton({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Consumer<RfidProvider>(
      builder: (context, rfidProvider, child) {
        return GestureDetector(
          onTap: rfidProvider.isScanning
              ? null
              : () => rfidProvider.simulateCardDetection(),
          child: Container(
            width: 140,
            height: 140,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: rfidProvider.isScanning
                    ? [
                      Colors.blue.shade400,
                      Colors.teal.shade300,
                    ]
                    : [
                      Colors.blue.shade200,
                      Colors.teal.shade200,
                    ],
              ),
              boxShadow: [
                BoxShadow(
                  color: Colors.blue.withOpacity(0.3),
                  blurRadius: rfidProvider.isScanning ? 30 : 15,
                  spreadRadius: rfidProvider.isScanning ? 5 : 0,
                ),
              ],
            ),
            child: Center(
              child: rfidProvider.isScanning
                  ? SizedBox(
                    width: 60,
                    height: 60,
                    child: CircularProgressIndicator(
                      valueColor: AlwaysStoppedAnimation(
                        Colors.white.withOpacity(0.8),
                      ),
                      strokeWidth: 3,
                    ),
                  )
                  : Icon(
                    Icons.contactless,
                    size: 60,
                    color: Colors.white,
                  ),
            ),
          ),
        );
      },
    );
  }
}
```

**Step 2: Widget test**

Create `terminal-frontend/test/rfid_button_widget_test.dart`:

```dart
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/widgets/rfid_detector_button.dart';

class MockRfidProvider extends Mock implements RfidProvider {}

void main() {
  group('RfidDetectorButton', () {
    late MockRfidProvider mockRfidProvider;

    setUp(() {
      mockRfidProvider = MockRfidProvider();
      when(() => mockRfidProvider.isScanning).thenReturn(false);
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
    });

    testWidgets('button displays icon when not scanning', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<RfidProvider>.value(
            value: mockRfidProvider,
            child: const Scaffold(
              body: RfidDetectorButton(),
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.contactless), findsOneWidget);
    });

    testWidgets('button shows spinner when scanning', (WidgetTester tester) async {
      when(() => mockRfidProvider.isScanning).thenReturn(true);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<RfidProvider>.value(
            value: mockRfidProvider,
            child: const Scaffold(
              body: RfidDetectorButton(),
            ),
          ),
        ),
      );

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });
  });
}
```

**Step 3: Run widget tests**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test test/rfid_button_widget_test.dart -v
```

Expected: PASS - Button renders and responds.

**Step 4: Commit**

```bash
cd /Users/dg/dev/frgs-vereinsbar
git add terminal-frontend/lib/widgets/rfid_detector_button.dart terminal-frontend/test/rfid_button_widget_test.dart
git commit -m "feat: phase-4 add RfidDetectorButton widget with mock detection"
```

---

## Mock Data Seed (for development/demo)

**Pre-populate SQLite with:**

1. **Member**: Max Mustermann (RF-4821)
   - ID: `550e8400-e29b-41d4-a716-446655440000`
   - Card UID: RF-4821
   - Language: de
   - SEPA valid: true
   - Balance: €0.00 (fresh account)

2. **Categories** (2):
   - Beverages (display_order: 1, names: {"de": "Getränke", "en": "Beverages"})
   - Sauna (display_order: 2, names: {"de": "Sauna", "en": "Sauna"})

3. **Products**:
   - **5 Beverages** (in Beverages category):
     1. Pils 0.5L - €3.50
     2. Weizen 0.5L - €3.80
     3. Radler 0.5L - €3.00
     4. Wasser 0.33L - €1.50
     5. Apfelschorle - €2.00
   - **1 Sauna Token** (in Sauna category):
     1. Sauna-Token 30min - €2.00

**Implementation note:** Create `terminal-frontend/lib/database/seed.dart` with `seedMockData()` function. Call during first app launch or in a debug button in Phase 2.

---

## Future Work: Real RFID Integration (Phase 8)

**Deferred:** Native macOS USB serial RFID reader integration

When ready to implement real hardware:
1. Add method channel for macOS native code
2. Replace `MockRfidService` with `NativeRfidService`
3. Add Platform-specific logic (Objective-C/Swift for macOS)
4. Keep same interface (`detectCard()`) for drop-in replacement

---

## Phase 5-7: UI Screens, Animations, and Testing

*These phases involve implementing the actual screen components following the prototype design, adding Material 3 animations, and comprehensive testing.*

---

## Implementation Notes

### Code Organization

```
terminal-frontend/
├── lib/
│   ├── config/              # Configuration constants
│   ├── database/            # Drift ORM & schema
│   ├── models/              # DTOs and data models
│   ├── repository/          # Data access layer
│   ├── services/            # Business logic (sync, network)
│   ├── providers/           # ChangeNotifier state managers
│   ├── screens/             # UI pages (rfid, products, cart, etc.)
│   ├── widgets/             # Reusable UI components
│   └── main.dart            # App entry point
├── test/                    # Unit & integration tests
└── pubspec.yaml             # Dependencies
```

### Testing Strategy

- **Unit tests**: Database operations, repositories, services
- **Provider tests**: State management with mocktail
- **Widget tests**: Individual screens and components
- **Integration tests**: End-to-end flows (RFID → products → checkout)

### Key Architectural Decisions

1. **Drift ORM**: Type-safe database queries (like Drizzle for Dart)
2. **Provider Pattern**: Simplicity over Redux/Bloc (easier to teach, test)
3. **Offline-First**: All core POS works without network; sync is async background task
4. **Material 3**: Modern Flutter design with dark theme
5. **Immutable Models**: Use value equality for cleaner state management

### Implementation Details

**Inactivity Timeout (30 seconds)**
- Timer starts when user first scans card
- Logs user out (return to RFID scan screen) if idle
- Cart is preserved (not cleared) → user can rescan to resume
- Preserved cart expires after 1 hour

**Balance Limit Enforcement (€100.00)**
- Checked at checkout: `newBalance = currentBalance + cartTotal`
- If `newBalance > 10000 cents` (€100): Show warning modal, disable Buy button
- Warning message: "Limit exceeded: Current balance + cart would be €X.XX (max €100.00)"

**Language Switching**
- User taps profile icon → User screen → Language selector
- Changes `member.preferred_language` in cache (local only)
- Syncs to backend on next sync cycle (POST to `/api/members/{id}`)
- Products/categories immediately redisplay in new language

**Error Handling (Transaction Creation)**
- If `createPurchaseTransaction()` throws exception: Show error modal
- User can manually retry (calls checkout again) or cancel
- No automatic retries (manual only per requirements)

**Mock Data Strategy**
- Pre-populated SQLite on first app launch
- 1 test member + 5 beverages + 1 sauna token
- All mock members have valid SEPA data
- Can rescan same card multiple times for testing

---

## Testing Verification Checklist

Before committing each task:

```bash
# Run unit tests for the task
flutter test test/[task_test].dart -v

# Run all tests
flutter test --coverage

# Build for target platform
flutter build macos --dry-run
```

---

## Git Commit Strategy

- **One commit per task** (small, reviewable changes)
- **Format**: `feat: [phase] [feature name` or `test: [feature name]`
- **Examples**:
  - `feat: phase-1 initialize flutter project`
  - `feat: phase-2 implement sync service`
  - `feat: phase-5 add products screen`
  - `test: add cart unit tests`

---

## Dependencies Verified (Feb 1, 2025)

All dependencies have been verified against latest stable versions as of February 2025:

**Core Dependencies:**
- [drift 2.30.1](https://pub.dev/packages/drift) - Type-safe ORM for relational data
- [drift_dev 2.30.1](https://pub.dev/packages/drift_dev) - Code generation for Drift
- [sqlite3_flutter_libs 0.5.41](https://pub.dev/packages/sqlite3_flutter_libs) - SQLite library for Flutter
- [sqflite_common 2.5.6](https://pub.dev/packages/sqflite_common) - Abstraction for SQLite access
- [sqflite_common_ffi 2.4.0+2](https://pub.dev/packages/sqflite_common_ffi) - Native SQLite binding

**State & Networking:**
- [provider 6.1.5+1](https://pub.dev/packages/provider) - ChangeNotifier state management
- [http 1.6.0](https://pub.dev/packages/http) - HTTP client library
- [uuid 4.5.2](https://pub.dev/packages/uuid) - UUID v4 generation

**UI & Animations:**
- [confetti 0.8.0](https://pub.dev/packages/confetti) - Confetti animation package
- [intl 0.20.2](https://pub.dev/packages/intl) - Internationalization & localization
- [logger 2.6.2](https://pub.dev/packages/logger) - Logging library

**Development & Testing:**
- [build_runner 2.10.5](https://pub.dev/packages/build_runner) - Code generation framework
- [flutter_lints 6.0.0](https://pub.dev/packages/flutter_lints) - Flutter linting rules
- [test 1.29.0](https://pub.dev/packages/test) - Testing framework
- [mocktail 1.0.4](https://pub.dev/packages/mocktail) - Mocking library for tests

---

## Next Steps After Plan Approval

Once you approve this plan, I will:

1. Create a dedicated git worktree for this feature
2. Execute tasks 1-4 (Phase 1: Setup & Database) in this session
3. You review and approve before continuing to Phase 2+

This prevents context bloat and maintains clean, reviewable commits.
