# Phase 3: Provider-Based State Management Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Implement state management layer with services (MembersService, ProductsService, CartService) and five providers (AuthProvider, MembersProvider, ProductsProvider, CartProvider, SyncProvider) to manage application state and coordinate offline-first synchronization.

**Architecture:** Three-tier design with repositories (data access) → services (business logic) → providers (UI state). Services wrap repositories, providers use services. Background sync every 60 seconds (non-blocking). All providers track detailed error state (isLoading, isSyncing, lastError, errorType).

**Tech Stack:** Flutter, Provider, Drift ORM, mocktail (mocking)

---

## Task 1: MembersService

**Files:**
- Create: `lib/services/members_service.dart`
- Test: `test/services/members_service_test.dart`

**Overview:** Wrap MembersRepository with business logic: RFID lookup with validation (active account, SEPA mandate valid), getAllMembers(), refreshMembers().

**Step 1: Write the failing test file**

Create `test/services/members_service_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/services/members_service.dart';

class MockMembersRepository extends Mock implements MembersRepository {}

void main() {
  group('MembersService', () {
    late MockMembersRepository mockRepo;
    late MembersService service;

    setUp(() {
      mockRepo = MockMembersRepository();
      service = MembersService(repository: mockRepo);
    });

    test('lookupByRfid returns member when found and valid', () async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      when(() => mockRepo.findByCardUid('card-123'))
          .thenAnswer((_) async => testMember);

      final (member, error) = await service.lookupByRfid('card-123');

      expect(member, equals(testMember));
      expect(error, isNull);
      verify(() => mockRepo.findByCardUid('card-123')).called(1);
    });

    test('lookupByRfid returns error when member not found', () async {
      when(() => mockRepo.findByCardUid('invalid-card'))
          .thenAnswer((_) async => null);

      final (member, error) = await service.lookupByRfid('invalid-card');

      expect(member, isNull);
      expect(error, contains('not found'));
      verify(() => mockRepo.findByCardUid('invalid-card')).called(1);
    });

    test('lookupByRfid returns error when account inactive', () async {
      final inactiveMember = MembersCacheData(
        id: 'member-2',
        cardUid: 'card-456',
        firstName: 'Jane',
        lastName: 'Smith',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: false,
      );

      when(() => mockRepo.findByCardUid('card-456'))
          .thenAnswer((_) async => inactiveMember);

      final (member, error) = await service.lookupByRfid('card-456');

      expect(member, isNull);
      expect(error, contains('inactive'));
    });

    test('lookupByRfid returns error when SEPA mandate not signed', () async {
      final noMandateMember = MembersCacheData(
        id: 'member-3',
        cardUid: 'card-789',
        firstName: 'Bob',
        lastName: 'Johnson',
        iban: 'DE89370400440532013000',
        mandateSigned: false,
        active: true,
      );

      when(() => mockRepo.findByCardUid('card-789'))
          .thenAnswer((_) async => noMandateMember);

      final (member, error) = await service.lookupByRfid('card-789');

      expect(member, isNull);
      expect(error, contains('SEPA'));
    });

    test('getAllMembers returns list from repository', () async {
      final members = [
        MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'John',
          lastName: 'Doe',
          iban: 'DE89370400440532013000',
          mandateSigned: true,
          active: true,
        ),
      ];

      when(() => mockRepo.getAllActive()).thenAnswer((_) async => members);

      final result = await service.getAllMembers();

      expect(result, equals(members));
      verify(() => mockRepo.getAllActive()).called(1);
    });

    test('refreshMembers returns list from repository', () async {
      final members = <MembersCacheData>[];

      when(() => mockRepo.getAllActive()).thenAnswer((_) async => members);

      final result = await service.refreshMembers();

      expect(result, equals(members));
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test test/services/members_service_test.dart
```

Expected: FAIL - `MembersService` not defined

**Step 3: Write minimal implementation**

Create `lib/services/members_service.dart`:

```dart
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';

class MembersService {
  final MembersRepository _repository;

  MembersService({required MembersRepository repository})
      : _repository = repository;

  /// Look up member by RFID card UID
  /// Returns tuple: (member, errorMessage)
  /// Error message is null if member found and valid
  Future<(MembersCacheData?, String?)> lookupByRfid(String cardUid) async {
    try {
      final member = await _repository.findByCardUid(cardUid);

      if (member == null) {
        return (null, 'Member not found');
      }

      // Validate member is active
      if (!member.active) {
        return (null, 'Member account is inactive');
      }

      // Validate SEPA mandate signed
      if (!member.mandateSigned) {
        return (null, 'SEPA mandate not signed');
      }

      return (member, null);
    } catch (e) {
      return (null, 'Error looking up member: $e');
    }
  }

  /// Get all active members
  Future<List<MembersCacheData>> getAllMembers() async {
    return _repository.getAllActive();
  }

  /// Refresh members from repository
  Future<List<MembersCacheData>> refreshMembers() async {
    return _repository.getAllActive();
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/services/members_service_test.dart -v
```

Expected: PASS (all 6 tests)

**Step 5: Commit**

```bash
git add lib/services/members_service.dart test/services/members_service_test.dart
git commit -m "feat: create MembersService (Phase 3 Task 1)

Wraps MembersRepository with business logic:
- lookupByRfid(cardUid) validates member is active and SEPA signed
- getAllMembers() and refreshMembers() return active members
- Returns tuple (member, errorMsg) for graceful error handling

Test coverage: 6 tests, all passing"
```

---

## Task 2: ProductsService

