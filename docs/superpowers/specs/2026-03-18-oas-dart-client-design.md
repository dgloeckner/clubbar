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
- Configured via `build.yaml` pointing at `api/terminal.yaml`
- No external tools or Java runtime required
- Run: `dart run build_runner build`

### Generated Artifacts

From `api/terminal.yaml`, the generator produces:
- **Model classes** with `fromJson`/`toJson` — replaces hand-written `MemberDTO`, `ProductDTO`, `CategoryDTO`, `MembersSyncResponse`, `ProductsSyncResponse`, `CategoriesSyncResponse`, `TransactionSyncResponse`
- **Typed API client** (Chopper-based) — one method per OAS path/operation

Generated files live in `lib/generated/` and are gitignored. They are regenerated on every `dart run build_runner build`.

### NetworkService Wrapper

The existing `NetworkService` is retained as the public API for the rest of the app. It becomes a thin wrapper over the generated Chopper client and continues to own:

| Responsibility | Detail |
|---|---|
| Bearer token injection | Set on the Chopper client interceptor |
| 304 Not Modified → `null` | Translated before returning to callers |
| `since` delta parameter | Constructed before passing to generated methods |
| `NetworkException` translation | Generated client errors mapped to existing type |

The rest of the app (`SyncService`, repositories, providers) is unchanged.

### New Dependencies

| Package | Role |
|---|---|
| `swagger_dart_code_generator` | Dev: build_runner code generator |
| `chopper` | Runtime: generated HTTP client base |
| `json_annotation` | Runtime: generated model serialization |
| `json_serializable` | Dev: build_runner JSON codegen |

---

## Backend Contract Validation (backend/)

### Tooling

**Package:** `league/openapi-psr7-validator`
- PSR-15 middleware; fits naturally into the Slim 4 middleware stack
- Validates both request and response against `api/terminal.yaml`
- No changes to controllers, services, or repositories required

### Integration

The middleware is registered in the Slim 4 app conditionally:

```
APP_ENV=test → middleware active
APP_ENV=production → middleware inactive (no overhead)
```

The Docker Compose configuration for test runs sets `APP_ENV=test`.

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

---

## Files Affected

| File/Directory | Change |
|---|---|
| `terminal-frontend/pubspec.yaml` | Add `chopper`, `json_annotation`; add `swagger_dart_code_generator`, `json_serializable` to dev deps |
| `terminal-frontend/build.yaml` | Configure `swagger_dart_code_generator` with path to `api/terminal.yaml` |
| `terminal-frontend/lib/generated/` | New directory (gitignored); contains generated models + client |
| `terminal-frontend/lib/services/network_service.dart` | Rewrite to wrap generated Chopper client |
| `terminal-frontend/lib/models/` | Hand-written DTO files removed (replaced by generated equivalents) |
| `backend/composer.json` | Add `league/openapi-psr7-validator` |
| `backend/src/app.php` (or middleware bootstrap) | Register validation middleware behind `APP_ENV=test` guard |
| `docker-compose.yml` (or test env config) | Set `APP_ENV=test` for test runs |
