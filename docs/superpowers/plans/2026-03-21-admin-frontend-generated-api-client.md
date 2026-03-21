# Admin Frontend: Generated API Client Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace hand-written API types and custom Axios client with an orval-generated typed client driven by `api/admin.yaml`, eliminating type drift and `as any` casts.

**Architecture:** orval reads `api/admin.yaml` and generates typed Axios functions (one file per OAS tag) + all TypeScript types. A slim `src/api/client.ts` exports the custom Axios mutator (preserving CSRF, loading state, 401 redirect, file download). A new `src/auth/session.ts` holds session side-effects that cannot be generated. All old service files and `src/types/index.ts` are deleted.

**Tech Stack:** orval (devDep), Axios, TypeScript, React 18, Vite, Playwright (E2E tests)

**Spec:** `docs/superpowers/specs/2026-03-21-admin-frontend-generated-api-client-design.md`

---

## File Map

**Created:**
- `admin-frontend/orval.config.ts` — orval configuration
- `admin-frontend/src/api/client.ts` — Axios instance, CSRF, loading state, 401, downloadFile, customInstance mutator
- `admin-frontend/src/auth/session.ts` — loginWithSession, logoutWithSession, updateProfileWithSession, getCurrentSession, isAuthenticated
- `admin-frontend/src/utils/transactions.ts` — display utilities extracted from services/transactions.ts (getTransactionTypeColor, getAmountColor, formatTransactionType, localizeTransactions transform)
- `admin-frontend/src/api/generated/` — all generated files (committed to repo)

**Modified:**
- `api/admin.yaml` — add GET/PATCH /auth/profile, csrf_token to LoginResponse
- `admin-frontend/package.json` — add generate script + orval devDep
- `admin-frontend/src/context/AuthContext.tsx`
- `admin-frontend/src/pages/ProfilePage.tsx`
- `admin-frontend/src/pages/MembersPage.tsx`
- `admin-frontend/src/pages/ProductsPage.tsx`
- `admin-frontend/src/pages/CategoriesPage.tsx`
- `admin-frontend/src/pages/JournalPage.tsx`
- `admin-frontend/src/pages/SettlementsPage.tsx`
- `admin-frontend/src/pages/AuditLogPage.tsx`
- `admin-frontend/src/pages/SettingsPage.tsx`
- `admin-frontend/src/pages/DashboardPage.tsx`
- `admin-frontend/src/components/modals/TransactionModal.tsx`
- `admin-frontend/src/components/modals/SettlementConfirmModal.tsx`
- `admin-frontend/technologies.md`

**Deleted (Phase 4):**
- `admin-frontend/src/types/index.ts`
- `admin-frontend/src/services/api.ts`
- `admin-frontend/src/services/auth.ts`
- `admin-frontend/src/services/members.ts`
- `admin-frontend/src/services/transactions.ts`
- `admin-frontend/src/services/settlements.ts`
- `admin-frontend/src/services/admin-users.ts`
- `admin-frontend/src/services/audit-log.ts`
- `admin-frontend/src/services/reports.ts`
- `admin-frontend/src/services/sepa-config.ts`
- `admin-frontend/src/services/terminals.ts`
- `admin-frontend/src/services/dashboard.ts`

---

## Chunk 1: OAS Fixes + Scaffolding

### Task 1: Fix `api/admin.yaml` — add all missing endpoints and schemas

The OAS is missing several endpoints that the service files call. Since the OAS is the source of truth, all missing endpoints must be added before generation. The following are absent:

- `GET /auth/profile` and `PATCH /auth/profile`
- `csrf_token` in `LoginResponse`
- `POST /admin/settlements/filter-preview` — used by `getSettlementFilterPreview()`
- `POST /admin/settlements/settle-filter` — used by `createSettlementByFilters()`
- `DELETE /admin/admin-users/{id}` or `PATCH /admin/admin-users/{id}` for deactivation/reactivation (check existing spec — may use PATCH)
- Entire `/admin/terminals` resource (terminals are not in the spec at all)

Also, `auth.ts` calls `PATCH /auth/change-password` but the OAS defines it as `POST` — the spec is correct, auth.ts is wrong (this is already handled in ProfilePage migration, Task 6).

**Files:**
- Modify: `api/admin.yaml`

- [ ] **Step 1: Add `csrf_token` to `LoginResponse` schema**

Find the `LoginResponse` schema (around line 2206) and add the field:

```yaml
    LoginResponse:
      type: object
      required: [admin_id, email, display_name, locale, csrf_token]
      properties:
        admin_id:
          type: string
          format: uuid
        email:
          type: string
          format: email
        display_name:
          type: string
        locale:
          type: string
        csrf_token:
          type: string
          description: CSRF token for subsequent state-changing requests
```

- [ ] **Step 2: Add `GET /auth/profile` and `PATCH /auth/profile` endpoints**

Insert after the `/auth/change-password` block (around line 200), before the `# MEMBERS` section:

```yaml
  /auth/profile:
    get:
      tags: [Authentication]
      summary: Get current admin profile
      description: |
        Get the profile of the currently authenticated admin user.

        **Use Case**: UC-A03
      operationId: getProfile
      security:
        - sessionAuth: []
      responses:
        '200':
          description: Profile retrieved successfully
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/AdminProfile'
        '401':
          $ref: '#/components/responses/Unauthorized'
    patch:
      tags: [Authentication]
      summary: Update current admin profile
      description: |
        Update email, display name, or locale for the currently authenticated admin user.

        **Use Case**: UC-A03
      operationId: updateProfile
      security:
        - sessionAuth: []
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: '#/components/schemas/UpdateProfileRequest'
      responses:
        '200':
          description: Profile updated successfully
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/AdminProfile'
        '400':
          $ref: '#/components/responses/ValidationError'
        '401':
          $ref: '#/components/responses/Unauthorized'
```

- [ ] **Step 3: Add `AdminProfile` and `UpdateProfileRequest` schemas**

In the `components/schemas` section, after `ChangePasswordRequest`:

```yaml
    AdminProfile:
      type: object
      properties:
        id:
          type: string
          format: uuid
        email:
          type: string
          format: email
        display_name:
          type: string
        locale:
          type: string
        last_login_at:
          type: string
          format: date-time
          nullable: true

    UpdateProfileRequest:
      type: object
      properties:
        email:
          type: string
          format: email
        display_name:
          type: string
        locale:
          type: string
          enum: [de, en]
```

- [ ] **Step 4: Add full terminals resource**

Add the complete terminals resource (previously undocumented):
- `GET /admin/terminals` (operationId: `listTerminals`)
- `POST /admin/terminals` (operationId: `createTerminal`)
- `PATCH /admin/terminals/{terminalId}` (operationId: `updateTerminal`)
- `POST /admin/terminals/{terminalId}/rotate-token` (operationId: `rotateTerminalToken`)
- `POST /admin/terminals/{terminalId}/revoke` (operationId: `revokeTerminalAccess`)

Reference `services/terminals.ts` for exact request/response shapes and schemas.

- [ ] **Step 5: Add settlement filter-preview and settle-filter endpoints**

Add `GET /admin/settlements/filter-preview` (operationId: `previewSettlementByFilters`) and `POST /admin/settlements/settle-filter` (operationId: `createSettlementByFilters`). Reference `services/settlements.ts` for the exact request/response shapes.

- [ ] **Step 6: Add global transactions list endpoint**

Add `GET /admin/transactions` (operationId: `listTransactions`) with pagination, date range, type, member, search, sort, and `settlement_status` query params. Reference `services/transactions.ts#getTransactions()` for the full parameter list. Response schema: `{ items: GlobalTransaction[], total, page, per_page }`.

- [ ] **Step 7: Verify admin-users — no OAS changes needed**

The OAS uses `updateAdminUser` (PATCH with `is_active`) — there are no separate deactivation/reactivation endpoints. Task 11 calls `updateAdminUser(id, { is_active: false/true })` directly.

- [ ] **Step 8: Validate OAS spec is valid YAML**

```bash
cd /Users/dg/dev/frgs-vereinsbar
npx --yes @redocly/cli lint api/admin.yaml --skip-rule operation-operationId-unique 2>&1 | tail -5
```

Expected: no errors (warnings about missing examples are OK)

- [ ] **Step 9: Commit**

```bash
git add api/admin.yaml
git commit -m "feat(oas): add missing endpoints — auth profile, terminals, transactions list, settlement filters, csrf_token"
```

---

### Task 2: Install orval and scaffold code generation

**Files:**
- Modify: `admin-frontend/package.json`
- Create: `admin-frontend/orval.config.ts`

- [ ] **Step 1: Install orval**

```bash
cd admin-frontend
npm install --save-dev orval
```

- [ ] **Step 2: Create `orval.config.ts`**

Create `admin-frontend/orval.config.ts`:

```ts
import { defineConfig } from 'orval'

export default defineConfig({
  admin: {
    input: '../api/admin.yaml',
    output: {
      mode: 'tags-split',
      target: 'src/api/generated/',
      schemas: 'src/api/generated',
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

- [ ] **Step 3: Add generate script to `package.json`**

Add to the `scripts` section:
```json
"generate": "orval"
```

- [ ] **Step 4: Create a stub `src/api/client.ts` so orval can reference it**

Create `admin-frontend/src/api/client.ts` with just the stub export (the full implementation comes in Task 3):

```ts
import axios from 'axios'
import type { AxiosRequestConfig } from 'axios'

// Stub — replaced fully in Task 3
const axiosInstance = axios.create({ baseURL: '/api', withCredentials: true })

export const customInstance = <T>(
  config: AxiosRequestConfig,
  options?: { signal?: AbortSignal }
): Promise<T> =>
  axiosInstance({ ...config, signal: options?.signal }).then(({ data }) => data)