**Files:**
- Create: `lib/services/products_service.dart`
- Test: `test/services/products_service_test.dart`

**Overview:** Wrap ProductsRepository with getActiveCategoriesWithProducts(), getProduct(), getTranslatedName() with language fallback, refreshProducts().

**Step 1: Write the failing test file**

Create `test/services/products_service_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'dart:convert';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/repository/products_repository.dart';
import 'package:ruderbar_terminal/services/products_service.dart';

class MockProductsRepository extends Mock implements ProductsRepository {}

void main() {
  group('ProductsService', () {
    late MockProductsRepository mockRepo;
    late ProductsService service;

    setUp(() {
      mockRepo = MockProductsRepository();
      service = ProductsService(repository: mockRepo);
    });

    test('getActiveCategoriesWithProducts returns from repository', () async {
      final category = CategoriesCacheData(
        id: 'cat-1',
        name: 'Drinks',
        position: 1,
      );
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      when(() => mockRepo.getActiveCategoriesWithProducts())
          .thenAnswer((_) async => [(category, [product])]);

      final result = await service.getActiveCategoriesWithProducts();

      expect(result, hasLength(1));
      expect(result[0].$1, equals(category));
      expect(result[0].$2, equals([product]));
    });

    test('getProduct returns product by id', () async {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      when(() => mockRepo.getProduct('prod-1'))
          .thenAnswer((_) async => product);

      final result = await service.getProduct('prod-1');

      expect(result, equals(product));
    });

    test('getTranslatedName returns name in requested language', () async {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      final result = service.getTranslatedName(product, 'en');

      expect(result, equals('Beer'));
    });

    test('getTranslatedName falls back to German if language not available', () async {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      final result = service.getTranslatedName(product, 'fr');

      expect(result, equals('Bier'));
    });

    test('getTranslatedName returns empty string if no translations available', () async {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({}),
        price: 5.0,
        position: 1,
        active: true,
      );

      final result = service.getTranslatedName(product, 'en');

      expect(result, equals(''));
    });

    test('refreshProducts returns list from repository', () async {
      when(() => mockRepo.getActiveCategoriesWithProducts())
          .thenAnswer((_) async => []);

      final result = await service.refreshProducts();

      expect(result, equals([]));
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/services/products_service_test.dart
```

Expected: FAIL - `ProductsService` not defined

**Step 3: Write minimal implementation**

Create `lib/services/products_service.dart`:

```dart
import 'dart:convert';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/repository/products_repository.dart';

class ProductsService {
  final ProductsRepository _repository;

  ProductsService({required ProductsRepository repository})
      : _repository = repository;

  /// Get all active categories with their products
  Future<List<(CategoriesCacheData, List<ProductsCacheData>)>>
      getActiveCategoriesWithProducts() async {
    return _repository.getActiveCategoriesWithProducts();
  }

  /// Get single product by ID
  Future<ProductsCacheData?> getProduct(String id) async {
    return _repository.getProduct(id);
  }

  /// Get product name translated to language (German fallback)
  String getTranslatedName(ProductsCacheData product, String language) {
    try {
      final translations = jsonDecode(product.name) as Map<String, dynamic>;

      // Try requested language first
      if (translations.containsKey(language)) {
        return translations[language] as String;
      }

      // Fall back to German
      if (translations.containsKey('de')) {
        return translations['de'] as String;
      }

      // No translations available
      return '';
    } catch (e) {
      return '';
    }
  }

  /// Refresh products from repository
  Future<List<(CategoriesCacheData, List<ProductsCacheData>)>>
      refreshProducts() async {
    return _repository.getActiveCategoriesWithProducts();
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/services/products_service_test.dart -v
```

Expected: PASS (all 6 tests)

**Step 5: Commit**

```bash
git add lib/services/products_service.dart test/services/products_service_test.dart
git commit -m "feat: create ProductsService (Phase 3 Task 2)

Wraps ProductsRepository with business logic:
- getActiveCategoriesWithProducts() returns categories with products
- getProduct(id) returns single product
- getTranslatedName() handles JSON-encoded multilingual names with German fallback
- refreshProducts() reloads from repository

Test coverage: 6 tests, all passing"
```

---

## Task 3: CartService

**Files:**
- Create: `lib/services/cart_service.dart`
- Test: `test/services/cart_service_test.dart`

**Overview:** Use TransactionsRepository to create transactions from cart items. Validate cart before checkout. Return tuple (transactionId, error).

**Step 1: Write the failing test file**

