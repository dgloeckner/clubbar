# Admin Frontend: Generated API Client Design

**Date:** 2026-03-21
**Status:** Approved

## Problem

The admin frontend maintains API types and an HTTP client by hand:

- `src/types/index.ts` — ~320 lines of manually written TypeScript interfaces, frequently out of sync with the real API
- `src/services/api.ts` — custom Axios wrapper with generic `get/post/patch/del` functions
- `src/services/*.ts` — service files each defining their own duplicate interfaces (e.g. `Member` is defined in both `types/index.ts` and `members.ts` with different fields), and containing `as any` casts and runtime response-format sniffing (`if ('items' in response)...`) to compensate for type drift
- `ProductsPage.tsx` and `CategoriesPage.tsx` import `get/post/patch/del` directly from `api.ts` (no service file intermediary)

The result: types drift from the OAS spec, bugs hide behind `as any`, and every API change requires updates in multiple places.

## Goal

Make `api/admin.yaml` the single source of truth. Types and typed API functions are generated from the spec. Manual models and the custom client wrapper are deleted.

## Approach

Use **orval** (devDependency) to generate typed Axios functions and TypeScript types from `api/admin.yaml`. orval supports a custom Axios mutator, preserving all existing cross-cutting concerns (CSRF, loading state, 401 redirect, file download).

## Architecture

### What is deleted

| File | Replaced by |
|------|-------------|
| `src/types/index.ts` | `src/api/generated/schemas.ts` (generated) + local UI types in context files |
| `src/services/api.ts` | `src/api/client.ts` (slim instance config) |
| `src/services/auth.ts` | `src/api/generated/authentication.ts` (HTTP calls) + `src/auth/session.ts` (session utilities) |
| `src/services/members.ts` | `src/api/generated/members.ts` (generated) |
| `src/services/transactions.ts` | `src/api/generated/transactions.ts` (generated) |
| `src/services/settlements.ts` | `src/api/generated/settlements.ts` (generated) |
| `src/services/admin-users.ts` | `src/api/generated/adminUsers.ts` (generated) |
| `src/services/audit-log.ts` | `src/api/generated/auditLog.ts` (generated) |
| `src/services/reports.ts` | `src/api/generated/reports.ts` (generated) |
| `src/services/sepa-config.ts` | `src/api/generated/sepaConfiguration.ts` (generated) |
| `src/services/terminals.ts` | `src/api/generated/terminals.ts` (generated) |
| `src/services/dashboard.ts` | `src/api/generated/reports.ts` (generated) |

`src/services/products.ts` does not exist — `ProductsPage.tsx` and `CategoriesPage.tsx` call `api.ts` directly. Both pages are migrated to generated functions in Phase 3.

### Generated output structure

```
api/admin.yaml
    │
    └── orval
            ├── src/api/generated/schemas.ts          ← all types
            ├── src/api/generated/authentication.ts
            ├── src/api/generated/members.ts
            ├── src/api/generated/transactions.ts
            ├── src/api/generated/products.ts
            ├── src/api/generated/settlements.ts
            ├── src/api/generated/sepaConfiguration.ts
            ├── src/api/generated/adminUsers.ts
            ├── src/api/generated/reports.ts
            ├── src/api/generated/auditLog.ts
            └── src/api/generated/rfidCards.ts

src/api/client.ts       ← Axios instance + interceptors (custom mutator)
src/auth/session.ts     ← session utilities (not generated)
```

Components import directly:
```ts
import { getAdminMembers, createAdminMember } from '../api/generated/members'
import type { Member } from '../api/generated/schemas'
```

### `src/api/client.ts` — custom mutator

Replaces `src/services/api.ts`. Retains all existing behaviour, drops only the generic HTTP wrappers:

- CSRF token management (`setCsrfToken`, localStorage persistence) — unchanged
- Global loading state pub/sub (`onLoadingStateChange`, pending request counter) — unchanged
- 401 → clear auth + redirect to `/login` — unchanged
- `downloadFile(url, fallbackFilename)` — unchanged, re-exported from this file
- Exports `customInstance<T>(config: AxiosRequestConfig): Promise<T>` — the function orval calls for every generated request

What disappears: the generic `get<T>()`, `post<T>()`, `patch<T>()`, `put<T>()`, `del<T>()` wrappers and all runtime response-format sniffing.

Pages that currently import `onLoadingStateChange` or `downloadFile` directly from `services/api` (`JournalPage.tsx`, `ProductsPage.tsx`, `SettlementsPage.tsx`) update their import path to `src/api/client.ts`.

### `src/auth/session.ts` — session utilities

`auth.ts` mixes HTTP calls (replaceable by generated functions) with app-level session logic (not replaceable). The session logic moves to `src/auth/session.ts`:

- `getCurrentSession()` — reads admin id/email/display_name/locale from localStorage
- `isAuthenticated()` — checks localStorage for admin_id
- `loginWithSession(credentials)` — wraps the generated `login()` function: calls it, stores the response in localStorage, calls `setCsrfToken` from the login response, calls `changeLanguage`
- `logoutWithSession()` — wraps the generated `logout()` function: calls it, clears localStorage, calls `setCsrfToken(null)`

**CSRF on login:** The current `auth.ts` reads `csrf_token` from the login response body and calls `setCsrfToken`. After migration, this side effect moves into `loginWithSession()` in `session.ts`. The generated `login()` function makes the HTTP call; `loginWithSession()` consumes the result and handles all side effects. `AuthContext.tsx` calls `loginWithSession()` instead of the generated `login()` directly.

