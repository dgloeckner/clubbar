# OAS-Driven Contract Enforcement: Dart Client Generation & Backend Validation

**Date:** 2026-03-18
**Status:** Approved

---

## Problem

The terminal Flutter app (`terminal-frontend/`) has hand-written Dart model classes (`MemberDTO`, `ProductDTO`, `CategoryDTO`, `MembersSyncResponse`, etc.) and a hand-written `NetworkService` that manually crafts HTTP calls and parses JSON. These are maintained separately from `api/terminal.yaml`.

When the OAS spec changes, there is no mechanism to detect that the Dart client or backend implementation has drifted. Bugs are caught only at runtime.

---

## Goal

Make `api/terminal.yaml` the single enforced source of truth at both ends of the terminal API:

- **Dart client:** generated from spec; compile errors catch misuse after spec changes
- **PHP backend:** responses validated against spec during test runs; Playwright tests catch drift automatically

---

## Architecture

```
api/terminal.yaml
    │
    ├── dart run build_runner build
    │       └── generates: models + typed HTTP client (Dart)
    │               └── NetworkService wraps: 304 handling, bearer token, error translation
    │
    └── Playwright test runs (APP_ENV=test)
            └── league/openapi-psr7-validator PSR-15 middleware
                    └── validates every response against spec → test failure on drift
```

---

## Dart Client (terminal-frontend)

### Tooling

**Package:** `swagger_dart_code_generator` (pub.dev)
- Integrates with the existing `build_runner` pipeline (alongside `drift_dev`)
- Configured via `build.yaml` with `inputFile: ../api/terminal.yaml` (path relative to `terminal-frontend/`)
- No external tools or Java runtime required
- Run: `dart run build_runner build`

### Generated Artifacts

From `api/terminal.yaml`, the generator produces:
- **Model classes** with `fromJson`/`toJson` — replaces hand-written `MemberDTO`, `ProductDTO`, `CategoryDTO`, `MembersSyncResponse`, `ProductsSyncResponse`, `CategoriesSyncResponse`, `TransactionSyncResponse`
- **Typed API client** (Chopper-based) — one method per OAS path/operation

Generated files live in `lib/generated/` and are gitignored. They are regenerated on every `dart run build_runner build`.

#### Model Migration: Files to Remove vs. Retain

The following `lib/models/` files are **replaced by generated equivalents** and must be deleted:

| File | Replaced by |
|---|---|
| `lib/models/member_dto.dart` | Generated `MemberDto` |
| `lib/models/product_dto.dart` | Generated `ProductDto` |
| `lib/models/category_dto.dart` | Generated `CategoryDto` |
| `lib/models/sync_response.dart` | Generated response wrappers |
| `lib/models/transaction_sync_response.dart` | Generated `TransactionSyncResponse` |

The following files are **not API-generated** and must be retained:

| File | Reason |
|---|---|
| `lib/models/transaction_list_item.dart` | UI-layer projection merging local SQLite data with remote API data; not in the OAS spec |
| `lib/models/shopping_cart.dart` | Pure local UI model; no API representation |
| `lib/models/cart_item.dart` | Pure local UI model; no API representation |

### NetworkService Wrapper

The existing `NetworkService` is retained as the public API for the rest of the app. It becomes a thin wrapper over the generated Chopper client and continues to own:

| Responsibility | Detail |
|---|---|
| Bearer token injection | Via a `TokenInterceptor` (see below) |
| 304 Not Modified → `null` | Translated before returning to callers |
| `since` delta parameter | Constructed before passing to generated methods |
| `NetworkException` translation | Generated client errors mapped to existing type |

#### Bearer Token Interceptor

The existing `NetworkService.setAuthToken()` is called at runtime after the terminal authenticates — the token is not known at construction time. Chopper clients are immutable once built, so a mutable interceptor is required.

Introduce a `TokenInterceptor` class that implements `RequestInterceptor` and holds a mutable `token` field:

```
class TokenInterceptor implements RequestInterceptor {
  String? token;

  @override
  FutureOr<Request> onRequest(Request request) {
    if (token == null) return request;
    return applyHeader(request, 'Authorization', 'Bearer $token');
  }
}
```

