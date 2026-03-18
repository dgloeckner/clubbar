# Dart OAS Client Generation Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all hand-written Dart model classes and HTTP calls (except `DispenserClient`) with types and a Chopper client generated from `api/terminal.yaml`, so that OAS spec changes automatically propagate to the Dart codebase via `dart run build_runner build`.

**Architecture:** `swagger_dart_code_generator` (a build_runner plugin) reads `api/terminal.yaml` and generates typed Dart model classes and a Chopper-based API client into `lib/generated/`. A rewritten `NetworkService` wraps the generated client, preserving the existing public API for the rest of the app (304 handling, bearer token injection via `TokenInterceptor`, delta sync parameters, `NetworkException` translation). All hand-written DTO files are deleted after all consumers are migrated.

**Tech Stack:** Flutter/Dart, `swagger_dart_code_generator` (latest stable), `chopper ^8.0.0`, `json_annotation ^4.9.0`, `json_serializable ^6.8.0` (dev), `build_runner ^2.10.5` (already present)

---

## Important: Generated Type Names

`swagger_dart_code_generator` generates Dart class names directly from the OAS `#/components/schemas` names. From `api/terminal.yaml`, the relevant schemas are:

| OAS Schema | Generated Dart class | Replaces |
|---|---|---|
| `Member` | `Member` | `MemberDTO` |
| `Category` | `Category` | `CategoryDTO` |
| `Product` | `Product` | `ProductDTO` |
| `MemberDeltaResponse` | `MemberDeltaResponse` | `MembersSyncResponse` |
| `CategoryDeltaResponse` | `CategoryDeltaResponse` | `CategoriesSyncResponse` |
| `ProductDeltaResponse` | `ProductDeltaResponse` | `ProductsSyncResponse` |
| `TransactionBatchRequest` | `TransactionBatchRequest` | hand-written map |
| `TransactionBatchResponse` | `TransactionBatchResponse` | `TransactionSyncResponse` |
| `TransactionHistoryResponse` | `TransactionHistoryResponse` | inline parsing |

Field names are camelCase from snake_case: `card_uid` → `cardUid`, `price_cents` → `priceCents`, `is_active` → `isActive`, `deleted_at` → `deletedAt`, `has_more` → `hasMore`, etc.

**Verify generated names after Task 3** — if the generator uses different conventions, adjust subsequent tasks accordingly.

> **⚠️ `is_active` type change warning:** The OAS spec declares `is_active` as `boolean`. The current hand-written `ProductDTO.fromJson` reads `is_active` as an integer from the database layer. The generated `Product.fromJson` will expect a JSON boolean (`true`/`false`). **Before running any tests in Task 10+**, audit all JSON test fixtures in `test/` and `integration_test/` for `is_active: 0` / `is_active: 1` and change them to `is_active: false` / `is_active: true`. The backend API returns booleans so runtime behavior is correct; only test fixtures may need updating.

---

## File Map

**New files:**
- `terminal-frontend/build.yaml` — codegen config pointing at `api/terminal.yaml`
- `terminal-frontend/lib/services/token_interceptor.dart` — mutable Chopper interceptor for bearer auth
- `terminal-frontend/lib/generated/` — gitignored, regenerated on build

**Modified files:**
- `terminal-frontend/pubspec.yaml` — add dependencies
- `terminal-frontend/.gitignore` — ignore `lib/generated/`
- `terminal-frontend/lib/services/network_service.dart` — full rewrite, wraps generated client
- `terminal-frontend/lib/services/transaction_history_service.dart` — constructor + HTTP call migration
- `terminal-frontend/lib/repository/members_repository.dart` — update `MemberDTO` → `Member`
- `terminal-frontend/lib/repository/products_repository.dart` — update `ProductDTO`/`CategoryDTO` → `Product`/`Category`
- `terminal-frontend/lib/providers/rfid_provider.dart` — update `MemberDTO` → `Member`
- `terminal-frontend/lib/services/mock_rfid_service.dart` — update `MemberDTO` → `Member`
- `terminal-frontend/lib/main.dart` — update DTO constructors to generated types
- `terminal-frontend/lib/widgets/member_details_modal.dart` — pass `NetworkService` not `baseUrl`/`authToken`
- `terminal-frontend/test/models_test.dart` — rewrite for generated types
- `terminal-frontend/test/models/transaction_sync_response_test.dart` — update import
- `terminal-frontend/test/network_service_test.dart` — rewrite: mocks old `http.Client`, must be updated to mock the Chopper client
- `terminal-frontend/test/services/network_service_test.dart` — rewrite: same as above (both files test `NetworkService`)
- `terminal-frontend/test/repository_test.dart` — update imports
- `terminal-frontend/test/sync_service_test.dart` — update imports
- `terminal-frontend/test/services/members_service_test.dart` — update import
- `terminal-frontend/integration_test/test_helpers.dart` — **rewrite** `FakeNetworkService`: it currently `extends NetworkService` and overrides generic HTTP methods (`get`, `patch`) that will not exist after the rewrite; must be changed to override the domain methods instead
- `terminal-frontend/integration_test/checkout_flow_test.dart` — update DTO imports
- `terminal-frontend/integration_test/walkthrough_test.dart` — update imports