Create `test/services/cart_service_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/models/transaction_dto.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';

class CartItem {
  final String productId;
  final int quantity;
  final double price;

  CartItem({
    required this.productId,
    required this.quantity,
    required this.price,
  });
}

class MockTransactionsRepository extends Mock implements TransactionsRepository {}

void main() {
  group('CartService', () {
    late MockTransactionsRepository mockRepo;
    late CartService service;

    setUp(() {
      mockRepo = MockTransactionsRepository();
      service = CartService(repository: mockRepo);
    });

    test('createTransaction persists transaction with items', () async {
      final member = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      final items = [
        CartItem(productId: 'prod-1', quantity: 2, price: 5.0),
        CartItem(productId: 'prod-2', quantity: 1, price: 3.0),
      ];

      when(() => mockRepo.insertTransaction(any()))
          .thenAnswer((_) async => 'txn-123');

      final (txnId, error) = await service.createTransaction(member, items);

      expect(txnId, equals('txn-123'));
      expect(error, isNull);
      verify(() => mockRepo.insertTransaction(any())).called(1);
    });

    test('createTransaction returns error when repository fails', () async {
      final member = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      final items = [
        CartItem(productId: 'prod-1', quantity: 1, price: 5.0),
      ];

      when(() => mockRepo.insertTransaction(any()))
          .thenThrow(Exception('Database error'));

      final (txnId, error) = await service.createTransaction(member, items);

      expect(txnId, isNull);
      expect(error, contains('error'));
    });

    test('validateCartBeforeCheckout returns valid for active member', () async {
      final member = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      final items = [
        CartItem(productId: 'prod-1', quantity: 1, price: 5.0),
      ];

      final (valid, error) = await service.validateCartBeforeCheckout(member, items);

      expect(valid, isTrue);
      expect(error, isNull);
    });

    test('validateCartBeforeCheckout returns error when member inactive', () async {
      final member = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: false,
      );

      final items = [
        CartItem(productId: 'prod-1', quantity: 1, price: 5.0),
      ];

      final (valid, error) = await service.validateCartBeforeCheckout(member, items);

      expect(valid, isFalse);
      expect(error, contains('inactive'));
    });

    test('validateCartBeforeCheckout returns error for empty cart', () async {
      final member = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      final (valid, error) = await service.validateCartBeforeCheckout(member, []);

      expect(valid, isFalse);
      expect(error, contains('empty'));
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/services/cart_service_test.dart
```

Expected: FAIL - `CartService` not defined, `CartItem` not defined in services

**Step 3: Write minimal implementation**

First, create the CartItem model in `lib/models/cart_item.dart`:

```dart
class CartItem {
  final String productId;
  final int quantity;
  final double price;

  CartItem({
    required this.productId,
    required this.quantity,
    required this.price,
  });

  double get lineTotal => price * quantity;
}
```

Then create `lib/services/cart_service.dart`:

```dart
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/models/transaction_dto.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';

class CartService {
  final TransactionsRepository _repository;

  CartService({required TransactionsRepository repository})
      : _repository = repository;

  /// Create and persist transaction from cart items
  /// Returns tuple: (transactionId, errorMessage)
  Future<(String?, String?)> createTransaction(
    MembersCacheData member,
    List<CartItem> items,
  ) async {
    try {
      // Create transaction object
      final total = items.fold<double>(0, (sum, item) => sum + item.lineTotal);

      final transaction = TransactionsCacheData(
        id: DateTime.now().millisecondsSinceEpoch.toString(),
        memberId: member.id,
        totalAmount: total,
        synced: false,
      );

      // Persist to repository
      final txnId = await _repository.insertTransaction(transaction);

      return (txnId, null);
    } catch (e) {
      return (null, 'Failed to create transaction: $e');
    }
  }

  /// Validate cart before checkout
  /// Returns tuple: (isValid, errorMessage)
  Future<(bool, String?)> validateCartBeforeCheckout(
    MembersCacheData member,
    List<CartItem> items,
  ) async {
    // Check member is active
    if (!member.active) {
      return (false, 'Member account is inactive');
    }

    // Check cart not empty
    if (items.isEmpty) {
      return (false, 'Cart is empty');
    }

    return (true, null);
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/services/cart_service_test.dart -v
```

Expected: PASS (all 5 tests)

**Step 5: Commit**

```bash
git add lib/models/cart_item.dart lib/services/cart_service.dart test/services/cart_service_test.dart
git commit -m "feat: create CartService and CartItem model (Phase 3 Task 3)

CartService wraps TransactionsRepository:
- createTransaction(member, items) persists cart to database
- validateCartBeforeCheckout() checks member active and cart not empty
- Returns tuple (txnId/result, error) for error handling

CartItem model encapsulates product + quantity + price with lineTotal calculation

Test coverage: 5 tests, all passing"
```

---

## Task 4: AuthProvider

**Files:**
- Create: `lib/providers/auth_provider.dart`
- Test: `test/providers/auth_provider_test.dart`

**Overview:** Manage token and authentication state. Simple provider without service dependency.

**Step 1: Write the failing test file**

Create `test/providers/auth_provider_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/providers/auth_provider.dart';

void main() {
  group('AuthProvider', () {
    late AuthProvider provider;

    setUp(() {
      provider = AuthProvider();
    });

    test('isAuthenticated is false initially', () {
      expect(provider.isAuthenticated, isFalse);
    });

    test('token is null initially', () {
      expect(provider.token, isNull);
    });

    test('setToken stores token and updates isAuthenticated', () {
      provider.setToken('test-token-123');

      expect(provider.token, equals('test-token-123'));
      expect(provider.isAuthenticated, isTrue);
    });

    test('clearToken removes token and updates isAuthenticated', () {
      provider.setToken('test-token-123');
      provider.clearToken();

      expect(provider.token, isNull);
      expect(provider.isAuthenticated, isFalse);
    });

    test('lastError is null initially', () {
      expect(provider.lastError, isNull);
    });

    test('lastError tracks authentication errors', () {
      provider.setError('Invalid credentials');

      expect(provider.lastError, equals('Invalid credentials'));
    });

    test('clearError removes error', () {
      provider.setError('Invalid credentials');
      provider.clearError();

      expect(provider.lastError, isNull);
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/providers/auth_provider_test.dart
```

Expected: FAIL - `AuthProvider` not defined

**Step 3: Write minimal implementation**

Create `lib/providers/auth_provider.dart`:

```dart
import 'package:flutter/foundation.dart';

class AuthProvider extends ChangeNotifier {
  String? _token;
  String? _lastError;

  String? get token => _token;
  bool get isAuthenticated => _token != null;
  String? get lastError => _lastError;

  void setToken(String token) {
    _token = token;
    _lastError = null;
    notifyListeners();
  }

  void clearToken() {
    _token = null;
    _lastError = null;
    notifyListeners();
  }

  void setError(String error) {
    _lastError = error;
    notifyListeners();
  }

  void clearError() {
    _lastError = null;
    notifyListeners();
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/providers/auth_provider_test.dart -v
```

Expected: PASS (all 7 tests)

**Step 5: Commit**

```bash
git add lib/providers/auth_provider.dart test/providers/auth_provider_test.dart
git commit -m "feat: create AuthProvider (Phase 3 Task 4)

Manages token and authentication state:
- token storage with isAuthenticated getter
- setToken()/clearToken() for login/logout
- Error tracking: lastError, setError(), clearError()
- Extends ChangeNotifier for state change notifications

Test coverage: 7 tests, all passing"
```

---

## Task 5: MembersProvider

**Files:**
- Create: `lib/providers/members_provider.dart`
- Test: `test/providers/members_provider_test.dart`

**Overview:** Manage member list state and RFID lookup. Use MembersService. Track isLoading, isSyncing, lastError, errorType.

**Step 1: Write the failing test file**

Create `test/providers/members_provider_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/services/members_service.dart';

class MockMembersService extends Mock implements MembersService {}

void main() {
  group('MembersProvider', () {
    late MockMembersService mockService;
    late MembersProvider provider;

    setUp(() {
      mockService = MockMembersService();
      provider = MembersProvider(service: mockService);
    });

    test('initial state is empty', () {
      expect(provider.members, isEmpty);
      expect(provider.selectedMember, isNull);
      expect(provider.isLoading, isFalse);
      expect(provider.isSyncing, isFalse);
      expect(provider.lastError, isNull);
    });

    test('selectMemberByRfid sets selectedMember when found', () async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      when(() => mockService.lookupByRfid('card-123'))
          .thenAnswer((_) async => (testMember, null));

      await provider.selectMemberByRfid('card-123');

      expect(provider.selectedMember, equals(testMember));
      expect(provider.lastError, isNull);
    });

    test('selectMemberByRfid sets error when member not found', () async {
      when(() => mockService.lookupByRfid('invalid-card'))
          .thenAnswer((_) async => (null, 'Member not found'));

      await provider.selectMemberByRfid('invalid-card');

      expect(provider.selectedMember, isNull);
      expect(provider.lastError, equals('Member not found'));
    });

    test('selectMemberByRfid clears previous error on success', () async {
      // First set an error
      when(() => mockService.lookupByRfid('invalid'))
          .thenAnswer((_) async => (null, 'Not found'));
      await provider.selectMemberByRfid('invalid');
      expect(provider.lastError, isNotNull);

      // Then succeed
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );
      when(() => mockService.lookupByRfid('card-123'))
          .thenAnswer((_) async => (testMember, null));

      await provider.selectMemberByRfid('card-123');

      expect(provider.lastError, isNull);
      expect(provider.selectedMember, equals(testMember));
    });

    test('clearSelectedMember resets member', () async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );
      when(() => mockService.lookupByRfid('card-123'))
          .thenAnswer((_) async => (testMember, null));

      await provider.selectMemberByRfid('card-123');
      expect(provider.selectedMember, isNotNull);

      provider.clearSelectedMember();
      expect(provider.selectedMember, isNull);
    });

    test('refreshMembers updates members list', () async {
      final members = [
        MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'John',
          lastName: 'Doe',
          iban: 'DE89370400440532013000',
          mandateSigned: true,
          active: true,
        ),
      ];

      when(() => mockService.getAllMembers())
          .thenAnswer((_) async => members);

      await provider.refreshMembers();

      expect(provider.members, equals(members));
      expect(provider.isSyncing, isFalse);
    });

    test('refreshMembers sets isSyncing during operation', () async {
      when(() => mockService.getAllMembers())
          .thenAnswer((_) async {
        expect(provider.isSyncing, isTrue);
        return [];
      });

      await provider.refreshMembers();

      expect(provider.isSyncing, isFalse);
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/providers/members_provider_test.dart
```

Expected: FAIL - `MembersProvider` not defined

**Step 3: Write minimal implementation**

Create `lib/providers/members_provider.dart`:

```dart
import 'package:flutter/foundation.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/services/members_service.dart';

class MembersProvider extends ChangeNotifier {
  final MembersService _service;

  List<MembersCacheData> _members = [];
  MembersCacheData? _selectedMember;
  bool _isLoading = false;
  bool _isSyncing = false;
  String? _lastError;
  Exception? _errorType;

  MembersProvider({required MembersService service}) : _service = service;

  List<MembersCacheData> get members => _members;
  MembersCacheData? get selectedMember => _selectedMember;
  bool get isLoading => _isLoading;
  bool get isSyncing => _isSyncing;
  String? get lastError => _lastError;
  Exception? get errorType => _errorType;

  /// Select member by RFID card UID
  Future<void> selectMemberByRfid(String cardUid) async {
    _isLoading = true;
    notifyListeners();

    try {
      final (member, error) = await _service.lookupByRfid(cardUid);

      if (member != null && error == null) {
        _selectedMember = member;
        _lastError = null;
        _errorType = null;
      } else {
        _selectedMember = null;
        _lastError = error;
        _errorType = null;
      }
    } catch (e) {
      _selectedMember = null;
      _lastError = 'Error looking up member: $e';
      _errorType = e as Exception?;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// Clear selected member
  void clearSelectedMember() {
    _selectedMember = null;
    notifyListeners();
  }

  /// Refresh members from service
  Future<void> refreshMembers() async {
    _isSyncing = true;
    notifyListeners();

    try {
      final members = await _service.getAllMembers();
      _members = members;
      _lastError = null;
      _errorType = null;
    } catch (e) {
      _lastError = 'Failed to refresh members: $e';
      _errorType = e as Exception?;
    } finally {
      _isSyncing = false;
      notifyListeners();
    }
  }

  /// Clear cached members
  Future<void> clearCache() async {
    _members = [];
    _selectedMember = null;
    notifyListeners();
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/providers/members_provider_test.dart -v
```