`NetworkService` holds a reference to the same `TokenInterceptor` instance passed to the `ChopperClient`. Both `setAuthToken(token)` and `setAuthToken(null)` update `tokenInterceptor.token`; `clearAuthToken()` also sets it to `null`. All three paths converge on the same interceptor field — no separate clearing logic required.

#### TransactionHistoryService Migration

`lib/services/transaction_history_service.dart` makes direct `package:http` calls to `GET /api/terminal/transactions/{member_id}` — one of the six in-scope endpoints. It must be migrated.

Add a `getTransactionHistory(String memberId, {int limit = 50})` method to `NetworkService` that delegates to the generated Chopper client. `TransactionListItem` (a local UI projection) is not replaced by a generated type; `TransactionHistoryService` continues to map the generated response model to `TransactionListItem`.

`TransactionHistoryService` requires the following structural changes:

- **Constructor signature changes** from `{required String baseUrl, required String authToken, required ClubBarDatabase database}` to `{required NetworkService networkService, required ClubBarDatabase database}`. The service no longer holds a snapshot of the auth token — it calls `networkService.getTransactionHistory()` which uses the live `TokenInterceptor` token.
- **`lib/widgets/member_details_modal.dart`** constructs `TransactionHistoryService` and must be updated to pass `networkService` instead of extracting `baseUrl` and `authToken` at construction time.

The rest of the app (`SyncService`, repositories, providers, `DispenserClient`) is unchanged.

#### `package:http` Retention

`lib/services/dispenser_client.dart` uses `package:http` directly to communicate with the ESP8266 hardware dispenser. This is outside the scope of the OAS contract migration (no spec exists for the dispenser API). **`http: ^1.6.0` must be retained in `pubspec.yaml`.** Do not remove it.

### New Dependencies

| Package | Role | Approximate version |
|---|---|---|
| `swagger_dart_code_generator` | Dev: build_runner code generator | latest stable (verify on pub.dev) |
| `chopper` | Runtime: generated HTTP client base | `^8.0.0` |
| `json_annotation` | Runtime: generated model serialization | `^4.9.0` |
| `json_serializable` | Dev: build_runner JSON codegen | `^6.8.0` |

Versions must be verified for compatibility with existing `build_runner: ^2.10.5` and `drift_dev: ^2.30.1` before adding to `pubspec.yaml`. Run `dart pub get` and resolve any conflicts before proceeding.

---

## Backend Contract Validation (backend/)

### Tooling

**Package:** `league/openapi-psr7-validator`
- PSR-15 middleware; fits naturally into the Slim 4 middleware stack
- Validates both request and response against `api/terminal.yaml`
- No changes to controllers, services, or repositories required

### Integration

The middleware is registered in `backend/bootstrap.php`, after `$app->add($factory->getErrorHandler())` (inside the error handler so validation failures are formatted correctly) and before `$app->add($factory->getJsonBodyParser())`. Slim 4 executes middleware in FIFO order (first added = outermost), so this placement puts the validator inside the error handler and outside the JSON body parser:

```
// $app->addRoutingMiddleware() must remain the first call (innermost anchor)
$app->addRoutingMiddleware();

$app->add($factory->getErrorHandler());            // outermost — first added
if (getenv('APP_ENV') === 'test') {
    $app->add(new OpenApiValidatorMiddleware(...));  // inside error handler
}
$app->add($factory->getJsonBodyParser());
$app->add($factory->getCorsMiddleware());           // innermost of middleware stack
```

`APP_ENV=test` is set in the Docker Compose environment for test runs. Production omits this variable.

### Effect on Test Runs

Every HTTP response sent during Playwright test runs is validated against the spec. A schema violation returns a 5xx error with a descriptive message. The Playwright test fails with a clear signal that the backend response no longer matches the spec.

**No changes to Playwright tests are required.** Drift is caught at the server layer automatically.

### Scope

Validation covers the terminal API endpoints defined in `api/terminal.yaml`:
- `GET /health`
- `GET /api/sync/members`
- `GET /api/sync/categories`
- `GET /api/sync/products`
- `POST /api/sync/transactions`
- `GET /api/terminal/transactions/{member_id}`

---

## Developer Workflow

| Event | Action | What is caught |
|---|---|---|
| Spec field renamed/removed | `dart run build_runner build` | Dart compile errors at usage sites |
| Spec field added (required) | `dart run build_runner build` | Compile errors if not provided |
| Backend response missing field | Run Playwright suite | Middleware validation failure → test failure |
| Backend response wrong type | Run Playwright suite | Middleware validation failure → test failure |
| CI pipeline | Runs build + Playwright | Full contract enforcement on every push |