**Deleted files:**
- `terminal-frontend/lib/models/member_dto.dart`
- `terminal-frontend/lib/models/product_dto.dart`
- `terminal-frontend/lib/models/category_dto.dart`
- `terminal-frontend/lib/models/sync_response.dart`
- `terminal-frontend/lib/models/transaction_sync_response.dart`

**NOT modified (out of scope):**
- `terminal-frontend/lib/services/dispenser_client.dart` — uses `package:http` for ESP8266 hardware; no OAS spec
- `terminal-frontend/lib/models/transaction_list_item.dart` — local UI projection, not in spec
- `terminal-frontend/lib/models/shopping_cart.dart` / `cart_item.dart` — local UI models

---

## Chunk 1: Codegen Setup

### Task 1: Add dependencies to `pubspec.yaml`

**Files:**
- Modify: `terminal-frontend/pubspec.yaml`

- [ ] **Step 1: Add runtime dependencies**

In `terminal-frontend/pubspec.yaml`, add to `dependencies:`:

```yaml
  chopper: ^8.0.0
  json_annotation: ^4.9.0
```

- [ ] **Step 2: Add dev dependencies**

In `terminal-frontend/pubspec.yaml`, add to `dev_dependencies:`:

```yaml
  swagger_dart_code_generator: any  # pin to latest stable after resolving
  json_serializable: ^6.8.0
```

Use `any` for `swagger_dart_code_generator` initially — after running `dart pub get`, pin it to the resolved version for reproducibility.

Note: `http: ^1.6.0` must **remain** in dependencies — `DispenserClient` uses it for hardware communication and is out of scope.

- [ ] **Step 3: Resolve dependencies**

```bash
cd terminal-frontend && flutter pub get
```

Expected: Clean resolution, no conflicts with `build_runner: ^2.10.5` or `drift_dev: ^2.30.1`. If conflicts occur, adjust version constraints and re-run.

---

### Task 2: Create `build.yaml`

**Files:**
- Create: `terminal-frontend/build.yaml`

- [ ] **Step 1: Create the file**

```yaml
targets:
  $default:
    builders:
      swagger_dart_code_generator:
        options:
          input_folder: "../api/"
          with_base_url: false
          use_default_null_for_lists: true
          build_only_models: false
```

`input_folder: "../api/"` is relative to `terminal-frontend/` and resolves to the repo-root `api/` directory. `swagger_dart_code_generator` processes **all YAML files** in this directory, which means both `terminal.yaml` and `admin.yaml` will be processed. This is acceptable: the admin API client types will be generated but are never imported or used — they add no runtime overhead. Do not add `input_file` — it is not a valid configuration key for this generator.

- [ ] **Step 2: Add `lib/generated/` to `.gitignore`**

Add to `terminal-frontend/.gitignore`:

```
# OAS generated client
lib/generated/
```

---

### Task 3: Run codegen and inspect generated types

**Files:**
- Create: `terminal-frontend/lib/generated/` (auto-generated)

- [ ] **Step 1: Run build_runner**

```bash
cd terminal-frontend && dart run build_runner build --delete-conflicting-outputs
```

Expected: No errors. `lib/generated/` directory created with `.dart` files.

- [ ] **Step 2: Inspect generated files**

```bash
ls terminal-frontend/lib/generated/
```

Note the generated file names. Typical output includes a models file and a service/client file.

- [ ] **Step 3: Verify generated class names**

Open the generated file(s) and confirm the class names match the table in the "Important: Generated Type Names" section above. If names differ (e.g., generator uses `MemberItem` instead of `Member`), update the field in the table and all subsequent tasks to use the actual names.

