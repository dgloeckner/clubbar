# Session ID & Balance Hardening Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the checkout confirmation screen DB-driven (no in-memory cart dependency) and ensure the member's Kontostand is fresh from the backend on every card scan.

**Architecture:** A UUID `session_id` is generated on RFID scan and stored on every local transaction row. The confirmation screen reads its data directly from these rows using two SQL queries. On card scan, any pending transactions are synced to the backend so the response includes an up-to-date `balanceCents`.

**Tech Stack:** Flutter/Drift ORM, SQLite, GoRouter, mocktail for mocks, flutter_test

---

## Task 1: Schema v5 → v6 — Add `session_id` and `unit_price_cents`

**Files:**
- Modify: `terminal-frontend/lib/database/schema/transactions_local.dart`
- Modify: `terminal-frontend/lib/database/database.dart`
- Test: `terminal-frontend/test/database_test.dart`

### Step 1: Update the schema table

In `transactions_local.dart`, add two nullable columns after `dispenserActual`:

```dart
TextColumn get sessionId => text().nullable()();       // Groups transactions by login session
IntColumn get unitPriceCents => integer().nullable()(); // Per-unit price at time of purchase
```

Full file after change:
```dart
import 'package:drift/drift.dart';
import 'members_cache.dart';
import 'products_cache.dart';

class TransactionsLocal extends Table {
  TextColumn get id => text()();
  TextColumn get memberId => text().references(MembersCache, #id)();
  TextColumn get productId => text().nullable().references(ProductsCache, #id)();
  IntColumn get amountCents => integer()();
  TextColumn get transactionType => text()();
  TextColumn get notes => text().nullable()();
  TextColumn get createdAt => text()();
  IntColumn get synced => integer().withDefault(Constant(0))();
  TextColumn get dispenserTxId => text().nullable()();
  IntColumn get dispenserRequested => integer().nullable()();
  IntColumn get dispenserActual => integer().nullable()();
  TextColumn get sessionId => text().nullable()();
  IntColumn get unitPriceCents => integer().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}
```

### Step 2: Bump schema version and add migration in `database.dart`

Change `schemaVersion` from `5` to `6`, and add the migration block:

```dart
@override
int get schemaVersion => 6;
```

In the `onUpgrade` handler, after the existing `if (from < 5)` block, add:

```dart
if (from < 6) {
  await _addColumnIfNotExists(
      m, 'transactions_local', 'session_id', 'TEXT');
  await _addColumnIfNotExists(
      m, 'transactions_local', 'unit_price_cents', 'INTEGER');
}
```

### Step 3: Regenerate Drift code

Run from `terminal-frontend/`:
```bash
dart run build_runner build --delete-conflicting-outputs
```

Expected: generates updated `database.g.dart` with new fields on `TransactionsLocalData` and `TransactionsLocalCompanion`.

### Step 4: Update the fallback value in `cart_service_test.dart`

The `setUpAll` in `test/services/cart_service_test.dart` constructs a `TransactionsLocalData` as a fallback value. After code-gen, this constructor will require the two new nullable fields (they default to `null`, so just add them explicitly):

```dart
registerFallbackValue(TransactionsLocalData(
  id: 'test-id',
  memberId: 'test-member',
  productId: null,
  amountCents: 0,
  transactionType: 'purchase',
  notes: null,
  createdAt: DateTime.now().toIso8601String(),
  synced: 0,
  sessionId: null,      // ADD
  unitPriceCents: null, // ADD
));
```

Check all other test files that construct `TransactionsLocalData` directly and add the two `null` fields. Use:
```bash
grep -r "TransactionsLocalData(" terminal-frontend/test/
```

### Step 5: Run the test suite

```bash
cd terminal-frontend && flutter test
```

Expected: all tests pass. Any `TransactionsLocalData(` constructor call missing the new fields will cause a compile error — fix them.

### Step 6: Commit

```bash
git add terminal-frontend/lib/database/schema/transactions_local.dart \
        terminal-frontend/lib/database/database.dart \
        terminal-frontend/lib/database/database.g.dart \
        terminal-frontend/test/
git commit -m "Task 1: Schema v6 — add session_id and unit_price_cents to transactions_local"
```

---

## Task 2: `MembersProvider` — Generate `sessionId` on Login

**Files:**
- Modify: `terminal-frontend/lib/providers/members_provider.dart`
- Test: `terminal-frontend/test/providers/members_provider_test.dart`

### Step 1: Write a failing test

Open `test/providers/members_provider_test.dart` and add a test (in the appropriate group):