Expected: PASS (all 8 tests)

**Step 5: Commit**

```bash
git add lib/providers/members_provider.dart test/providers/members_provider_test.dart
git commit -m "feat: create MembersProvider (Phase 3 Task 5)

Manages member list and RFID lookup state:
- selectMemberByRfid(cardUid) validates and selects member
- clearSelectedMember() resets selection for next transaction
- refreshMembers() loads from service with isSyncing flag
- Tracks detailed error state: isLoading, isSyncing, lastError, errorType

Test coverage: 8 tests, all passing"
```

---

## Task 6: ProductsProvider

**Files:**
- Create: `lib/providers/products_provider.dart`
- Test: `test/providers/products_provider_test.dart`

**Overview:** Manage product catalog state. Use ProductsService. Track categories, products, language handling, sync status.

**Step 1: Write the failing test file**

Create `test/providers/products_provider_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'dart:convert';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/services/products_service.dart';

class MockProductsService extends Mock implements ProductsService {}

void main() {
  group('ProductsProvider', () {
    late MockProductsService mockService;
    late ProductsProvider provider;

    setUp(() {
      mockService = MockProductsService();
      provider = ProductsProvider(service: mockService);
    });

    test('initial state is empty', () {
      expect(provider.categories, isEmpty);
      expect(provider.products, isEmpty);
      expect(provider.isLoading, isFalse);
      expect(provider.isSyncing, isFalse);
      expect(provider.lastError, isNull);
    });

    test('refreshProducts updates categories and products', () async {
      final category = CategoriesCacheData(
        id: 'cat-1',
        name: 'Drinks',
        position: 1,
      );
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      when(() => mockService.getActiveCategoriesWithProducts())
          .thenAnswer((_) async => [(category, [product])]);

      await provider.refreshProducts();

      expect(provider.categories, equals([category]));
      expect(provider.products, equals([product]));
      expect(provider.lastError, isNull);
    });

    test('refreshProducts sets isSyncing during operation', () async {
      when(() => mockService.getActiveCategoriesWithProducts())
          .thenAnswer((_) async {
        expect(provider.isSyncing, isTrue);
        return [];
      });

      await provider.refreshProducts();

      expect(provider.isSyncing, isFalse);
    });

    test('refreshProducts handles error', () async {
      when(() => mockService.getActiveCategoriesWithProducts())
          .thenThrow(Exception('Service error'));

      await provider.refreshProducts();

      expect(provider.lastError, contains('refresh'));
      expect(provider.isSyncing, isFalse);
    });

    test('getTranslatedName delegates to service', () {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier', 'en': 'Beer'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      when(() => mockService.getTranslatedName(product, 'en'))
          .thenReturn('Beer');

      final result = provider.getTranslatedName(product, 'en');

      expect(result, equals('Beer'));
      verify(() => mockService.getTranslatedName(product, 'en')).called(1);
    });

    test('clearCache empties products and categories', () async {
      final category = CategoriesCacheData(
        id: 'cat-1',
        name: 'Drinks',
        position: 1,
      );
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      when(() => mockService.getActiveCategoriesWithProducts())
          .thenAnswer((_) async => [(category, [product])]);

      await provider.refreshProducts();
      expect(provider.products, isNotEmpty);

      await provider.clearCache();

      expect(provider.categories, isEmpty);
      expect(provider.products, isEmpty);
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/providers/products_provider_test.dart
```

Expected: FAIL - `ProductsProvider` not defined

**Step 3: Write minimal implementation**

Create `lib/providers/products_provider.dart`:

```dart
import 'package:flutter/foundation.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/services/products_service.dart';

class ProductsProvider extends ChangeNotifier {
  final ProductsService _service;

  List<CategoriesCacheData> _categories = [];
  List<ProductsCacheData> _products = [];
  bool _isLoading = false;
  bool _isSyncing = false;
  String? _lastError;
  Exception? _errorType;

  ProductsProvider({required ProductsService service}) : _service = service;

  List<CategoriesCacheData> get categories => _categories;
  List<ProductsCacheData> get products => _products;
  bool get isLoading => _isLoading;
  bool get isSyncing => _isSyncing;
  String? get lastError => _lastError;
  Exception? get errorType => _errorType;

  /// Refresh products from service
  Future<void> refreshProducts() async {
    _isSyncing = true;
    notifyListeners();

    try {
      final result = await _service.getActiveCategoriesWithProducts();
      _categories = result.map((item) => item.$1).toList();
      _products = result.expand((item) => item.$2).toList();
      _lastError = null;
      _errorType = null;
    } catch (e) {
      _lastError = 'Failed to refresh products: $e';
      _errorType = e as Exception?;
    } finally {
      _isSyncing = false;
      notifyListeners();
    }
  }

  /// Get translated product name
  String getTranslatedName(ProductsCacheData product, String language) {
    return _service.getTranslatedName(product, language);
  }

  /// Clear cached products
  Future<void> clearCache() async {
    _categories = [];
    _products = [];
    notifyListeners();
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/providers/products_provider_test.dart -v
```