- [ ] **Step 4: Note the generated Chopper service class name**

The service class (the Chopper client) will have a name based on the OAS title ("Club Bar - Terminal API"). Note the exact class name — you will need it in Task 5 when rewriting `NetworkService`.

- [ ] **Step 5: Commit setup**

Note: `lib/generated/` must NOT be committed — it is regenerated on every `dart run build_runner build`. Verify the `.gitignore` entry from Task 2 Step 2 is present before committing.

```bash
cd terminal-frontend && git add pubspec.yaml pubspec.lock build.yaml .gitignore
git commit -m "feat(terminal): add swagger_dart_code_generator and build.yaml for OAS codegen"
```

---

## Chunk 2: TokenInterceptor and NetworkService Rewrite

### Task 4: Create `TokenInterceptor`

**Files:**
- Create: `terminal-frontend/lib/services/token_interceptor.dart`

- [ ] **Step 1: Create the interceptor class**

```dart
import 'dart:async';
import 'package:chopper/chopper.dart';

/// Mutable Chopper request interceptor that injects a Bearer token.
///
/// The token is set at runtime via [token] after authentication.
/// Both [NetworkService.setAuthToken] and [NetworkService.clearAuthToken]
/// update this field directly — no client rebuild needed.
class TokenInterceptor implements RequestInterceptor {
  String? token;

  @override
  FutureOr<Request> onRequest(Request request) {
    if (token == null) return request;
    // Note: applyHeader() was removed in Chopper v8. Use copyWith() instead.
    return request.copyWith(
      headers: {...request.headers, 'Authorization': 'Bearer $token'},
    );
  }
}
```

- [ ] **Step 2: Verify analysis**

```bash
cd terminal-frontend && dart analyze lib/services/token_interceptor.dart
```

Expected: No errors.

---

### Task 5: Rewrite `NetworkService` to wrap the generated Chopper client

**Files:**
- Modify: `terminal-frontend/lib/services/network_service.dart`

The rewrite preserves the **exact same public method signatures** as the current `NetworkService` so all existing callers (`SyncService`, auth providers, etc.) require no changes. Internally it delegates to the generated Chopper client.

> **`ifNoneMatch` parameter note:** The current `NetworkService` sync methods accept an `ifNoneMatch` parameter (used for HTTP `If-None-Match` caching). `SyncService` never passes this parameter — it is dead code. The rewritten `NetworkService` drops this parameter entirely. No callers need updating.

> **`fetchBackendVersion` note:** The current `NetworkService` has a `fetchBackendVersion()` method that calls `GET /api/health` and parses a `version` field. The `api/terminal.yaml` health response schema does not include `version`. This method must remain **hand-written** in `NetworkService` — do not attempt to generate it. Keep calling `GET /api/health` via the generated client but parse `version` manually from the raw response map if needed, or keep using `package:http` just for this one method.

> **PHP array/object compatibility:** The current `TransactionSyncResponse.fromJson` has critical defensive logic: PHP `json_encode` returns `[]` (JSON array) for empty arrays and `{}` (JSON object) for populated maps. The `member_balances` field can arrive as either `Map<String,dynamic>` or `List` (empty). The generated `TransactionBatchResponse.fromJson` from `json_serializable` will NOT include this handling — it will throw if `member_balances` is `[]`. **Required:** In the `syncTransactions()` method of rewritten `NetworkService`, do NOT use `response.body` directly. Instead, decode the raw response JSON and pass it through a custom parser that preserves this logic. See `lib/models/transaction_sync_response.dart` for the exact defensive code to preserve.

- [ ] **Step 1: Rewrite `network_service.dart`**