export default axiosInstance
```

- [ ] **Step 5: Run code generation**

```bash
cd admin-frontend
npm run generate
```

Expected: `src/api/generated/` directory created with files like `authentication.ts`, `members.ts`, `schemas.ts`, etc.

- [ ] **Step 6: Verify generated files compile**

```bash
cd admin-frontend
npx tsc --noEmit 2>&1 | head -30
```

Expected: compilation errors only from the existing service files (which still import `api.ts`), not from the generated files themselves.

- [ ] **Step 7: Commit**

```bash
cd admin-frontend
git add package.json package-lock.json orval.config.ts src/api/generated/ src/api/client.ts
git commit -m "feat(codegen): scaffold orval generated API client from admin.yaml"
```

---

## Chunk 2: Infrastructure — client.ts + session.ts + AuthContext

### Task 3: Create `src/api/client.ts` — the full Axios mutator

Replace the stub with the full implementation. This file replaces `services/api.ts`, keeping all cross-cutting concerns and dropping the generic HTTP wrappers.

**Files:**
- Modify: `admin-frontend/src/api/client.ts`

- [ ] **Step 1: Write `src/api/client.ts`**

```ts
/**
 * API Client — Axios instance + custom orval mutator
 *
 * Cross-cutting concerns:
 * - CSRF token management (persisted to localStorage)
 * - Global loading state pub/sub (drives LoadingIndicator)
 * - 401 → redirect to /login
 * - File download helper
 *
 * NOTE: Bearer token auth is NOT used — the API uses cookie-based session auth.
 */

import axios from 'axios'
import type { AxiosRequestConfig } from 'axios'

// ─── CSRF ────────────────────────────────────────────────────────────────────

let csrfToken: string | null = localStorage.getItem('csrf_token')

export function setCsrfToken(token: string | null) {
  csrfToken = token
  if (token) {
    localStorage.setItem('csrf_token', token)
  } else {
    localStorage.removeItem('csrf_token')
  }
}

// ─── Loading state ───────────────────────────────────────────────────────────

let pendingRequests = 0
const loadingStateCallbacks: Array<(isLoading: boolean) => void> = []

export function onLoadingStateChange(callback: (isLoading: boolean) => void): () => void {
  loadingStateCallbacks.push(callback)
  return () => {
    const index = loadingStateCallbacks.indexOf(callback)
    if (index > -1) loadingStateCallbacks.splice(index, 1)
  }
}

function notifyLoadingState() {
  const isLoading = pendingRequests > 0
  loadingStateCallbacks.forEach(cb => cb(isLoading))
}

function incrementPending() {
  const wasLoading = pendingRequests > 0
  pendingRequests++
  if (!wasLoading) notifyLoadingState()
}

function decrementPending() {
  pendingRequests = Math.max(0, pendingRequests - 1)
  notifyLoadingState()
}

// ─── Axios instance ───────────────────────────────────────────────────────────

const axiosInstance = axios.create({
  baseURL: '/api',
  headers: { 'Content-Type': 'application/json' },
  withCredentials: true,
})

axiosInstance.interceptors.request.use(
  (config) => {
    if (csrfToken && config.method && !['get', 'head', 'options'].includes(config.method)) {
      config.headers['X-CSRF-Token'] = csrfToken
    }
    incrementPending()
    return config
  },
  (error) => {
    decrementPending()
    return Promise.reject(error)
  }
)

axiosInstance.interceptors.response.use(
  (response) => {
    decrementPending()
    return response
  },
  (error) => {
    decrementPending()
    if (error.response?.status === 401) {
      localStorage.removeItem('admin_id')
      localStorage.removeItem('email')
      localStorage.removeItem('display_name')
      localStorage.removeItem('locale')
      localStorage.removeItem('csrf_token')
      csrfToken = null
      if (window.location.pathname !== '/login') {
        window.location.href = '/login'
      }
    }
    if (error.response?.status === 403) {
      console.error('Access forbidden')
    }
    if (error.response?.status === 500) {
      console.error('Server error:', error.response.data)
    }
    return Promise.reject(error)
  }
)

// ─── orval mutator ────────────────────────────────────────────────────────────

export const customInstance = <T>(
  config: AxiosRequestConfig,
  options?: { signal?: AbortSignal }
): Promise<T> =>
  axiosInstance({ ...config, signal: options?.signal }).then(({ data }) => data)

// ─── File download ────────────────────────────────────────────────────────────