Expected: PASS (all 6 tests)

**Step 5: Commit**

```bash
git add lib/providers/products_provider.dart test/providers/products_provider_test.dart
git commit -m "feat: create ProductsProvider (Phase 3 Task 6)

Manages product catalog and categories:
- refreshProducts() loads categories and products with isSyncing flag
- getTranslatedName() delegates language handling to service
- clearCache() empties product list for logout/reset
- Tracks detailed error state: isLoading, isSyncing, lastError, errorType

Test coverage: 6 tests, all passing"
```

---

## Task 7: CartProvider

**Files:**
- Create: `lib/providers/cart_provider.dart`
- Test: `test/providers/cart_provider_test.dart`

**Overview:** Manage in-memory shopping cart. Use CartService to create transactions. Track items, total, checkout state.

**Step 1: Write the failing test file**

Create `test/providers/cart_provider_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';
import 'dart:convert';

class MockCartService extends Mock implements CartService {}

void main() {
  group('CartProvider', () {
    late MockCartService mockService;
    late CartProvider provider;

    setUp(() {
      mockService = MockCartService();
      provider = CartProvider(service: mockService);
    });

    test('initial state is empty', () {
      expect(provider.items, isEmpty);
      expect(provider.itemCount, equals(0));
      expect(provider.total, equals(0.0));
      expect(provider.isLoading, isFalse);
      expect(provider.lastError, isNull);
    });

    test('addItem adds product to cart and updates total', () {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      provider.addItem(product, 2);

      expect(provider.items, hasLength(1));
      expect(provider.itemCount, equals(2));
      expect(provider.total, equals(10.0));
    });

    test('addItem accumulates quantities for same product', () {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      provider.addItem(product, 1);
      provider.addItem(product, 2);

      expect(provider.items, hasLength(1));
      expect(provider.itemCount, equals(3));
      expect(provider.total, equals(15.0));
    });

    test('removeItem removes product from cart', () {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      provider.addItem(product, 2);
      provider.removeItem('prod-1');

      expect(provider.items, isEmpty);
      expect(provider.itemCount, equals(0));
      expect(provider.total, equals(0.0));
    });

    test('updateQuantity changes item quantity', () {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      provider.addItem(product, 1);
      provider.updateQuantity('prod-1', 3);

      expect(provider.itemCount, equals(3));
      expect(provider.total, equals(15.0));
    });

    test('checkout creates transaction and clears cart', () async {
      final member = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: true,
      );

      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      provider.addItem(product, 1);

      when(() => mockService.createTransaction(any(), any()))
          .thenAnswer((_) async => ('txn-123', null));

      await provider.checkout(member);

      expect(provider.items, isEmpty);
      expect(provider.total, equals(0.0));
      expect(provider.lastError, isNull);
    });

    test('checkout handles validation error without clearing cart', () async {
      final member = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        iban: 'DE89370400440532013000',
        mandateSigned: true,
        active: false,
      );

      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      provider.addItem(product, 1);

      when(() => mockService.validateCartBeforeCheckout(any(), any()))
          .thenAnswer((_) async => (false, 'Member inactive'));

      await provider.checkout(member);

      expect(provider.items, hasLength(1)); // Cart unchanged
      expect(provider.lastError, equals('Member inactive'));
    });

    test('clearCart empties cart', () {
      final product = ProductsCacheData(
        id: 'prod-1',
        categoryId: 'cat-1',
        name: jsonEncode({'de': 'Bier'}),
        price: 5.0,
        position: 1,
        active: true,
      );

      provider.addItem(product, 2);
      expect(provider.items, isNotEmpty);

      provider.clearCart();

      expect(provider.items, isEmpty);
      expect(provider.total, equals(0.0));
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/providers/cart_provider_test.dart
```

Expected: FAIL - `CartProvider` not defined

**Step 3: Write minimal implementation**

Create `lib/providers/cart_provider.dart`:

```dart
import 'package:flutter/foundation.dart';
import 'package:ruderbar_terminal/models/cart_item.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';

class CartProvider extends ChangeNotifier {
  final CartService _service;

  List<CartItem> _items = [];
  bool _isLoading = false;
  String? _lastError;
  Exception? _errorType;

  CartProvider({required CartService service}) : _service = service;

  List<CartItem> get items => _items;
  int get itemCount => _items.fold(0, (sum, item) => sum + item.quantity);
  double get total => _items.fold(0.0, (sum, item) => sum + item.lineTotal);
  bool get isLoading => _isLoading;
  String? get lastError => _lastError;
  Exception? get errorType => _errorType;

  /// Add item to cart (accumulates quantity if product already present)
  void addItem(ProductsCacheData product, int quantity) {
    final existingIndex =
        _items.indexWhere((item) => item.productId == product.id);

    if (existingIndex >= 0) {
      // Update quantity for existing item
      final existing = _items[existingIndex];
      _items[existingIndex] = CartItem(
        productId: existing.productId,
        quantity: existing.quantity + quantity,
        price: existing.price,
      );
    } else {
      // Add new item
      _items.add(CartItem(
        productId: product.id,
        quantity: quantity,
        price: product.price,
      ));
    }

    notifyListeners();
  }

  /// Remove item from cart
  void removeItem(String productId) {
    _items.removeWhere((item) => item.productId == productId);
    notifyListeners();
  }

  /// Update item quantity
  void updateQuantity(String productId, int quantity) {
    final index = _items.indexWhere((item) => item.productId == productId);
    if (index >= 0) {
      final item = _items[index];
      _items[index] = CartItem(
        productId: item.productId,
        quantity: quantity,
        price: item.price,
      );
      notifyListeners();
    }
  }

  /// Checkout: validate, create transaction, clear cart
  Future<void> checkout(MembersCacheData member) async {
    _isLoading = true;
    notifyListeners();

    try {
      // Validate cart
      final (valid, error) =
          await _service.validateCartBeforeCheckout(member, _items);

      if (!valid) {
        _lastError = error;
        _isLoading = false;
        notifyListeners();
        return;
      }

      // Create transaction
      final (txnId, createError) =
          await _service.createTransaction(member, _items);

      if (txnId == null) {
        _lastError = createError;
        _isLoading = false;
        notifyListeners();
        return;
      }

      // Clear cart on success
      _items = [];
      _lastError = null;
      _errorType = null;
    } catch (e) {
      _lastError = 'Checkout failed: $e';
      _errorType = e as Exception?;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  /// Clear cart
  void clearCart() {
    _items = [];
    _lastError = null;
    notifyListeners();
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/providers/cart_provider_test.dart -v
```