```dart
import 'package:chopper/chopper.dart';
import 'package:logger/logger.dart';
import '../config/app_config.dart';
import '../models/transaction_sync_response.dart';   // TEMPORARY — delete in Task 13
import '../models/sync_response.dart';               // TEMPORARY — delete in Task 13
import 'token_interceptor.dart';
// TODO: replace these imports with generated paths after confirming class names
// import '../generated/<generated_file>.dart';

/// Result of a sync operation
// (keep existing NetworkException and HttpResponse types unchanged)

class NetworkService {
  late final ChopperClient _client;
  late final TokenInterceptor _tokenInterceptor;
  // TODO: replace `dynamic` with the generated service type after confirming name
  late final dynamic _api;
  String _baseUrl;
  final Logger _logger;

  NetworkService({required String baseUrl, Logger? logger})
      : _baseUrl = baseUrl,
        _logger = logger ?? Logger() {
    _tokenInterceptor = TokenInterceptor();
    _client = ChopperClient(
      baseUrl: Uri.parse(baseUrl),
      interceptors: [_tokenInterceptor, HttpLoggingInterceptor()],
    );
    // TODO: replace with generated service class
    // _api = _client.getService<GeneratedTerminalApiService>();
  }

  String get baseUrl => _baseUrl;

  void setAuthToken(String? token) {
    _tokenInterceptor.token = token;
  }

  String? getAuthToken() => _tokenInterceptor.token;

  void clearAuthToken() {
    _tokenInterceptor.token = null;
  }

  void setBaseUrl(String baseUrl) {
    _baseUrl = baseUrl;
    // Note: ChopperClient is immutable — if baseUrl changes at runtime,
    // rebuild the client here.
  }

  // TODO: implement syncMembers, syncCategories, syncProducts,
  // syncTransactions, getTransactionHistory, checkHealth
  // delegating to _api, handling 304 → null, mapping to NetworkException
}

class NetworkException implements Exception {
  final String message;
  final int? statusCode;

  NetworkException(this.message, {this.statusCode});

  @override
  String toString() =>
      'NetworkException: $message ${statusCode != null ? '(HTTP $statusCode)' : ''}';
}
```

**Important:** This is a scaffold. Complete the TODO items as follows:

1. Import the generated file (check generated path from Task 3 Step 2)
2. Replace `dynamic _api` with the generated service type
3. Implement each sync method by calling the generated API and translating the response:

For `syncMembers` as an example:

```dart
Future<MemberDeltaResponse?> syncMembers({int? since}) async {
  try {
    final response = await _api.syncMembers(since: since ?? 0);
    if (response.statusCode == 304) return null;
    if (!response.isSuccessful) {
      throw NetworkException(
        response.error?.toString() ?? 'HTTP ${response.statusCode}',
        statusCode: response.statusCode,
      );
    }
    return response.body;
  } catch (e) {
    if (e is NetworkException) rethrow;
    throw NetworkException('Sync members failed: $e');
  }
}
```

Implement equivalent methods for `syncCategories`, `syncProducts`, `syncTransactions`, and `getTransactionHistory`. The return types are the generated response classes.

- [ ] **Step 2: Run analysis**

```bash
cd terminal-frontend && dart analyze lib/services/network_service.dart
```

Fix any errors. Common issues: wrong import path for generated file, wrong generated service class name.

- [ ] **Step 3: Run existing network service tests**

There are two test files for `NetworkService` — update both:

```bash
cd terminal-frontend && flutter test test/network_service_test.dart
cd terminal-frontend && flutter test test/services/network_service_test.dart
```

Both tests currently mock the old `http.Client`. Update both to mock the Chopper client or use the `MockChopperClient` pattern. Keep the same test cases — just update the mock setup.

- [ ] **Step 4: Commit once tests pass**

```bash
git add terminal-frontend/lib/services/token_interceptor.dart \
        terminal-frontend/lib/services/network_service.dart \
        terminal-frontend/test/network_service_test.dart \
        terminal-frontend/test/services/network_service_test.dart
git commit -m "feat(terminal): rewrite NetworkService to wrap generated Chopper client"
```

---

## Chunk 3: Migrate Consumers (Repositories, Providers, Services, main)

At this point, `NetworkService`'s public methods return generated types (`MemberDeltaResponse`, `ProductDeltaResponse`, etc.) instead of the hand-written ones. All consumers of these types need updating.

**Pattern for each file:** Replace `import '../models/member_dto.dart'` (or similar) with the generated import path, then update all references to use the new class/field names (which should be identical — the generator uses the same field names as the spec, which match the hand-written DTOs).

### Task 6: Update `members_repository.dart`

**Files:**
- Modify: `terminal-frontend/lib/repository/members_repository.dart`

- [ ] **Step 1: Update import**

Replace:
```dart
import '../models/member_dto.dart';
```
With the generated import (e.g., `import '../generated/<file>.dart';`).

- [ ] **Step 2: Update method signatures**