**`AuthState` and `AuthResponse` types:** These are UI wrapper types (`success: boolean`, component state shape) that do not exist in the generated schemas. `AuthContext.tsx` keeps its own local type definitions for these. The generated `LoginResponse` type from `schemas.ts` is used inside `loginWithSession()` but does not replace `AuthState`.

### orval configuration

`admin-frontend/orval.config.ts`:

```ts
import { defineConfig } from 'orval'

export default defineConfig({
  admin: {
    input: '../api/admin.yaml',
    output: {
      mode: 'tags-split',
      target: 'src/api/generated/',
      schemas: 'src/api/generated/schemas.ts',
      client: 'axios',
      override: {
        mutator: {
          path: 'src/api/client.ts',
          name: 'customInstance',
        },
      },
    },
  },
})
```

`package.json` addition:
```json
"generate": "orval"
```

Generated files are **committed to the repo** (not gitignored). Contributors do not need to regenerate to build. Regeneration is required only when `api/admin.yaml` changes.

## Migration Path

Phased migration — each phase independently verifiable before proceeding:

### Phase 0 — OAS spec fixes (prerequisite)
Before generating, two gaps in `api/admin.yaml` must be filled:
- Add `GET /auth/profile` endpoint (returns `AdminProfile`)
- Add `PATCH /auth/profile` endpoint (updates admin profile, returns `AdminProfile`)
- Add `PATCH /auth/change-password` endpoint
- Add `csrf_token` field to the `LoginResponse` schema (currently absent; `auth.ts` reads it via `as any`)

These omissions mean `ProfilePage.tsx` and the CSRF-on-login path have no generated counterparts. The OAS spec must be the source of truth, so the spec is extended rather than keeping manual implementations.

### Phase 1 — Scaffolding
- Install orval as devDependency
- Add `orval.config.ts`
- Add `generate` script to `package.json`
- Run `npm run generate`, verify output compiles

### Phase 2 — Create `src/api/client.ts` and `src/auth/session.ts`
- Extract Axios instance + interceptors from `src/services/api.ts` into `src/api/client.ts`
- **Remove** the dead `auth_token` Bearer header from the request interceptor — it is never written by the app (the API uses cookie-based session auth, not Bearer tokens)
- Export `customInstance` mutator and `downloadFile` from `client.ts`
- Extract session utilities from `src/services/auth.ts` into `src/auth/session.ts`
- Implement `loginWithSession` and `logoutWithSession` wrappers
- `loginWithSession` reads `csrf_token` from the generated `LoginResponse` (now present in schema after Phase 0) and calls `setCsrfToken`; also stores admin data in localStorage and calls `changeLanguage`
- `updateProfileWithSession` wraps generated `updateProfile()`, writes updated `email`/`display_name`/`locale` back to localStorage after success
- Update `AuthContext.tsx` to import from `src/auth/session.ts`
- Verify loading state behaviour, CSRF on login, and 401 redirect still work

### Phase 3 — Migrate pages and components domain by domain
For each domain, update all imports in pages/components:

1. **Auth** — `AuthContext.tsx`: import `loginWithSession`, `logoutWithSession`, `getCurrentSession`, `isAuthenticated` from `session.ts`. `ProfilePage.tsx`: import `getProfile`, `changePassword` from generated `authentication.ts`; import `updateProfileWithSession` from `session.ts`
2. **Members** — import from generated `members.ts`
3. **Products/Categories** — `ProductsPage.tsx` and `CategoriesPage.tsx` (direct `api.ts` callers): replace inline `get/post/patch/del` calls with generated functions from `products.ts`
4. **Transactions** — import from generated `transactions.ts`
5. **Settlements** — `SettlementsPage.tsx`: generated `settlements.ts` for API calls; `downloadFile` import updated to `api/client.ts`
6. **Admin Users** — import from generated `adminUsers.ts`
7. **Audit Log** — `JournalPage.tsx`: generated `auditLog.ts` for API calls; `onLoadingStateChange` import updated to `api/client.ts`
8. **Reports** — import from generated `reports.ts`
9. **SEPA Config** — import from generated `sepaConfiguration.ts`
10. **Terminals** — import from generated `terminals.ts`

Type imports: update from `src/types/index.ts` to `src/api/generated/schemas.ts` throughout. Remove all `as any` casts (generated types make them unnecessary).

### Phase 4 — Delete old files
Once no file imports from them:
- Delete `src/types/index.ts`
- Delete `src/services/api.ts`
- Delete all `src/services/*.ts`
- Update `technologies.md`

## Error Handling

The generated functions throw Axios errors on non-2xx responses. Error handling in components/pages remains unchanged — they already catch Axios errors. The 401 global redirect continues to fire from the response interceptor in `client.ts`.

## Testing

- E2E tests (Playwright) are the primary verification mechanism — no changes to test infrastructure required
- After each domain migration in Phase 3, run the relevant E2E tests to confirm no regressions
- Full suite after Phase 4

## Consequences

**Positive:**
- `api/admin.yaml` is the single source of truth for types and API contracts
- No more type drift, no more `as any` casts, no more duplicate interface definitions
- Adding or changing an endpoint requires only updating the OAS spec and re-running `npm run generate`
- Response shape is guaranteed by the generated types — runtime sniffing code is eliminated
- `ProductsPage.tsx` and `CategoriesPage.tsx` lose their direct dependency on the raw HTTP client

**Negative:**
- Generated files must be kept in sync with the spec — if `api/admin.yaml` changes without re-running `npm run generate`, the repo is silently out of sync. Mitigation: a CI step running `npm run generate && git diff --exit-code` can catch this; this is optional for a small team but recommended
- orval adds a devDependency and a codegen step to the workflow
- `loginWithSession` is a thin wrapper that must be kept aligned with the generated `login()` signature when the auth endpoint changes