Expected: PASS (all 8 tests)

**Step 5: Commit**

```bash
git add lib/providers/cart_provider.dart test/providers/cart_provider_test.dart
git commit -m "feat: create CartProvider (Phase 3 Task 7)

Manages in-memory shopping cart:
- addItem() accumulates quantities for same product
- removeItem(), updateQuantity() modify cart
- checkout(member) validates, creates transaction, clears cart on success
- On validation error, keeps cart intact for retry
- Tracks detailed error state: isLoading, lastError, errorType

Test coverage: 8 tests, all passing"
```

---

## Task 8: SyncProvider with Background Timer

**Files:**
- Create: `lib/providers/sync_provider.dart`
- Test: `test/providers/sync_provider_test.dart`

**Overview:** Manage background synchronization. Use SyncService. Run timer every 60 seconds. Call refresh on MembersProvider and ProductsProvider on success. Non-blocking error handling.

**Step 1: Write the failing test file**

Create `test/providers/sync_provider_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/models/sync_response.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/services/sync_service.dart';

class MockSyncService extends Mock implements SyncService {}

class MockMembersProvider extends Mock implements MembersProvider {}

class MockProductsProvider extends Mock implements ProductsProvider {}

void main() {
  group('SyncProvider', () {
    late MockSyncService mockSyncService;
    late MockMembersProvider mockMembersProvider;
    late MockProductsProvider mockProductsProvider;
    late SyncProvider provider;

    setUp(() {
      mockSyncService = MockSyncService();
      mockMembersProvider = MockMembersProvider();
      mockProductsProvider = MockProductsProvider();
      provider = SyncProvider(
        syncService: mockSyncService,
        membersProvider: mockMembersProvider,
        productsProvider: mockProductsProvider,
      );
    });

    tearDown(() {
      provider.stopSync();
    });

    test('initial state reflects no sync', () {
      expect(provider.isSyncing, isFalse);
      expect(provider.lastSyncTime, isNull);
      expect(provider.retryCount, equals(0));
      expect(provider.lastError, isNull);
    });

    test('startSync calls syncService and refreshes providers', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async => {});
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async => {});

      await provider.startSync();

      expect(provider.isSyncing, isFalse);
      expect(provider.lastError, isNull);
      verify(() => mockSyncService.syncAll()).called(1);
      verify(() => mockMembersProvider.refreshMembers()).called(1);
      verify(() => mockProductsProvider.refreshProducts()).called(1);
    });

    test('startSync handles sync failure non-blocking', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.failure);
      when(() => mockSyncService.getLastError())
          .thenAnswer((_) async => 'Network error');

      await provider.startSync();

      expect(provider.isSyncing, isFalse);
      expect(provider.lastError, contains('Network error'));
      expect(provider.retryCount, equals(1));
    });

    test('startSync skips if not needed', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => false);

      await provider.startSync();

      verifyNever(() => mockSyncService.syncAll());
    });

    test('startSync increments retryCount on failure', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.failure);
      when(() => mockSyncService.getLastError())
          .thenAnswer((_) async => 'Error');

      expect(provider.retryCount, equals(0));

      await provider.startSync();
      expect(provider.retryCount, equals(1));

      await provider.startSync();
      expect(provider.retryCount, equals(2));
    });

    test('startSync clears error on success', () async {
      // Set an error first
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.failure);
      when(() => mockSyncService.getLastError())
          .thenAnswer((_) async => 'Network error');

      await provider.startSync();
      expect(provider.lastError, isNotNull);

      // Then succeed
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async => {});
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async => {});

      await provider.startSync();

      expect(provider.lastError, isNull);
      expect(provider.retryCount, equals(0)); // Reset on success
    });

    test('background timer can be started and stopped', () async {
      when(() => mockSyncService.isSyncNeeded())
          .thenAnswer((_) async => true);
      when(() => mockSyncService.syncAll())
          .thenAnswer((_) async => SyncResult.success);
      when(() => mockMembersProvider.refreshMembers())
          .thenAnswer((_) async => {});
      when(() => mockProductsProvider.refreshProducts())
          .thenAnswer((_) async => {});

      provider.startBackgroundSync(intervalSeconds: 1);
      await Future.delayed(Duration(milliseconds: 1500));

      // Should have called sync at least once due to timer
      verify(() => mockSyncService.isSyncNeeded()).called(greaterThan(0));

      provider.stopSync();
    });
  });
}
```