Replace `MemberDTO` with `Member` (generated class) in:
- `upsertMembers(List<MemberDTO> members)` → `upsertMembers(List<Member> members)`
- Any `MemberDTO.fromJson(...)` calls → use generated `Member.fromJson(...)`

- [ ] **Step 3: Run analysis**

```bash
cd terminal-frontend && dart analyze lib/repository/members_repository.dart
```

Expected: No errors.

---

### Task 7: Update `products_repository.dart`

**Files:**
- Modify: `terminal-frontend/lib/repository/products_repository.dart`

- [ ] **Step 1: Update imports**

Replace imports of `category_dto.dart` and `product_dto.dart` with the generated import.

- [ ] **Step 2: Update method signatures**

- `upsertCategories(List<CategoryDTO>)` → `upsertCategories(List<Category>)`
- `upsertProducts(List<ProductDTO>)` → `upsertProducts(List<Product>)`
- Any `CategoryDTO.fromJson(...)` / `ProductDTO.fromJson(...)` → generated equivalents

- [ ] **Step 3: Run analysis**

```bash
cd terminal-frontend && dart analyze lib/repository/products_repository.dart
```

---

### Task 8: Update `rfid_provider.dart`

**Files:**
- Modify: `terminal-frontend/lib/providers/rfid_provider.dart`

- [ ] **Step 1: Update import and inline `MemberDTO` construction**

Find all `MemberDTO(...)` constructors and `MemberDTO.fromJson(...)` calls. Replace with `Member(...)` / `Member.fromJson(...)` using the generated class.

- [ ] **Step 2: Run analysis**

```bash
cd terminal-frontend && dart analyze lib/providers/rfid_provider.dart
```

---

### Task 9: Update `mock_rfid_service.dart`

**Files:**
- Modify: `terminal-frontend/lib/services/mock_rfid_service.dart`

This file is entirely built around `MemberDTO` (used for mock data in development/testing).

- [ ] **Step 1: Update all `MemberDTO` references to generated `Member`**

Replace all occurrences of `MemberDTO` with `Member` and update the import.

- [ ] **Step 2: Run analysis**

```bash
cd terminal-frontend && dart analyze lib/services/mock_rfid_service.dart
```

---

### Task 10: Update `main.dart`

**Files:**
- Modify: `terminal-frontend/lib/main.dart`

- [ ] **Step 1: Update DTO imports and seed data constructors**

Find all `MemberDTO(...)`, `ProductDTO(...)`, `CategoryDTO(...)` usages in `main.dart` and update imports + constructor calls to use generated `Member`, `Product`, `Category`.

- [ ] **Step 2: Run analysis**

```bash
cd terminal-frontend && dart analyze lib/main.dart
```

- [ ] **Step 3: Run all tests so far**

```bash
cd terminal-frontend && flutter test
```

Fix any remaining compile errors before proceeding.

- [ ] **Step 4: Commit**

```bash
git add terminal-frontend/lib/repository/ \
        terminal-frontend/lib/providers/rfid_provider.dart \
        terminal-frontend/lib/services/mock_rfid_service.dart \
        terminal-frontend/lib/main.dart
git commit -m "feat(terminal): migrate repositories, providers, and main to generated OAS types"
```

---

## Chunk 4: TransactionHistoryService and Callsite Migration

### Task 11: Update `TransactionHistoryService` constructor and HTTP call

**Files:**
- Modify: `terminal-frontend/lib/services/transaction_history_service.dart`
- Modify: `terminal-frontend/lib/widgets/member_details_modal.dart`

- [ ] **Step 1: Update `TransactionHistoryService` constructor**

Change the constructor from:
```dart
TransactionHistoryService({
  required String baseUrl,
  required String authToken,
  required ClubBarDatabase database,
  Logger? logger,
})
```

To:
```dart
TransactionHistoryService({
  required NetworkService networkService,
  required ClubBarDatabase database,
  Logger? logger,
})
```

Store `networkService` as `_networkService`. Remove `_baseUrl` and `_authToken` fields.

- [ ] **Step 2: Replace the direct `http.get` call with `NetworkService`**

In `_fetchRemoteTransactions()` (or equivalent), replace the raw `http.get` call with:

```dart
final response = await _networkService.getTransactionHistory(
  memberId,
  limit: limit,
);
```

Map the generated `TransactionHistoryResponse` fields to `TransactionListItem` (which is a local UI projection — it remains hand-written).