```dart
test('selectMemberByRfid sets a non-null sessionId on success', () async {
  // Arrange: mock service returns a member
  when(() => mockService.lookupByRfid(any()))
      .thenAnswer((_) async => (fakeMember, null));
  when(() => mockService.getEffectiveBalance(any()))
      .thenAnswer((_) async => 0);

  // Act
  await provider.selectMemberByRfid('test-uid');

  // Assert
  expect(provider.sessionId, isNotNull);
  expect(provider.sessionId, hasLength(greaterThan(0)));
});

test('clearSelectedMember clears sessionId', () async {
  when(() => mockService.lookupByRfid(any()))
      .thenAnswer((_) async => (fakeMember, null));
  when(() => mockService.getEffectiveBalance(any()))
      .thenAnswer((_) async => 0);
  await provider.selectMemberByRfid('test-uid');

  provider.clearSelectedMember();

  expect(provider.sessionId, isNull);
});

test('setSelectedMember sets a non-null sessionId', () async {
  when(() => mockService.getEffectiveBalance(any()))
      .thenAnswer((_) async => 0);

  await provider.setSelectedMember(fakeMember);

  expect(provider.sessionId, isNotNull);
});
```

### Step 2: Run the test to confirm it fails

```bash
cd terminal-frontend && flutter test test/providers/members_provider_test.dart
```

Expected: FAIL — `provider.sessionId` not found (getter does not exist yet).

### Step 3: Implement

Add `_sessionId` field and `sessionId` getter. Generate UUID using `package:uuid` (already in `pubspec.yaml` via `cart_service.dart`).

At the top of `members_provider.dart`, add the import:
```dart
import 'package:uuid/uuid.dart';
```

Add to the class fields (alongside `_selectedMember`):
```dart
static const _uuid = Uuid();
String? _sessionId;
```

Add the getter (alongside `selectedMember`):
```dart
String? get sessionId => _sessionId;
```

In `selectMemberByRfid()`, inside the `if (member != null && error == null)` block, generate the session ID:
```dart
_selectedMember = member;
_sessionId = _uuid.v4();   // ADD THIS LINE
_memberDeckel = await _service.getEffectiveBalance(member);
```

In `setSelectedMember()`, generate the session ID before `notifyListeners()`:
```dart
_selectedMember = member;
_sessionId = _uuid.v4();   // ADD THIS LINE
_memberDeckel = await _service.getEffectiveBalance(member);
```

In `clearSelectedMember()`, clear the session ID:
```dart
void clearSelectedMember() {
  _selectedMember = null;
  _memberDeckel = null;
  _sessionId = null;   // ADD THIS LINE
  _localeProvider?.resetToDefault();
  notifyListeners();
}
```

### Step 4: Run the tests

```bash
cd terminal-frontend && flutter test test/providers/members_provider_test.dart
```

Expected: all tests pass including the new ones.

### Step 5: Full test suite

```bash
cd terminal-frontend && flutter test
```

Expected: all pass.

### Step 6: Commit

```bash
git add terminal-frontend/lib/providers/members_provider.dart \
        terminal-frontend/test/providers/members_provider_test.dart
git commit -m "Task 2: MembersProvider generates sessionId UUID on card scan"
```

---

## Task 3: `TransactionsRepository` — Session Queries

**Files:**
- Modify: `terminal-frontend/lib/repository/transactions_repository.dart`
- Test: `terminal-frontend/test/repository_test.dart`

### Step 1: Write failing tests

In `test/repository_test.dart`, add tests for the two new methods. Find the existing test group for `TransactionsRepository` and add:

```dart
group('session queries', () {
  test('getSessionTotal returns sum of abs(amountCents) for session', () async {
    final db = RuderbarDatabase.forTesting(NativeDatabase.memory());
    final repo = TransactionsRepository(db);
    // Insert member first (FK constraint)
    await db.into(db.membersCache).insert(MembersCacheCompanion(
      id: const Value('m1'), cardUid: const Value('c1'),
      firstName: const Value('A'), lastName: const Value('B'),
      preferredLanguage: const Value('de'), isActive: const Value(1),
      isSepaValid: const Value(1), updatedAt: const Value('2025-01-01T00:00:00Z'),
    ));
    // Insert two transactions with session_id 'sess-1'
    await db.into(db.transactionsLocal).insert(TransactionsLocalCompanion(
      id: const Value('t1'), memberId: const Value('m1'),
      amountCents: const Value(350), transactionType: const Value('purchase'),
      createdAt: const Value('2025-01-01T12:00:00Z'), synced: const Value(0),
      sessionId: const Value('sess-1'), unitPriceCents: const Value(350),
    ));
    await db.into(db.transactionsLocal).insert(TransactionsLocalCompanion(
      id: const Value('t2'), memberId: const Value('m1'),
      amountCents: const Value(500), transactionType: const Value('purchase'),
      createdAt: const Value('2025-01-01T12:00:01Z'), synced: const Value(0),
      sessionId: const Value('sess-1'), unitPriceCents: const Value(500),
    ));
    // Insert transaction with different session
    await db.into(db.transactionsLocal).insert(TransactionsLocalCompanion(
      id: const Value('t3'), memberId: const Value('m1'),
      amountCents: const Value(999), transactionType: const Value('purchase'),
      createdAt: const Value('2025-01-01T13:00:00Z'), synced: const Value(0),
      sessionId: const Value('sess-2'),
    ));

    final total = await repo.getSessionTotal('sess-1');

    expect(total, 850); // 350 + 500
    await db.close();
  });

  test('getSessionDispenserInfo returns dispenser row for session', () async {
    final db = RuderbarDatabase.forTesting(NativeDatabase.memory());
    final repo = TransactionsRepository(db);
    await db.into(db.membersCache).insert(MembersCacheCompanion(
      id: const Value('m1'), cardUid: const Value('c1'),
      firstName: const Value('A'), lastName: const Value('B'),
      preferredLanguage: const Value('de'), isActive: const Value(1),
      isSepaValid: const Value(1), updatedAt: const Value('2025-01-01T00:00:00Z'),
    ));
    await db.into(db.transactionsLocal).insert(TransactionsLocalCompanion(
      id: const Value('t1'), memberId: const Value('m1'),
      amountCents: const Value(500), transactionType: const Value('purchase'),
      createdAt: const Value('2025-01-01T12:00:00Z'), synced: const Value(0),
      sessionId: const Value('sess-1'),
      dispenserTxId: const Value('disp-tx-1'),
      dispenserRequested: const Value(5),
      dispenserActual: const Value(2),
      unitPriceCents: const Value(500),
    ));

    final info = await repo.getSessionDispenserInfo('sess-1');

    expect(info, isNotNull);
    expect(info!.dispenserRequested, 5);
    expect(info.dispenserActual, 2);
    expect(info.unitPriceCents, 500);
    await db.close();
  });

  test('getSessionDispenserInfo returns null when no dispenser row', () async {
    final db = RuderbarDatabase.forTesting(NativeDatabase.memory());
    final repo = TransactionsRepository(db);

    final info = await repo.getSessionDispenserInfo('no-such-session');

    expect(info, isNull);
    await db.close();
  });
});
```

Note: you need the `NativeDatabase` import in the test file:
```dart
import 'package:drift/native.dart';
```

### Step 2: Run test to confirm it fails

```bash
cd terminal-frontend && flutter test test/repository_test.dart
```

Expected: FAIL — methods `getSessionTotal` and `getSessionDispenserInfo` do not exist.

### Step 3: Implement the two methods

In `terminal-frontend/lib/repository/transactions_repository.dart`, add after `getIncompleteDispenserTransactions()`:

```dart
/// Sum of abs(amountCents) for all transactions belonging to a session.
/// This is the actual billed amount for the checkout session.
Future<int> getSessionTotal(String sessionId) async {
  final rows = await (_db.select(_db.transactionsLocal)
        ..where((t) => t.sessionId.equals(sessionId)))
      .get();
  return rows.fold<int>(0, (sum, t) => sum + t.amountCents.abs());
}

/// Returns the dispenser row for a session (the row that has a dispenserTxId),
/// or null if the session had no dispenser items.
Future<TransactionsLocalData?> getSessionDispenserInfo(String sessionId) async {
  return (_db.select(_db.transactionsLocal)
        ..where((t) =>
            t.sessionId.equals(sessionId) & t.dispenserTxId.isNotNull())
        ..limit(1))
      .getSingleOrNull();
}
```

### Step 4: Run the tests

```bash
cd terminal-frontend && flutter test test/repository_test.dart
```

Expected: all pass.

### Step 5: Full suite

```bash
cd terminal-frontend && flutter test
```

Expected: all pass.

### Step 6: Commit

```bash
git add terminal-frontend/lib/repository/transactions_repository.dart \
        terminal-frontend/test/repository_test.dart
git commit -m "Task 3: TransactionsRepository — getSessionTotal and getSessionDispenserInfo queries"
```

---

## Task 4: `CartService` — Persist `sessionId` and `unitPriceCents`

**Files:**
- Modify: `terminal-frontend/lib/services/cart_service.dart`
- Test: `terminal-frontend/test/services/cart_service_test.dart`

### Step 1: Write failing tests