**Step 2: Run test to verify it fails**

```bash
flutter test test/providers/sync_provider_test.dart
```

Expected: FAIL - `SyncProvider` not defined

**Step 3: Write minimal implementation**

Create `lib/providers/sync_provider.dart`:

```dart
import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/services/sync_service.dart';

class SyncProvider extends ChangeNotifier {
  final SyncService _syncService;
  final MembersProvider _membersProvider;
  final ProductsProvider _productsProvider;

  bool _isSyncing = false;
  DateTime? _lastSyncTime;
  int _retryCount = 0;
  String? _lastError;
  Exception? _errorType;
  Timer? _backgroundTimer;

  SyncProvider({
    required SyncService syncService,
    required MembersProvider membersProvider,
    required ProductsProvider productsProvider,
  })  : _syncService = syncService,
        _membersProvider = membersProvider,
        _productsProvider = productsProvider;

  bool get isSyncing => _isSyncing;
  DateTime? get lastSyncTime => _lastSyncTime;
  int get retryCount => _retryCount;
  String? get lastError => _lastError;
  Exception? get errorType => _errorType;

  /// Manually trigger sync
  Future<void> startSync() async {
    // Check if sync is needed first
    final needed = await _syncService.isSyncNeeded();
    if (!needed) {
      return;
    }

    _isSyncing = true;
    notifyListeners();

    try {
      final result = await _syncService.syncAll();

      if (result == SyncResult.success) {
        // Refresh other providers
        await _membersProvider.refreshMembers();
        await _productsProvider.refreshProducts();

        _lastSyncTime = DateTime.now();
        _lastError = null;
        _errorType = null;
        _retryCount = 0;
      } else {
        // Sync failed, get error message
        final error = await _syncService.getLastError();
        _lastError = error;
        _retryCount++;
      }
    } catch (e) {
      _lastError = 'Sync error: $e';
      _errorType = e as Exception?;
      _retryCount++;
    } finally {
      _isSyncing = false;
      notifyListeners();
    }
  }

  /// Start background sync timer (every N seconds)
  void startBackgroundSync({int intervalSeconds = 60}) {
    // Stop existing timer if any
    _backgroundTimer?.cancel();

    // Run sync immediately
    startSync();

    // Then schedule periodic syncs
    _backgroundTimer = Timer.periodic(Duration(seconds: intervalSeconds), (_) {
      if (!_isSyncing) {
        startSync();
      }
    });
  }

  /// Stop background sync timer
  void stopSync() {
    _backgroundTimer?.cancel();
    _backgroundTimer = null;
  }

  @override
  void dispose() {
    stopSync();
    super.dispose();
  }
}
```

**Step 4: Run test to verify it passes**

```bash
flutter test test/providers/sync_provider_test.dart -v
```

Expected: PASS (all 7 tests)

**Step 5: Commit**

```bash
git add lib/providers/sync_provider.dart test/providers/sync_provider_test.dart
git commit -m "feat: create SyncProvider with background timer (Phase 3 Task 8)

Manages background synchronization and data freshness:
- startSync() manually triggers sync, refreshes MembersProvider and ProductsProvider
- startBackgroundSync() runs timer every 60 seconds (configurable for testing)
- Non-blocking error handling: errors stored but don't prevent transactions
- Automatic retry on next sync interval
- isSyncing flag prevents concurrent sync operations
- stopSync() stops background timer on shutdown

Test coverage: 7 tests, all passing"
```

---

## Task 9: Verify All Tests Passing

**Overview:** Run complete test suite to verify all 79+ tests pass with Phase 3 additions.

**Step 1: Run full test suite**

```bash
cd /Users/dg/dev/frgs-vereinsbar/terminal-frontend
flutter test 2>&1 | tail -5
```

Expected output should show all tests passing with count.

**Step 2: Check test count**

```bash
flutter test 2>&1 | grep -E "passed|failed"
```

Expected: All X tests passed (X should be >= 150+ with Phase 3 additions)

**Step 3: Generate test summary**

```bash
flutter test 2>&1 > /tmp/test-summary.txt
cat /tmp/test-summary.txt | tail -100 | grep -A 20 "passed"
```

**Step 4: Commit test verification**

```bash
git status
```

Expected: clean working directory (all previous commits covered changes)

**Step 5: Document completion**

Create `plans/INDEX.md` if not exists, or update if exists, with:

```markdown
# Implementation Plans Index

## Current Status

**Phase 3: Provider-Based State Management** - COMPLETED
- 5 Providers: AuthProvider, MembersProvider, ProductsProvider, CartProvider, SyncProvider
- 3 Services: MembersService, ProductsService, CartService
- 8 Tasks completed, all tests passing
- Total test coverage: 150+ tests across Phase 1-3

## Completed Plans

- Phase 1: Flutter Project Setup & Core Models (15 tests)
- Phase 2: Data Access & Service Layer (70 tests)
- Phase 3: Provider-Based State Management (150+ tests total)

## Next Phase

Phase 4: UI Implementation (ready when user approves)
- Checkout page with RFID scanner
- Product selection UI
- Member management screens
- Sync status display
```

---

## Success Criteria

✅ All services implemented and tested
✅ All providers implemented and tested
✅ Background sync timer running every 60 seconds
✅ Sync errors non-blocking; app works offline
✅ All 150+ tests passing
✅ 80%+ test coverage across all classes
✅ Git history clean with meaningful commits