export async function downloadFile(url: string, fallbackFilename: string): Promise<void> {
  const response = await axiosInstance.get(url, { responseType: 'blob' })
  const contentDisposition = response.headers['content-disposition']
  let filename = fallbackFilename
  if (contentDisposition) {
    const match = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
    if (match?.[1]) filename = match[1].replace(/['"]/g, '')
  }
  const blob = new Blob([response.data])
  const objectUrl = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = objectUrl
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(objectUrl)
}

export default axiosInstance
```

- [ ] **Step 2: Re-run generation to ensure mutator is resolved**

```bash
cd admin-frontend && npm run generate
```

Expected: generation succeeds, no mutator resolution errors.

- [ ] **Step 3: Type-check**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | grep "src/api" | head -10
```

Expected: no errors in `src/api/`.

- [ ] **Step 4: Commit**

```bash
git add admin-frontend/src/api/client.ts admin-frontend/src/api/generated/
git commit -m "feat(client): create Axios mutator with CSRF, loading state, 401 redirect"
```

---

### Task 4: Create `src/auth/session.ts`

Session side-effects that cannot be generated: localStorage management, CSRF on login, language switch on login.

**Files:**
- Create: `admin-frontend/src/auth/session.ts`

- [ ] **Step 1: Create `src/auth/session.ts`**

```ts
/**
 * Session utilities — side effects that wrap generated API functions.
 *
 * The generated authentication functions handle HTTP. This file handles
 * the localStorage + CSRF + i18n side effects that happen around them.
 */

import { login, logout, updateProfile, getProfile } from '../api/generated/authentication'
import { setCsrfToken } from '../api/client'
import { changeLanguage } from '../i18n/config'
import type { UpdateProfileRequest, AdminProfile } from '../api/generated/schemas'

// ─── Read-only session helpers ────────────────────────────────────────────────

export function getCurrentSession() {
  return {
    adminId: localStorage.getItem('admin_id'),
    email: localStorage.getItem('email'),
    displayName: localStorage.getItem('display_name'),
    // 'de' (not 'de-DE') — i18n/config.ts only accepts ISO 639-1 codes
    locale: localStorage.getItem('locale') || 'de',
  }
}

export function isAuthenticated(): boolean {
  return !!localStorage.getItem('admin_id')
}

// ─── Login ────────────────────────────────────────────────────────────────────

export interface LoginResult {
  success: boolean
  message: string
  data?: {
    admin_id: string
    email: string
    display_name: string
    locale: string
  }
}

export async function loginWithSession(credentials: {
  email: string
  password: string
}): Promise<LoginResult> {
  try {
    const response = await login({ email: credentials.email, password: credentials.password })

    if (response.admin_id) {
      localStorage.setItem('admin_id', response.admin_id)
      localStorage.setItem('email', response.email ?? '')
      localStorage.setItem('display_name', response.display_name ?? '')
      localStorage.setItem('locale', response.locale ?? 'de')

      if (response.csrf_token) {
        setCsrfToken(response.csrf_token)
      }

      const locale = response.locale || 'de'
      changeLanguage(locale)

      return {
        success: true,
        message: 'Login successful',
        data: {
          admin_id: response.admin_id,
          email: response.email ?? '',
          display_name: response.display_name ?? '',
          locale,
        },
      }
    }

    return { success: false, message: 'Login failed' }
  } catch (error: any) {
    const message = error.response?.data?.message || 'Login failed'
    return { success: false, message }
  }
}

// ─── Logout ───────────────────────────────────────────────────────────────────

export async function logoutWithSession(): Promise<void> {
  try {
    await logout()
  } catch (error) {
    console.error('Logout error:', error)
  } finally {
    localStorage.removeItem('admin_id')
    localStorage.removeItem('email')
    localStorage.removeItem('display_name')
    localStorage.removeItem('locale')
    setCsrfToken(null)
  }
}

// ─── Profile update ───────────────────────────────────────────────────────────

export async function updateProfileWithSession(
  data: UpdateProfileRequest
): Promise<AdminProfile> {
  const profile = await updateProfile(data)
  if (profile) {
    if (profile.email) localStorage.setItem('email', profile.email)
    if (profile.display_name) localStorage.setItem('display_name', profile.display_name)
    if (profile.locale) localStorage.setItem('locale', profile.locale)
  }
  return profile
}

// Re-export getProfile for convenience (no side effects needed)
export { getProfile }
```

- [ ] **Step 2: Type-check**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | grep "src/auth" | head -10
```

Expected: no errors in `src/auth/`.

- [ ] **Step 3: Commit**

```bash
git add admin-frontend/src/auth/session.ts
git commit -m "feat(auth): create session.ts with loginWithSession, logoutWithSession, updateProfileWithSession"
```

---

### Task 5: Update `AuthContext.tsx` to use session.ts

`AuthContext.tsx` currently imports from `services/auth` and `types/index.ts`. Migrate it to use `session.ts`.

**Files:**
- Modify: `admin-frontend/src/context/AuthContext.tsx`

- [ ] **Step 1: Update `AuthContext.tsx`**

Replace the file content with:

```tsx
/**
 * Authentication Context
 * Provides global auth state and methods to all components
 */

import { createContext, useContext, useState, useEffect, ReactNode } from 'react'
import {
  loginWithSession,
  logoutWithSession,
  getCurrentSession,
  isAuthenticated,
} from '../auth/session'

// UI-level types — not from generated schemas
interface LoginCredentials {
  email: string
  password: string
}

interface AuthState {
  isAuthenticated: boolean
  adminId?: string
  email?: string
  displayName?: string
  locale?: string
}

interface AuthContextType extends AuthState {
  login: (credentials: LoginCredentials) => Promise<boolean>
  logout: () => Promise<void>
  loading: boolean
  error?: string
}

const AuthContext = createContext<AuthContextType | undefined>(undefined)

interface AuthProviderProps {
  children: ReactNode
}

export function AuthProvider({ children }: AuthProviderProps) {
  const [auth, setAuth] = useState<AuthState>({ isAuthenticated: false })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string>()

  useEffect(() => {
    const session = getCurrentSession()
    if (isAuthenticated()) {
      setAuth({
        isAuthenticated: true,
        adminId: session.adminId || undefined,
        email: session.email || undefined,
        displayName: session.displayName || undefined,
        locale: session.locale,
      })
    }
    setLoading(false)
  }, [])

  const handleLogin = async (credentials: LoginCredentials): Promise<boolean> => {
    setLoading(true)
    setError(undefined)
    try {
      const response = await loginWithSession(credentials)
      if (response.success && response.data) {
        setAuth({
          isAuthenticated: true,
          adminId: response.data.admin_id,
          email: response.data.email,
          displayName: response.data.display_name,
          locale: response.data.locale,
        })
        return true
      }
      setError(response.message)
      return false
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Login failed'
      setError(message)
      return false
    } finally {
      setLoading(false)
    }
  }

  const handleLogout = async () => {
    setLoading(true)
    try {
      await logoutWithSession()
      setAuth({ isAuthenticated: false })
      setError(undefined)
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Logout failed'
      setError(message)
    } finally {
      setLoading(false)
    }
  }

  const value: AuthContextType = {
    ...auth,
    login: handleLogin,
    logout: handleLogout,
    loading,
    error,
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextType {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used within AuthProvider')
  return context
}
```

- [ ] **Step 2: Type-check**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | grep "AuthContext" | head -10
```

Expected: no errors.

- [ ] **Step 3: Run auth E2E tests**

```bash
cd e2etests && npm test -- tests/admin/ui-features.spec.ts tests/admin/i18n-language-switch.spec.ts --workers=4
```

Expected: all tests pass. (Profile tests belong to Task 6 — `ProfilePage.tsx` is not yet migrated.)

- [ ] **Step 4: Commit**

```bash
git add admin-frontend/src/context/AuthContext.tsx
git commit -m "feat(auth): migrate AuthContext to use session.ts wrappers"
```

---

## Chunk 3: Domain Migrations

> **Important — verify generated function names before migrating each domain.**
> orval derives TypeScript function names directly from `operationId` in the OAS spec. Before each task below, check the actual generated file (e.g., `cat admin-frontend/src/api/generated/members.ts | grep "^export"`) to confirm function names. The names in the import blocks below are based on known operationIds and are correct unless the OAS was changed during Task 1.


### Task 6: Migrate `ProfilePage.tsx`

ProfilePage imports `getProfile`, `updateProfile`, `changePassword`, `AdminProfile` from `services/auth`. After migration it uses generated functions + `session.ts`.

Note: The `ChangePasswordRequest` in `auth.ts` has `new_password_confirmation` and no `current_password`. The OAS defines `current_password`, `new_password`, `confirm_password`. The ProfilePage form needs to be updated to match the OAS.

**Files:**
- Modify: `admin-frontend/src/pages/ProfilePage.tsx`

- [ ] **Step 1: Update ProfilePage.tsx imports**

Replace:
```ts
import { getProfile, updateProfile, changePassword, AdminProfile } from '../services/auth'
```

With:
```ts
import { changePassword } from '../api/generated/authentication'
import { getProfile, updateProfileWithSession } from '../auth/session'
import type { AdminProfile } from '../api/generated/schemas'
```

- [ ] **Step 2: Update `updateProfile` call**

Find the call to `updateProfile(...)` and replace with `updateProfileWithSession(...)`.

- [ ] **Step 3: Add `currentPassword` field to password change form**

The OAS `ChangePasswordRequest` requires `current_password`. Add state and form field:

1. Add state: `const [currentPassword, setCurrentPassword] = useState('')`
2. Add an input for current password in the form (above new password)
3. Update the `changePassword` call to:
```ts
await changePassword({
  current_password: currentPassword,
  new_password: newPassword,
  confirm_password: confirmPassword,
})
```
4. Clear `currentPassword` after success

- [ ] **Step 4: Type-check**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | grep "ProfilePage" | head -10
```

Expected: no errors.

- [ ] **Step 5: Run profile E2E tests**

```bash
cd e2etests && npm test -- tests/admin/profile.spec.ts --workers=4
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add admin-frontend/src/pages/ProfilePage.tsx
git commit -m "feat(profile): migrate ProfilePage to generated API client"
```

---

### Task 7: Create `src/utils/transactions.ts` + migrate JournalPage

`services/transactions.ts` contains both API calls and display utility functions (`getTransactionTypeColor`, `getAmountColor`, `formatTransactionType`) plus a `product_names` JSON localization transform. The utilities move to `src/utils/transactions.ts`; the API calls are replaced by generated functions; the localization transform becomes a local helper in JournalPage.

**Files:**
- Create: `admin-frontend/src/utils/transactions.ts`
- Modify: `admin-frontend/src/pages/JournalPage.tsx`
- Modify: `admin-frontend/src/components/modals/TransactionModal.tsx`
- Modify: `admin-frontend/src/components/modals/SettlementConfirmModal.tsx`

- [ ] **Step 1: Create `src/utils/transactions.ts`**

```ts
/**
 * Transaction display utilities.
 * Extracted from services/transactions.ts.
 */

export function formatTransactionType(type: 'purchase' | 'correction'): string {
  const labels: Record<'purchase' | 'correction', string> = {
    purchase: 'Purchase',
    correction: 'Correction',
  }
  return labels[type] || type
}

export function getTransactionTypeColor(
  type: 'purchase' | 'correction'
): { bg: string; text: string } {
  const colors: Record<'purchase' | 'correction', { bg: string; text: string }> = {
    purchase: { bg: 'rgba(59, 130, 246, 0.1)', text: '#3b82f6' },
    correction: { bg: 'rgba(251, 146, 60, 0.1)', text: '#f97316' },
  }
  return colors[type] || { bg: 'rgba(107, 114, 128, 0.1)', text: '#64748b' }
}

export function getAmountColor(amountCents: number): string {
  if (amountCents > 0) return 'text-red-600'
  if (amountCents < 0) return 'text-green-600'
  return 'text-gray-600'
}
```

- [ ] **Step 2: Update `JournalPage.tsx` imports**

Replace:
```ts
import { onLoadingStateChange } from '../services/api'
import {
  getTransactions,
  getTransactionTypeColor,
  getAmountColor,
  createCorrection,
  type GlobalTransaction
} from '../services/transactions'
import {
  createSettlement,
  createSettlementByFilters,
  getSettlementFilterPreview,
  type SettlementFilterPreview,
} from '../services/settlements'
import { getMembers, type Member } from '../services/members'
```

With:
```ts
import { onLoadingStateChange } from '../api/client'
import { listTransactions, createManualTransaction } from '../api/generated/transactions'
import { createSettlement, createSettlementByFilters, previewSettlementByFilters } from '../api/generated/settlements'
import { listMembers } from '../api/generated/members'
import { getTransactionTypeColor, getAmountColor } from '../utils/transactions'
import type { GlobalTransaction, SettlementFilterPreview, Member } from '../api/generated/schemas'
```

> **Note:** Check exact generated function names by reading `src/api/generated/transactions.ts`, `settlements.ts`, `members.ts` after generation. Orval uses the `operationId` from the OAS spec. Adjust import names to match.

- [ ] **Step 3: Add `product_names` localization transform in JournalPage**

The current `getTransactions` in `services/transactions.ts` parses `product_names` JSON string (a backend implementation detail) to extract the localized product name. After migration this becomes a local helper in JournalPage:

```ts
import { getCurrentLanguage } from '../i18n/config'
import { getLocalizedName } from '../utils/i18n-helpers'

function localizeTransactionItems(items: GlobalTransaction[]): GlobalTransaction[] {
  const lang = getCurrentLanguage()
  return items.map(item => {
    const raw = item as any
    let product_name: string | null = item.product_name ?? null
    if (raw.product_names && typeof raw.product_names === 'string') {
      try {
        product_name = getLocalizedName(JSON.parse(raw.product_names), lang)
      } catch {
        // ignore
      }
    }
    return { ...item, product_name, is_settled: !!item.settlement_date }
  })
}
```

Call `localizeTransactionItems()` on the response items after fetching.

- [ ] **Step 4: Update `TransactionModal.tsx` imports**

Replace:
```ts
import { getMemberTransactionHistory, MemberTransactionHistory } from '../../services/transactions'
```

With:
```ts
import { getMemberTransactions } from '../../api/generated/transactions'
import type { MemberTransactionHistory } from '../../api/generated/schemas'
```

Adjust the call to `getMemberTransactions` to use the generated function signature.

- [ ] **Step 5: Update `SettlementConfirmModal.tsx` imports**

Replace:
```ts
import type { GlobalTransaction } from '../../services/transactions'
```

With:
```ts
import type { GlobalTransaction } from '../../api/generated/schemas'
```

- [ ] **Step 6: Type-check**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | grep -E "JournalPage|TransactionModal|SettlementConfirmModal" | head -20
```

Expected: no errors.

- [ ] **Step 7: Run journal E2E tests**

```bash
cd e2etests && npm test -- tests/admin/journal-and-settlements.spec.ts --workers=4
```

Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add admin-frontend/src/utils/transactions.ts \
        admin-frontend/src/pages/JournalPage.tsx \
        admin-frontend/src/components/modals/TransactionModal.tsx \
        admin-frontend/src/components/modals/SettlementConfirmModal.tsx
git commit -m "feat(journal): migrate JournalPage and transaction modals to generated client"
```

---

### Task 8: Migrate `MembersPage.tsx`

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx`

- [ ] **Step 1: Update imports**

Replace:
```ts
import { getMembers, createMember, updateMember, exportMemberData, anonymizeMember, Member } from '../services/members'
import { getDashboardMetrics } from '../services/dashboard'
```

With:
```ts
import { listMembers, createMember, updateMember, exportMemberData, anonymizeMember } from '../api/generated/members'
import { getDashboardMetrics } from '../api/generated/dashboard'
import type { Member } from '../api/generated/schemas'
```

> **Note:** Check exact generated function names in `src/api/generated/members.ts` and `reports.ts`.

- [ ] **Step 2: Remove all `as any` casts and response-sniffing code**

The generated functions return the correct type directly — no `if ('items' in response)` checks needed. Remove any such normalization.

- [ ] **Step 3: Type-check**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | grep "MembersPage" | head -10
```

- [ ] **Step 4: Run members E2E tests**

```bash
cd e2etests && npm test -- tests/admin/members.spec.ts tests/admin/members-stats.spec.ts tests/admin/members-gdpr-export.spec.ts tests/admin/members-anonymize.spec.ts --workers=4
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git commit -m "feat(members): migrate MembersPage to generated API client"
```

---

### Task 9: Migrate `ProductsPage.tsx` and `CategoriesPage.tsx`

These two pages import `get/post/patch/del` directly from `services/api` (no service file intermediary). They get replaced with generated functions.

**Files:**
- Modify: `admin-frontend/src/pages/ProductsPage.tsx`
- Modify: `admin-frontend/src/pages/CategoriesPage.tsx`

- [ ] **Step 1: Update `ProductsPage.tsx` imports**

Replace:
```ts
import { get, post, patch, del, onLoadingStateChange } from '../services/api'
```

With:
```ts
import { onLoadingStateChange } from '../api/client'
import { listProducts, createProduct, updateProduct } from '../api/generated/products'
import type { Product, Category } from '../api/generated/schemas'
```

> **Note:** Check generated function names in `src/api/generated/products.ts`.

- [ ] **Step 2: Replace direct `get/post/patch/del` calls in ProductsPage with generated functions**

The page makes inline API calls like `await get('/admin/products', { params })`. Replace each with the appropriate generated function. Remove all `as any` casts. Note: product deletion likely uses `updateProduct` with `is_active: false` (deactivation), not a separate `deleteProduct` — verify against the generated file.

- [ ] **Step 3: Update `CategoriesPage.tsx` imports**

Replace:
```ts
import { get, post, patch, del } from '../services/api'
```

With:
```ts
import { listCategories, createCategory, updateCategory, deleteCategory } from '../api/generated/products'
import type { Category } from '../api/generated/schemas'
```

- [ ] **Step 4: Replace direct API calls in CategoriesPage**

Same pattern — replace inline `get/post/patch/del` calls with generated functions.

- [ ] **Step 5: Type-check**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | grep -E "ProductsPage|CategoriesPage" | head -20
```

- [ ] **Step 6: Run products and categories E2E tests**

```bash
cd e2etests && npm test -- tests/admin/products.spec.ts tests/admin/categories.spec.ts --workers=4
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add admin-frontend/src/pages/ProductsPage.tsx admin-frontend/src/pages/CategoriesPage.tsx
git commit -m "feat(products): migrate ProductsPage and CategoriesPage to generated client"
```

---

### Task 10: Migrate `SettlementsPage.tsx`

**Files:**
- Modify: `admin-frontend/src/pages/SettlementsPage.tsx`

- [ ] **Step 1: Update imports**

Replace:
```ts
import { downloadFile } from '../services/api'
```

With:
```ts
import { downloadFile } from '../api/client'
```

Update any other imports from `services/settlements` to use generated equivalents from `../api/generated/settlements`.

- [ ] **Step 2: Type-check**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | grep "SettlementsPage" | head -10
```

- [ ] **Step 3: Run settlements E2E tests**

```bash
cd e2etests && npm test -- tests/admin/journal-and-settlements.spec.ts --workers=4
```

- [ ] **Step 4: Commit**

```bash
git add admin-frontend/src/pages/SettlementsPage.tsx
git commit -m "feat(settlements): migrate SettlementsPage to generated client"
```

---

### Task 11: Migrate `SettingsPage.tsx`

SettingsPage handles SEPA config, admin users, and terminals — three domains in one file.

**Files:**
- Modify: `admin-frontend/src/pages/SettingsPage.tsx`

- [ ] **Step 1: Update imports**

Replace:
```ts
import { getSepaConfig, updateSepaConfig } from '../services/sepa-config'
import { getAdminUsers, createAdminUser, updateAdminUser, deactivateAdminUser, reactivateAdminUser, resetAdminPassword } from '../services/admin-users'
import { getTerminals, createTerminal, updateTerminal, rotateTerminalToken, revokeTerminalAccess } from '../services/terminals'
```

With:
```ts
import { getSepaConfig, updateSepaConfig } from '../api/generated/sepaConfiguration'
import { listAdminUsers, createAdminUser, updateAdminUser, resetAdminPassword } from '../api/generated/adminUsers'
import { listTerminals, createTerminal, updateTerminal, rotateTerminalToken, revokeTerminalAccess } from '../api/generated/terminals'
// Note: deactivateAdminUser/reactivateAdminUser do not exist as separate endpoints.
// Use: updateAdminUser(id, { is_active: false }) / updateAdminUser(id, { is_active: true })
import type { SepaConfig, AdminUser, Terminal } from '../api/generated/schemas'
```

> **Note:** Check exact generated function/type names.

- [ ] **Step 2: Remove `as any` casts and response-sniffing**

- [ ] **Step 3: Type-check**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | grep "SettingsPage" | head -10
```

- [ ] **Step 4: Run settings E2E tests**

```bash
cd e2etests && npm test -- tests/admin/settings-admin-users.spec.ts tests/admin/settings-terminals.spec.ts tests/admin/settings-sepa-config.spec.ts --workers=4
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add admin-frontend/src/pages/SettingsPage.tsx
git commit -m "feat(settings): migrate SettingsPage to generated client"
```

---

### Task 12: Migrate remaining pages — AuditLogPage and DashboardPage

**Files:**
- Modify: `admin-frontend/src/pages/AuditLogPage.tsx`
- Modify: `admin-frontend/src/pages/DashboardPage.tsx`

- [ ] **Step 1: Update `AuditLogPage.tsx` imports**

Replace:
```ts
import { getAuditLogs, getAvailableActions, getAvailableEntityTypes, AuditLogEntry } from '../services/audit-log'
```

With:
```ts
import { listAuditLog } from '../api/generated/auditLog'
import type { AuditLogEntry } from '../api/generated/schemas'
```

Note: `getAvailableActions()` and `getAvailableEntityTypes()` in `services/audit-log.ts` are **client-side helper functions that return hardcoded arrays** — they are not API calls. Move them inline into `AuditLogPage.tsx` as constants rather than importing from a generated file.

- [ ] **Step 2: Update `DashboardPage.tsx` imports**

Replace:
```ts
import { getDashboardMetrics, DashboardResponse } from '../services/dashboard'
```

With:
```ts
import { getDashboardMetrics } from '../api/generated/dashboard'
import type { DashboardMetrics } from '../api/generated/schemas'
```

Note: `getDashboardMetrics` is tagged `Dashboard` in the OAS (not `Reports`), so orval generates it in `dashboard.ts`, not `reports.ts`.

- [ ] **Step 3: Type-check**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | grep -E "AuditLogPage|DashboardPage" | head -10
```

- [ ] **Step 4: Run E2E tests**

```bash
cd e2etests && npm test -- tests/admin/audit-log.spec.ts tests/admin/audit-log-e2e.spec.ts tests/admin/dashboard.spec.ts --workers=4
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add admin-frontend/src/pages/AuditLogPage.tsx admin-frontend/src/pages/DashboardPage.tsx
git commit -m "feat(audit-log): migrate AuditLogPage and DashboardPage to generated client"
```

---

## Chunk 4: Cleanup

### Task 13: Delete all old service files and types

Do this only when `npx tsc --noEmit` reports zero errors that reference the old files.

**Files:**
- Delete: `admin-frontend/src/types/index.ts`
- Delete: `admin-frontend/src/services/api.ts`
- Delete: `admin-frontend/src/services/auth.ts`
- Delete: `admin-frontend/src/services/members.ts`
- Delete: `admin-frontend/src/services/transactions.ts`
- Delete: `admin-frontend/src/services/settlements.ts`
- Delete: `admin-frontend/src/services/admin-users.ts`
- Delete: `admin-frontend/src/services/audit-log.ts`
- Delete: `admin-frontend/src/services/reports.ts`
- Delete: `admin-frontend/src/services/sepa-config.ts`
- Delete: `admin-frontend/src/services/terminals.ts`
- Delete: `admin-frontend/src/services/dashboard.ts`

- [ ] **Step 1: Verify nothing still imports the old files**

```bash
cd admin-frontend
grep -rE "from ['\"](\.\./)*services/(api|auth|members|transactions|settlements|admin-users|audit-log|reports|sepa-config|terminals|dashboard)['\"]" src/
grep -rE "from ['\"](\.\./)*types(\/index)?['\"]" src/ | grep -v "generated"
```

Expected: **no output** from either command — if anything is printed, go back and migrate that file before continuing.

- [ ] **Step 3: Delete old files**

```bash
cd admin-frontend
rm src/types/index.ts
rm src/services/api.ts src/services/auth.ts src/services/members.ts
rm src/services/transactions.ts src/services/settlements.ts
rm src/services/admin-users.ts src/services/audit-log.ts
rm src/services/reports.ts src/services/sepa-config.ts
rm src/services/terminals.ts src/services/dashboard.ts
```

- [ ] **Step 4: Full type-check — zero errors**

```bash
cd admin-frontend && npx tsc --noEmit
```

Expected: **zero errors**.

- [ ] **Step 5: Full E2E suite — all tests pass**

```bash
cd e2etests && npm test -- --workers=4
```

Expected: all tests pass (same count as before migration).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(cleanup): delete all hand-written service files and types/index.ts"
```

---

### Task 14: Update `technologies.md` and commit generated files

**Files:**
- Modify: `admin-frontend/technologies.md`

- [ ] **Step 1: Update the architecture layers table in `technologies.md`**

Change:

```
| **API Types** | Generated from OpenAPI |
| **API Client** | Axios instance with Auth header |
```

To:

```
| **API Types** | Generated from `api/admin.yaml` via orval |
| **API Client** | orval-generated functions + Axios custom mutator (`src/api/client.ts`) |
| **Session** | `src/auth/session.ts` — localStorage side effects around generated auth functions |
```

- [ ] **Step 2: Update the "API Types from OpenAPI" code block**

Change the command example from `npx openapi-typescript` to:

```bash
# Regenerate after changing api/admin.yaml
cd admin-frontend && npm run generate
```

- [ ] **Step 3: Verify the `src/services/` directory is empty and remove it**

```bash
ls admin-frontend/src/services/
```

Expected: empty or no such directory. If empty:
```bash
rmdir admin-frontend/src/services
```

- [ ] **Step 4: Final full E2E run**

```bash
cd e2etests && npm test -- --workers=4
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add admin-frontend/technologies.md
git commit -m "docs: update technologies.md to reflect generated API client architecture"
```