In `test/services/cart_service_test.dart`, add tests that verify the new params are accepted and that `insertTransactionCompanion` is called (since we'll switch `createTransaction` to use `Companion` now):

Add a test that calls `createTransaction` with `sessionId`:

```dart
test('createTransaction accepts sessionId and unitPriceCents', () async {
  when(() => mockRepo.insertTransactionCompanion(any()))
      .thenAnswer((_) async {});

  final (txnId, error) = await service.createTransaction(
    member,
    items,
    sessionId: 'test-session-id',
  );

  expect(txnId, isNotNull);
  expect(error, isNull);
  // Verify insertTransactionCompanion was called (not insertTransaction)
  verify(() => mockRepo.insertTransactionCompanion(any())).called(greaterThan(0));
});
```

Also update the existing `createTransaction` test to pass the new required param.

### Step 2: Run test to confirm it fails

```bash
cd terminal-frontend && flutter test test/services/cart_service_test.dart
```

Expected: FAIL — `createTransaction` doesn't accept `sessionId` yet.

### Step 3: Update `createTransaction` in `cart_service.dart`

Change the signature to accept `sessionId`:

```dart
Future<(String?, String?)> createTransaction(
  MembersCacheData member,
  List<CartItem> items, {
  required String sessionId,
}) async {
```

Change the loop body to use `TransactionsLocalCompanion` (supports all nullable fields) instead of `TransactionsLocalData`:

```dart
for (final item in items) {
  for (var i = 0; i < item.quantity; i++) {
    final txnId = _uuid.v4();
    firstTxnId ??= txnId;

    final companion = TransactionsLocalCompanion(
      id: Value(txnId),
      memberId: Value(member.id),
      productId: Value(item.productId),
      amountCents: Value(item.priceCents),
      transactionType: const Value('purchase'),
      notes: const Value(null),
      createdAt: Value(now),
      synced: const Value(0),
      sessionId: Value(sessionId),
      unitPriceCents: Value(item.priceCents),
    );

    await _repository.insertTransactionCompanion(companion);
  }
}
```

### Step 4: Update `createTransactionsFromDispenseResult` signature

Add `sessionId` and `unitPriceCents` params:

```dart
Future<(String?, String?)> createTransactionsFromDispenseResult({
  required String dispenserTxId,
  required String memberId,
  required String productId,
  required int priceCents,
  required int requestedQty,
  required int actualDispensed,
  required String sessionId,      // ADD
}) async {
```

In the loop body, add both fields to the existing `TransactionsLocalCompanion`:

```dart
sessionId: Value(sessionId),
unitPriceCents: Value(priceCents), // unit price = priceCents per token
```

### Step 5: Run the tests

```bash
cd terminal-frontend && flutter test test/services/cart_service_test.dart
```

Expected: all pass.

### Step 6: Commit

```bash
git add terminal-frontend/lib/services/cart_service.dart \
        terminal-frontend/test/services/cart_service_test.dart
git commit -m "Task 4: CartService — persist sessionId and unitPriceCents on transactions"
```

---

## Task 5: `CartProvider` — Pass `sessionId`, Remove In-Memory Financial State

**Files:**
- Modify: `terminal-frontend/lib/providers/cart_provider.dart`
- Modify: `terminal-frontend/lib/screens/shopping_cart_screen.dart`
- Test: `terminal-frontend/test/providers/cart_provider_test.dart`

### Step 1: Write failing tests

In `test/providers/cart_provider_test.dart`, add a test that `checkout()` now takes a `sessionId` param (not just `member`), and that `lastCheckoutTotalCents` and `lastPartialDispenseInfo` are gone:

```dart
test('checkout passes sessionId to CartService', () async {
  // Existing test updated to pass sessionId
  // Verify that service.createTransaction is called with sessionId
  // (exact mock verification depends on existing test structure)
});
```

Also verify that after `checkout()`, `lastCheckoutTotalCents` does not exist (compile check is sufficient once getter is removed).

### Step 2: Update `checkout()` signature in `cart_provider.dart`

Change:
```dart
Future<void> checkout(BuildContext context, MembersCacheData member) async {
```
To:
```dart
Future<void> checkout(BuildContext context, MembersCacheData member, String sessionId) async {
```

### Step 3: Remove `_lastPartialDispenseInfo` field and getter

Remove these lines from `cart_provider.dart`:
- `PartialDispenseInfo? _lastPartialDispenseInfo;` (field)
- `PartialDispenseInfo? get lastPartialDispenseInfo => _lastPartialDispenseInfo;` (getter)
- All assignments to `_lastPartialDispenseInfo` in `checkout()`
- The `if (_lastPartialDispenseInfo != null ...)` block that computed `_lastCheckoutTotalCents`
- The partial dispense info tracking section in checkout
- The import: `import 'package:ruderbar_terminal/models/partial_dispense_info.dart';`

Remove these lines:
- `int? _lastCheckoutTotalCents;` (field)
- `int? get lastCheckoutTotalCents => _lastCheckoutTotalCents;` (getter)
- The `_lastCheckoutTotalCents = ...` assignment block before `_items = []`
- `_lastCheckoutTotalCents = null;` in `clearCart()`

### Step 4: Pass `sessionId` to CartService calls

In `checkout()`, pass `sessionId` to both cart service calls:

```dart
// Regular products:
final (txnId, createError) = await _service.createTransaction(
  member, regularProducts,
  sessionId: sessionId,  // ADD
);

// Dispenser products:
final (tokenTxnId, tokenError) =
    await _service.createTransactionsFromDispenseResult(
  dispenserTxId: dispenserTxId,
  memberId: member.id,
  productId: tokenProduct.productId,
  priceCents: tokenProduct.priceCents,
  requestedQty: requestedQty,
  actualDispensed: actualQuantity,
  sessionId: sessionId,  // ADD
);
```

### Step 5: Update `clearCart()` to remove old fields (already done in Step 3)

Ensure `clearCart()` only clears: `_items`, `_lastError`, `_errorType`. Remove any clearing of `_lastPartialDispenseInfo` and `_lastCheckoutTotalCents`.

### Step 6: Expose `lastSessionId` from CartProvider

Add field and getter so the shopping cart screen can navigate to the confirmation URL:

```dart
String? _lastSessionId;
String? get lastSessionId => _lastSessionId;
```

In `checkout()`, after `_lastTransactionId ??= txnId` (or after the dispenser path), store the session ID:
```dart
_lastSessionId = sessionId;
```

In `clearCart()`:
```dart
_lastSessionId = null;
```

### Step 7: Update `shopping_cart_screen.dart` call site

Find line 370:
```dart
context.go('/confirmation/${cartProvider.lastTransactionId}');
```

Change to pass `sessionId`. Read it from `MembersProvider`:
```dart
final sessionId = context.read<MembersProvider>().sessionId ?? '';
context.go('/confirmation/$sessionId');
```

Also update the call to `checkout()`:
```dart
final sessionId = membersProvider.sessionId ?? '';
await cartProvider.checkout(context, member, sessionId);
```

### Step 8: Run tests

```bash
cd terminal-frontend && flutter test
```

Expected: all pass (after fixing any broken tests due to removed fields).

### Step 9: Commit

```bash
git add terminal-frontend/lib/providers/cart_provider.dart \
        terminal-frontend/lib/screens/shopping_cart_screen.dart \
        terminal-frontend/test/providers/cart_provider_test.dart
git commit -m "Task 5: CartProvider — pass sessionId, remove in-memory lastCheckoutTotalCents and lastPartialDispenseInfo"
```

---

## Task 6: Router + `CheckoutConfirmationScreen` — DB-Driven via `sessionId`

**Files:**
- Modify: `terminal-frontend/lib/config/app_router.dart`
- Modify: `terminal-frontend/lib/screens/checkout_confirmation_screen.dart`
- Test: `terminal-frontend/test/screens/checkout_confirmation_screen_test.dart`

### Step 1: Update the route in `app_router.dart`

Change:
```dart
path: '/confirmation/:transactionId',
pageBuilder: (context, state) {
  final transactionId = state.pathParameters['transactionId'] ?? '';
  return CustomTransitionPage(
    key: state.pageKey,
    child: CheckoutConfirmationScreen(transactionId: transactionId),
```

To:
```dart
path: '/confirmation/:sessionId',
pageBuilder: (context, state) {
  final sessionId = state.pathParameters['sessionId'] ?? '';
  return CustomTransitionPage(
    key: state.pageKey,
    child: CheckoutConfirmationScreen(sessionId: sessionId),
```

Also update the redirect guard — the path `/confirmation` still matches, so no change needed there.

### Step 2: Rewrite `CheckoutConfirmationScreen`

The screen no longer reads from `CartProvider` for financial data. It receives `sessionId` and queries the DB via `TransactionsRepository`.

Replace `final String transactionId` with `final String sessionId` in the widget constructor.

Remove imports:
```dart
import 'package:ruderbar_terminal/models/partial_dispense_info.dart';
```
(Leave `CartProvider` import for `clearCart()` in `_performNavigation()`.)

Add import:
```dart
import 'package:ruderbar_terminal/repository/transactions_repository.dart';
```

**Session data model** — add a private class at the top of the file:
```dart
class _SessionData {
  final int billedCents;
  final bool isPartial;
  final int? dispenserRequested;
  final int? dispenserActual;
  final int? originalTotalCents; // dispenserRequested * unitPriceCents

  const _SessionData({
    required this.billedCents,
    required this.isPartial,
    this.dispenserRequested,
    this.dispenserActual,
    this.originalTotalCents,
  });
}
```

**Loading logic** — add a `Future<_SessionData>` field loaded in `initState`:

```dart
late Future<_SessionData> _sessionDataFuture;

@override
void initState() {
  super.initState();
  _sessionDataFuture = _loadSessionData();
  // ... rest of initState unchanged (animation setup) ...
}

Future<_SessionData> _loadSessionData() async {
  final repo = context.read<TransactionsRepository>();
  final billedCents = await repo.getSessionTotal(widget.sessionId);
  final dispenserRow = await repo.getSessionDispenserInfo(widget.sessionId);

  final isPartial = dispenserRow != null &&
      dispenserRow.dispenserActual != null &&
      dispenserRow.dispenserRequested != null &&
      dispenserRow.dispenserActual! < dispenserRow.dispenserRequested!;

  int? originalTotalCents;
  if (isPartial && dispenserRow!.unitPriceCents != null) {
    originalTotalCents =
        dispenserRow.dispenserRequested! * dispenserRow.unitPriceCents!;
  }

  return _SessionData(
    billedCents: billedCents,
    isPartial: isPartial,
    dispenserRequested: dispenserRow?.dispenserRequested,
    dispenserActual: dispenserRow?.dispenserActual,
    originalTotalCents: originalTotalCents,
  );
}
```

**Auto-nav** — move the "is partial?" check into the `FutureBuilder`:

The existing `addPostFrameCallback` that reads `isPartial` from `CartProvider` is replaced. The auto-nav decision is made after the future resolves. Change `initState` to only set up animation — not the auto-nav timer. Instead, trigger auto-nav inside the `FutureBuilder` `onData` callback via a one-shot `WidgetsBinding.instance.addPostFrameCallback` call from within `build`.

A clean pattern is: add a `bool _autoNavStarted = false;` field. In `build`, inside the `FutureBuilder` snapshot check, if data is loaded and `!_autoNavStarted && !data.isPartial`, call `_startAutoNav()` and set `_autoNavStarted = true`.

**`build()` method** — wrap content in a `FutureBuilder<_SessionData>`:

```dart
return FutureBuilder<_SessionData>(
  future: _sessionDataFuture,
  builder: (context, snapshot) {
    if (!snapshot.hasData) {
      return const Center(child: CircularProgressIndicator());
    }
    final data = snapshot.data!;
    // Trigger auto-nav on first successful load for non-partial sessions
    if (!_autoNavStarted && !data.isPartial) {
      _autoNavStarted = true;
      WidgetsBinding.instance.addPostFrameCallback((_) => _startAutoNav());
    }

    // ... rest of your existing build body, but replace:
    // - `isPartial` with `data.isPartial`
    // - `cartTotal` (was lastCheckoutTotalCents) with `data.billedCents`
    // - `partialDispenseInfo!.actualDispensed` with `data.dispenserActual ?? 0`
    // - `partialDispenseInfo!.originalTotalCents` with `data.originalTotalCents ?? 0`
    // Keep: memberName, newBalance from MembersProvider
    // Keep: widget.sessionId displayed as reference ID (replaces widget.transactionId)
  },
);
```

**Remove**: `final cartTotal = context.watch<CartProvider>().lastCheckoutTotalCents ?? 0;`
**Remove**: `final partialDispenseInfo = context.watch<CartProvider>().lastPartialDispenseInfo;`
**Remove**: `final isPartial = partialDispenseInfo?.isPartial ?? false;`

### Step 3: Run analyze

```bash
cd terminal-frontend && flutter analyze
```

Expected: no errors.

### Step 4: Run tests

```bash
cd terminal-frontend && flutter test test/screens/checkout_confirmation_screen_test.dart
```

Update broken tests: they no longer set up `CartProvider.lastPartialDispenseInfo` — instead they use a `TransactionsRepository` mock that returns session data.

### Step 5: Full suite

```bash
cd terminal-frontend && flutter test
```

Expected: all pass.

### Step 6: Commit

```bash
git add terminal-frontend/lib/config/app_router.dart \
        terminal-frontend/lib/screens/checkout_confirmation_screen.dart \
        terminal-frontend/test/screens/checkout_confirmation_screen_test.dart
git commit -m "Task 6: CheckoutConfirmationScreen is now DB-driven via sessionId"
```

---

## Task 7: Delete `PartialDispenseInfo` Model

**Files:**
- Delete: `terminal-frontend/lib/models/partial_dispense_info.dart`

### Step 1: Verify no remaining imports

```bash
grep -r "partial_dispense_info" terminal-frontend/lib/ terminal-frontend/test/
```

Expected: no output. If any files are found, remove the import and any usages before deleting.

### Step 2: Delete the file

```bash
rm terminal-frontend/lib/models/partial_dispense_info.dart
```

### Step 3: Run analyze and tests

```bash
cd terminal-frontend && flutter analyze && flutter test
```

Expected: all pass with no "target of URI doesn't exist" errors.

### Step 4: Commit

```bash
git add -A terminal-frontend/lib/models/partial_dispense_info.dart
git commit -m "Task 7: Delete PartialDispenseInfo model — replaced by DB session queries"
```

---

## Task 8: Fresh Balance on Card Scan

**Background:** The existing `GET /sync/members` endpoint does not return `balance_cents` (balance comes only from the transaction sync response). On card scan, we trigger a transaction sync (`POST /sync/transactions`) with any currently unsynced transactions. The response includes `member_balances` which we apply to the cache. If the network is unavailable or the backend returns no balance for this member (no transactions processed), we fall back to the cached balance and show a "balance may be outdated" indicator.

**Files:**
- Modify: `terminal-frontend/lib/services/members_service.dart`
- Modify: `terminal-frontend/lib/providers/members_provider.dart`
- Modify: `terminal-frontend/lib/services/network_service.dart` (expose `syncTransactions` return type)
- Modify: `terminal-frontend/lib/main.dart` (inject `NetworkService` into `MembersService`)
- Modify: `terminal-frontend/lib/screens/product_selection_screen.dart` (or wherever `memberDeckel` is displayed — show indicator)
- Test: `terminal-frontend/test/services/members_service_test.dart`

### Step 1: Write a failing test

In `test/services/members_service_test.dart`, add a test that verifies the balance is refreshed from the network on a successful RFID lookup:

```dart
test('lookupByRfid triggers balance refresh and updates cache on success', () async {
  when(() => mockRepo.findByCardUid('card-uid'))
      .thenAnswer((_) async => (fakeMember, null));
  when(() => mockNetworkService.syncTransactions(any()))
      .thenAnswer((_) async => TransactionSyncResponse(
        acceptedIds: [],
        rejected: RejectedTransactions(count: 0, errors: []),
        memberBalances: {'member-1': 4200}, // fresh balance
      ));
  when(() => mockTransactionsRepo.getUnsyncedTransactions())
      .thenAnswer((_) async => []);
  when(() => mockRepo.updateMemberBalance('member-1', 4200))
      .thenAnswer((_) async {});

  await service.lookupByRfid('card-uid');

  verify(() => mockRepo.updateMemberBalance('member-1', 4200)).called(1);
});

test('lookupByRfid succeeds even when network sync throws', () async {
  when(() => mockRepo.findByCardUid('card-uid'))
      .thenAnswer((_) async => (fakeMember, null));
  when(() => mockNetworkService.syncTransactions(any()))
      .thenThrow(NetworkException('offline'));
  when(() => mockTransactionsRepo.getUnsyncedTransactions())
      .thenAnswer((_) async => []);

  final (member, error) = await service.lookupByRfid('card-uid');

  expect(member, isNotNull);
  expect(error, isNull);
});
```

### Step 2: Run test to confirm it fails

```bash
cd terminal-frontend && flutter test test/services/members_service_test.dart
```

Expected: FAIL — `MembersService` doesn't accept `NetworkService` yet.

### Step 3: Add `NetworkService` dependency to `MembersService`

Change the constructor to accept an optional `NetworkService`:

```dart
class MembersService {
  final MembersRepository _repository;
  final TransactionsRepository _transactionsRepository;
  final NetworkService? _networkService;  // ADD — nullable for offline/test use

  MembersService({
    required MembersRepository repository,
    required TransactionsRepository transactionsRepository,
    NetworkService? networkService,       // ADD
  })  : _repository = repository,
        _transactionsRepository = transactionsRepository,
        _networkService = networkService; // ADD
```

Add import at top:
```dart
import 'package:ruderbar_terminal/services/network_service.dart';
import 'package:ruderbar_terminal/models/transaction_sync_response.dart';
```

### Step 4: Add `_refreshBalance` helper

Add a private method to `MembersService`:

```dart
/// Attempt to refresh member balance from backend.
/// Sends any unsynced transactions (opportunistic sync) and reads the
/// backend's current balance from the response.
/// Silently swallows all errors — offline is fine.
Future<void> _refreshBalance(String memberId) async {
  if (_networkService == null) return;
  try {
    final unsynced = await _transactionsRepository.getUnsyncedTransactions();
    // Serialize transactions (same format as SyncService uses)
    final payload = unsynced.map((t) => {
      'id': t.id,
      'member_id': t.memberId,
      'product_id': t.productId,
      'amount_cents': t.amountCents,
      'transaction_type': t.transactionType,
      'notes': t.notes,
      'created_at': t.createdAt,
    }).toList();
    final response = await _networkService!.syncTransactions(payload);
    final freshBalance = response.memberBalances[memberId];
    if (freshBalance != null) {
      await _repository.updateMemberBalance(memberId, freshBalance);
    }
  } catch (_) {
    // Network unavailable or error — silent fallback to cached balance
  }
}
```

### Step 5: Call `_refreshBalance` in `lookupByRfid`

```dart
Future<(MembersCacheData?, String?)> lookupByRfid(String cardUid) async {
  final (member, error) = await _repository.findByCardUid(cardUid);
  if (member == null) return (member, error);

  await _refreshBalance(member.id);   // ADD

  // Re-read from DB in case balance was updated
  final updated = await (_repository as dynamic).findById(member.id);
  return (updated ?? member, null);
}
```

Note: `MembersRepository` currently doesn't have a `findById` method. Add one:

```dart
// In members_repository.dart
Future<MembersCacheData?> findById(String memberId) async {
  return (_db.select(_db.membersCache)
        ..where((m) => m.id.equals(memberId)))
      .getSingleOrNull();
}
```

Then in `MembersService.lookupByRfid()`:
```dart
final updated = await _repository.findById(member.id);
return (updated ?? member, null);
```

### Step 6: Add `balanceMayBeOutdated` flag to `MembersProvider`

In `MembersProvider`, add a field:
```dart
bool _balanceMayBeOutdated = false;
bool get balanceMayBeOutdated => _balanceMayBeOutdated;
```

Expose it after `selectMemberByRfid()`. Since `_refreshBalance` is silent inside `MembersService`, the indicator is simple: if network is unavailable, `_networkService` returns nothing useful. For now, the simplest implementation is to always show the indicator and hide it after a successful sync. This can be a follow-up.

For MVP: after `selectMemberByRfid()` succeeds, set `_balanceMayBeOutdated = false` (assume sync worked since `lookupByRfid` tried).

### Step 7: Inject `NetworkService` in `main.dart`

Find where `MembersService` is constructed in `main.dart` and add the `networkService` param:

```dart
final membersService = MembersService(
  repository: membersRepo,
  transactionsRepository: transactionsRepo,
  networkService: networkService,  // ADD — networkService is already instantiated earlier in main.dart
);
```

### Step 8: (Optional) Show indicator in UI

In `terminal-frontend/lib/widgets/main_layout.dart` or whichever widget shows `memberDeckel`, add a small indicator when `MembersProvider.balanceMayBeOutdated` is true. This is a UX detail and can be deferred if time-constrained.

### Step 9: Run all tests

```bash
cd terminal-frontend && flutter test
```

Expected: all pass.

### Step 10: Commit

```bash
git add terminal-frontend/lib/services/members_service.dart \
        terminal-frontend/lib/repository/members_repository.dart \
        terminal-frontend/lib/providers/members_provider.dart \
        terminal-frontend/lib/main.dart \
        terminal-frontend/test/services/members_service_test.dart
git commit -m "Task 8: Fresh balance fetch from backend on card scan via opportunistic transaction sync"
```

---

## Completion Checklist

After all tasks are committed, verify end-to-end manually:

1. Launch the terminal app in a connected state
2. Scan a member card → note the Kontostand shown
3. Add items to cart including a token product
4. Complete checkout with a partial dispense (if dispenser available)
5. Verify confirmation screen shows:
   - Correct billed amount (sum of dispensed tokens × price)
   - Crossed-out original amount (for partial dispense)
   - Correct token count in title
   - No auto-close for partial; confirm button present
6. Kill and relaunch app → scan the same card → confirmation screen values are unchanged (DB-driven)
7. Simulate SEPA settlement on backend → scan card → Kontostand reflects the settled amount

---

## Reference: Key File Paths

| Component | File |
|-----------|------|
| DB schema | `terminal-frontend/lib/database/schema/transactions_local.dart` |
| DB migrations | `terminal-frontend/lib/database/database.dart` |
| Session ID generation | `terminal-frontend/lib/providers/members_provider.dart` |
| Session queries | `terminal-frontend/lib/repository/transactions_repository.dart` |
| Transaction write | `terminal-frontend/lib/services/cart_service.dart` |
| Cart orchestration | `terminal-frontend/lib/providers/cart_provider.dart` |
| Route config | `terminal-frontend/lib/config/app_router.dart` |
| Confirmation screen | `terminal-frontend/lib/screens/checkout_confirmation_screen.dart` |
| Balance refresh | `terminal-frontend/lib/services/members_service.dart` |
| DI wiring | `terminal-frontend/lib/main.dart` |