> **Method signature note:** Before writing the call above, check the generated Chopper client for the exact `getTransactionHistory` method signature. The generator may produce positional arguments (e.g., `getTransactionHistory(memberId, limit)`) rather than named arguments. Adjust the call to match whatever signature was generated. Confirm by inspecting `lib/generated/` after Task 3.

- [ ] **Step 3: Remove the `package:http` import from this file**

`transaction_history_service.dart` should no longer import `package:http/http.dart`.

- [ ] **Step 4: Run analysis**

```bash
cd terminal-frontend && dart analyze lib/services/transaction_history_service.dart
```

---

### Task 12: Update `member_details_modal.dart` callsite

**Files:**
- Modify: `terminal-frontend/lib/widgets/member_details_modal.dart`

- [ ] **Step 1: Find the `TransactionHistoryService` construction**

Locate where `TransactionHistoryService(baseUrl: ..., authToken: ..., database: ...)` is called.

- [ ] **Step 2: Replace with `NetworkService` injection**

Change to:
```dart
TransactionHistoryService(
  networkService: networkService, // pass the NetworkService instance
  database: database,
)
```

Ensure `networkService` is available in scope. If the widget receives it via constructor or Provider, access it accordingly.

- [ ] **Step 3: Run analysis**

```bash
cd terminal-frontend && dart analyze lib/widgets/member_details_modal.dart
```

- [ ] **Step 4: Run tests**

```bash
cd terminal-frontend && flutter test
```

- [ ] **Step 5: Commit**

```bash
git add terminal-frontend/lib/services/transaction_history_service.dart \
        terminal-frontend/lib/widgets/member_details_modal.dart
git commit -m "feat(terminal): migrate TransactionHistoryService to NetworkService; remove direct http calls"
```

---

## Chunk 5: Delete Old Models and Update Tests

### Task 13: Delete the five hand-written model files

**Files:**
- Delete: `terminal-frontend/lib/models/member_dto.dart`
- Delete: `terminal-frontend/lib/models/product_dto.dart`
- Delete: `terminal-frontend/lib/models/category_dto.dart`
- Delete: `terminal-frontend/lib/models/sync_response.dart`
- Delete: `terminal-frontend/lib/models/transaction_sync_response.dart`

- [ ] **Step 1: Remove the files**

```bash
cd terminal-frontend
rm lib/models/member_dto.dart \
   lib/models/product_dto.dart \
   lib/models/category_dto.dart \
   lib/models/sync_response.dart \
   lib/models/transaction_sync_response.dart
```

- [ ] **Step 2: Also remove the temporary imports added in Task 5 Step 1**

In `lib/services/network_service.dart`, remove any remaining `// TEMPORARY` import lines for the deleted model files.

- [ ] **Step 3: Run analysis to find any remaining references**

```bash
cd terminal-frontend && dart analyze
```

Fix any "can't find file" errors — these are remaining imports that still reference deleted files.

- [ ] **Step 4: Verify build_runner still works**

```bash
cd terminal-frontend && dart run build_runner build --delete-conflicting-outputs
```

Expected: Clean build, no errors.

---

### Task 14: Update test files

**Files:**
- Modify: `terminal-frontend/test/models_test.dart`
- Modify: `terminal-frontend/test/models/transaction_sync_response_test.dart`
- Modify: `terminal-frontend/test/repository_test.dart`
- Modify: `terminal-frontend/test/sync_service_test.dart`
- Modify: `terminal-frontend/test/services/members_service_test.dart`
- Modify: `terminal-frontend/integration_test/test_helpers.dart` — **rewrite FakeNetworkService**
- Modify: `terminal-frontend/integration_test/checkout_flow_test.dart` — update DTO imports
- Modify: `terminal-frontend/integration_test/walkthrough_test.dart` — update imports

- [ ] **Step 1: Rewrite or delete `test/models_test.dart`**

`test/models_test.dart` currently tests hand-written `fromJson` behavior. Two options:

**Option A (recommended):** Delete it. The generated `fromJson` comes from `json_serializable` which is a well-tested library — there is no value in testing it again.

**Option B:** Rewrite as a smoke test verifying the generated class can be instantiated from a JSON map. Use the example JSON from `api/terminal.yaml`:

```dart
test('Member.fromJson parses correctly', () {
  final json = {
    'id': '123e4567-e89b-12d3-a456-426614174000',
    'card_uid': '04:d2:3e:5a:10:80:80',
    'first_name': 'Max',
    'last_name': 'Mustermann',
    'preferred_language': 'de',
    'is_active': true,
    'is_sepa_valid': true,
    'deleted_at': null,
    'created_at': '2024-06-15T10:00:00Z',
    'updated_at': '2025-01-20T14:23:45Z',
  };
  final member = Member.fromJson(json);
  expect(member.id, '123e4567-e89b-12d3-a456-426614174000');
  expect(member.firstName, 'Max');
});
```

- [ ] **Step 2: Update remaining test files**

For `transaction_sync_response_test.dart`, `repository_test.dart`, `sync_service_test.dart`, `members_service_test.dart`, and `walkthrough_test.dart`:

1. Replace imports of deleted model files with the generated import
2. Replace references to old class names (`MemberDTO` → `Member`, `MembersSyncResponse` → `MemberDeltaResponse`, etc.)
3. Update field references if any changed (`members` and `cursor` are the same; `count` and `hasMore` are new fields — tests using the response type should add or ignore them)
4. For `is_active` values in test JSON fixtures: change `0`/`1` integers to `false`/`true` booleans (see warning in the "Generated Type Names" section above)

- [ ] **Step 2b: Rewrite `FakeNetworkService` in `integration_test/test_helpers.dart`**

`FakeNetworkService` currently `extends NetworkService` and overrides generic HTTP methods (`get`, `patch`). After the rewrite, `NetworkService` has no generic methods — it only has domain methods (`syncMembers`, `syncCategories`, etc.). The fake must be updated to override domain methods instead.

Replace the current `FakeNetworkService` implementation with one that overrides the actual domain methods:

```dart
class FakeNetworkService extends NetworkService {
  // Override domain methods to return fake data without HTTP calls
  @override
  Future<MemberDeltaResponse?> syncMembers({int? since}) async {
    // Return fake MemberDeltaResponse or null as needed by integration tests
    return MemberDeltaResponse(members: [], cursor: 0, count: 0, hasMore: false);
  }

  // Override syncCategories, syncProducts, syncTransactions similarly
  // Check what data each integration test expects and provide it here
}
```

Read `integration_test/test_helpers.dart` before rewriting — the fake data it currently provides must be preserved in the new domain-method overrides.

- [ ] **Step 2c: Update `integration_test/checkout_flow_test.dart`**

Open `integration_test/checkout_flow_test.dart` and update any imports of deleted model files (`member_dto.dart`, `product_dto.dart`, `category_dto.dart`, `sync_response.dart`) to the generated equivalents. Update class names to match generated types.

- [ ] **Step 3: Run all tests**

```bash
cd terminal-frontend && flutter test
```

Expected: All tests pass. Fix any failures before proceeding.

- [ ] **Step 4: Commit**

```bash
git add terminal-frontend/test/ \
        terminal-frontend/integration_test/ \
        terminal-frontend/lib/models/
git commit -m "feat(terminal): delete hand-written DTO files; update all tests to use generated OAS types"
```

---

## Chunk 6: Final Verification

### Task 15: Full test run and analysis

- [ ] **Step 1: Full analysis with zero errors**

```bash
cd terminal-frontend && dart analyze
```

Expected: `No issues found!` (or only pre-existing warnings unrelated to this change).

- [ ] **Step 2: Run all unit tests**

```bash
cd terminal-frontend && flutter test
```

Expected: All tests pass.

- [ ] **Step 3: Verify build_runner is clean**

```bash
cd terminal-frontend && dart run build_runner build --delete-conflicting-outputs
```

Expected: Clean build. No conflict warnings.

- [ ] **Step 4: Verify no hand-written DTO imports remain**

```bash
grep -r "member_dto\|product_dto\|category_dto\|sync_response\|transaction_sync_response" \
  terminal-frontend/lib terminal-frontend/test terminal-frontend/integration_test
```

Expected: No output. Any matches indicate a file that was missed.

- [ ] **Step 5: Verify `package:http` is only used in `dispenser_client.dart`**

```bash
grep -r "package:http" terminal-frontend/lib --include="*.dart"
```

Expected: Only `lib/services/dispenser_client.dart` (hardware dispenser, intentionally kept).

- [ ] **Step 6: Final commit**

```bash
git add -A
git commit -m "feat(terminal): complete OAS-driven Dart client migration — all types generated from terminal.yaml"
```