---

## Out of Scope

- Admin API (`api/admin.yaml`) — admin frontend is React, not Dart; separate concern
- Runtime validation in production — overhead not justified; test-time coverage is sufficient
- openapi-generator CLI / Java toolchain — rejected in favour of build_runner-native approach
- PHPUnit contract tests — Playwright E2E suite already covers all terminal endpoints end-to-end
- `DispenserClient` migration — communicates with ESP8266 hardware dispenser, no OAS spec exists; `package:http` retained for this use

---

## Files Affected

| File/Directory | Change |
|---|---|
| `terminal-frontend/pubspec.yaml` | Add `chopper`, `json_annotation`; add `swagger_dart_code_generator`, `json_serializable` to dev deps. Retain `http: ^1.6.0` (used by `DispenserClient`). |
| `terminal-frontend/build.yaml` | Configure `swagger_dart_code_generator` with path to `api/terminal.yaml` |
| `terminal-frontend/lib/generated/` | New directory (gitignored); contains generated models + client |
| `terminal-frontend/lib/services/network_service.dart` | Rewrite to wrap generated Chopper client; add `TokenInterceptor`; add `getTransactionHistory()` |
| `terminal-frontend/lib/services/transaction_history_service.dart` | Remove direct `package:http` calls; change constructor to accept `NetworkService` instead of `baseUrl`/`authToken`; delegate HTTP to `NetworkService.getTransactionHistory()` |
| `terminal-frontend/lib/widgets/member_details_modal.dart` | Update `TransactionHistoryService` construction to pass `networkService` instead of `baseUrl`/`authToken` |
| `terminal-frontend/lib/models/member_dto.dart` | Delete — replaced by generated model |
| `terminal-frontend/lib/models/product_dto.dart` | Delete — replaced by generated model |
| `terminal-frontend/lib/models/category_dto.dart` | Delete — replaced by generated model |
| `terminal-frontend/lib/models/sync_response.dart` | Delete — replaced by generated response wrappers |
| `terminal-frontend/lib/models/transaction_sync_response.dart` | Delete — replaced by generated model |
| `terminal-frontend/lib/models/transaction_list_item.dart` | Retain — local UI projection, not API-generated |
| `terminal-frontend/lib/models/shopping_cart.dart` | Retain — local UI model |
| `terminal-frontend/lib/models/cart_item.dart` | Retain — local UI model |
| `terminal-frontend/lib/repository/members_repository.dart` | Update import and `upsertMembers(List<MemberDTO>)` signature to use generated type |
| `terminal-frontend/lib/repository/products_repository.dart` | Update imports and `upsertCategories()`/`upsertProducts()` signatures to use generated types |
| `terminal-frontend/lib/providers/rfid_provider.dart` | Update import and inline `MemberDTO` construction to use generated type |
| `terminal-frontend/lib/services/mock_rfid_service.dart` | Update throughout — entire file is built around `MemberDTO`; replace with generated type |
| `terminal-frontend/lib/main.dart` | Update DTO imports and seed data constructors to use generated types |
| `terminal-frontend/test/models_test.dart` | Rewrite — currently tests hand-written `fromJson`; replace with equivalent tests against generated types (or delete if generated types are considered trusted and not worth re-testing) |
| `terminal-frontend/test/models/transaction_sync_response_test.dart` | Update import to generated type |
| `terminal-frontend/test/repository_test.dart` | Update DTO imports to generated types |
| `terminal-frontend/test/sync_service_test.dart` | Update imports to generated response types |
| `terminal-frontend/test/services/members_service_test.dart` | Update import to generated type |
| `terminal-frontend/integration_test/test_helpers.dart` | Update all DTO imports to generated types |
| `terminal-frontend/integration_test/walkthrough_test.dart` | Update DTO imports to generated types |
| `backend/composer.json` | Add `league/openapi-psr7-validator: ^3.0` |
| `backend/bootstrap.php` | Register validation middleware after `add(ErrorHandler)`, guarded by `APP_ENV=test` |
| `docker-compose.yml` (or test env config) | Set `APP_ENV=test` for test runs |
