# TOTP + Session Bug Fixes

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix two bugs: `SESSION_MAX_AGE` is configured but never applied to PHP's session engine; and the frontend TOTP enrollment flow sends new admins to the dashboard instead of showing the QR-code setup screen.

**Architecture:**
- Bug 1 (backend): Apply `SESSION_MAX_AGE` and cookie settings via `ini_set` + `session_set_cookie_params` in `bootstrap.php`, before any `session_start()` fires. Both `session.gc_maxlifetime` (server GC) and the cookie `lifetime` (client expiry) must be set.
- Bug 2 (frontend): `loginWithSession()` falls through to the success path when the backend returns `{ requiresTotpSetup: true }`, because it only checks `requiresMfa`. Fix the detection, store the CSRF token, thread `requiresTotpSetup` through context, and render a QR-code enrollment screen in `LoginPage`.

**Tech Stack:** PHP 8.3, React 18, TypeScript, i18next, Playwright.

---

## Bug 1 — SESSION_MAX_AGE not enforced

### Files

| Action | File |
|--------|------|
| Modify | `backend/bootstrap.php` |
| Modify | `e2etests/tests/api/admin-auth.spec.ts` |

---

### Milestone 1.1 — Apply session settings in bootstrap

- [ ] **1.1.1** In `backend/bootstrap.php`, add session configuration immediately after `$config` is constructed (before `$pdo` and the rest). Insert:

```php
// Configure session lifetime and cookie parameters globally before any session_start()
ini_set('session.gc_maxlifetime', (string) $config->sessionMaxAge);
session_set_cookie_params([
    'lifetime' => $config->sessionMaxAge,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
```

- [ ] **1.1.2** Syntax-check:

```bash
php -l backend/bootstrap.php
# Expected: No syntax errors detected
```

- [ ] **1.1.3** Restart PHP so the change takes effect:

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
```

- [ ] **1.1.4** Commit:

```bash
git add backend/bootstrap.php
git commit -m "fix(session): apply SESSION_MAX_AGE to PHP session gc_maxlifetime and cookie lifetime"
```

---

### Milestone 1.2 — E2E test: Set-Cookie carries Max-Age

Add one test to the existing admin-auth spec that verifies the session cookie returned by login carries `Max-Age=7200`.

- [ ] **1.2.1** Open `e2etests/tests/api/admin-auth.spec.ts` and add at the end of the `describe` block:

```typescript
test('login Set-Cookie includes Max-Age matching SESSION_MAX_AGE', async ({ playwright }) => {
  const ctx = await playwright.request.newContext()
  try {
    const resp = await ctx.post(`${API_BASE}/auth/login`, {
      data: {
        email: TEST_CREDENTIALS.admin.email,
        password: TEST_CREDENTIALS.admin.password,
      },
    })
    // Admin is TOTP-enrolled, so we get requiresMfa — but the cookie is still set
    const setCookie = resp.headers()['set-cookie'] ?? ''
    expect(setCookie).toMatch(/Max-Age=7200/i)
  } finally {
    await ctx.dispose()
  }
})
```

- [ ] **1.2.2** Run the new test:

```bash
cd e2etests && npm test -- --grep "Set-Cookie includes Max-Age" --workers=1
# Expected: 1 passed
```

- [ ] **1.2.3** Commit:

```bash
git add e2etests/tests/api/admin-auth.spec.ts
git commit -m "test(session): verify Set-Cookie Max-Age matches SESSION_MAX_AGE"
```

---

## Bug 2 — TOTP enrollment flow broken

### Root cause

When an unenrolled admin logs in, the backend returns:
```json
{ "requiresTotpSetup": true, "admin": { "id": "...", ... }, "csrf_token": "..." }
```

`loginWithSession()` checks for `r.requiresMfa` but not `r.requiresTotpSetup`, so it falls through to the success path, writes `admin_id` to localStorage, returns `success: true`, and `LoginPage` navigates to `/dashboard`. The dashboard's API calls hit the 403 `totp_setup_required` gate and show "Ein Fehler ist aufgetreten".

### Enrollment data flow

After the login response with `requiresTotpSetup`:
- The server session already has `admin_user_id` set, so setup/confirm endpoints will work.
- The `csrf_token` in the response must be stored immediately (needed for `POST /api/auth/2fa/setup` and `POST /api/auth/2fa/confirm`).
- The admin data must **not** be stored to `localStorage` yet (that would make `isAuthenticated()` return true).
- After `confirm`, store the admin data and set `isAuthenticated: true`.

### Files

| Action | File |
|--------|------|
| Modify | `admin-frontend/src/auth/session.ts` |
| Modify | `admin-frontend/src/context/AuthContext.tsx` |
| Modify | `admin-frontend/src/pages/LoginPage.tsx` |
| Modify | `admin-frontend/public/locales/en.json` |
| Modify | `admin-frontend/public/locales/de.json` |

---

### Milestone 2.1 — session.ts: detect requiresTotpSetup + helpers

- [ ] **2.1.1** In `admin-frontend/src/auth/session.ts`, extend `LoginSessionResult`:

```typescript
export interface LoginSessionResult {
  success: boolean
  requiresMfa?: boolean
  requiresTotpSetup?: boolean
  message: string
  data?: {
    admin_id: string
    email: string
    display_name: string
    locale: string
  }
}
```

- [ ] **2.1.2** In `loginWithSession()`, add a `requiresTotpSetup` branch **before** the `admin` access, immediately after the `requiresMfa` check:

```typescript
if (r.requiresTotpSetup) {
  // Store CSRF token so the setup/confirm endpoints can be called
  setCsrfToken(r.csrf_token)
  return {
    success: false,
    requiresTotpSetup: true,
    message: '',
    data: {
      admin_id: r.admin.id,
      email: r.admin.email,
      display_name: r.admin.display_name,
      locale: r.admin.locale,
    },
  }
}
```

Note: `data` is carried here so the context can store it in memory (not localStorage) for use after enrollment confirms.

- [ ] **2.1.3** Add `setupTotpWithSession` and `confirmTotpWithSession` after the existing `submitMfaWithSession` function:

```typescript
// ─── TOTP enrollment ──────────────────────────────────────────────────────────

/** Fetch QR code + secret to display to the user during first-time 2FA setup. */
export async function setupTotpWithSession(): Promise<{ qrCode: string; secret: string }> {
  const resp = await axiosInstance.post('/auth/2fa/setup')
  return resp.data as { qrCode: string; secret: string }
}

/**
 * Confirm enrollment with the first TOTP code.
 * On success, writes admin data to localStorage and sets the language.
 */
export async function confirmTotpWithSession(
  code: string,
  adminData: { admin_id: string; email: string; display_name: string; locale: string }
): Promise<{ success: boolean; message: string }> {
  try {
    await axiosInstance.post('/auth/2fa/confirm', { code })

    localStorage.setItem('admin_id', adminData.admin_id)
    localStorage.setItem('email', adminData.email)
    localStorage.setItem('display_name', adminData.display_name)
    localStorage.setItem('locale', adminData.locale)
    changeLanguage(adminData.locale)

    return { success: true, message: '' }
  } catch (error: unknown) {
    const message = axios.isAxiosError(error)
      ? (error.response?.data?.message as string | undefined) ?? 'Invalid code'
      : 'Invalid code'
    return { success: false, message }
  }
}
```

- [ ] **2.1.4** TypeScript check:

```bash
cd admin-frontend && npx tsc --noEmit
# Expected: no output (zero errors)
```

- [ ] **2.1.5** Commit:

```bash
git add admin-frontend/src/auth/session.ts
git commit -m "fix(2fa): detect requiresTotpSetup in loginWithSession, add setup/confirm helpers"
```

---

### Milestone 2.2 — AuthContext: requiresTotpSetup state + methods

- [ ] **2.2.1** In `admin-frontend/src/context/AuthContext.tsx`, import the new helpers:

```typescript
import {
  loginWithSession,
  submitMfaWithSession,
  setupTotpWithSession,
  confirmTotpWithSession,
  logoutWithSession,
  getCurrentSession,
  isAuthenticated,
} from '../auth/session'
```

- [ ] **2.2.2** Extend `AuthState` and `AuthContextType`:

```typescript
interface AuthState {
  isAuthenticated: boolean
  requiresMfa?: boolean
  requiresTotpSetup?: boolean
  pendingAdmin?: { admin_id: string; email: string; display_name: string; locale: string }
  adminId?: string
  email?: string
  displayName?: string
  locale?: string
}

interface AuthContextType extends AuthState {
  login: (credentials: LoginCredentials) => Promise<boolean>
  submitMfa: (code: string) => Promise<boolean>
  setupTotp: () => Promise<{ qrCode: string; secret: string }>
  confirmTotp: (code: string) => Promise<boolean>
  logout: () => Promise<void>
  loading: boolean
  error?: string
}
```

- [ ] **2.2.3** In `handleLogin`, add the `requiresTotpSetup` branch after the `requiresMfa` branch:

```typescript
if (response.requiresTotpSetup) {
  setAuth(prev => ({
    ...prev,
    requiresTotpSetup: true,
    pendingAdmin: response.data,
  }))
  return false
}
```

- [ ] **2.2.4** Add `handleSetupTotp` and `handleConfirmTotp` functions (add before `handleLogout`):

```typescript
const handleSetupTotp = async (): Promise<{ qrCode: string; secret: string }> => {
  return setupTotpWithSession()
}

const handleConfirmTotp = async (code: string): Promise<boolean> => {
  const pendingAdmin = auth.pendingAdmin
  if (!pendingAdmin) return false

  setLoading(true)
  setError(undefined)
  try {
    const result = await confirmTotpWithSession(code, pendingAdmin)
    if (result.success) {
      setAuth({
        isAuthenticated: true,
        requiresTotpSetup: false,
        pendingAdmin: undefined,
        adminId: pendingAdmin.admin_id,
        email: pendingAdmin.email,
        displayName: pendingAdmin.display_name,
        locale: pendingAdmin.locale,
      })
      return true
    }
    setError(result.message)
    return false
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Invalid code'
    setError(message)
    return false
  } finally {
    setLoading(false)
  }
}
```

- [ ] **2.2.5** Wire into the context value:

```typescript
const value: AuthContextType = {
  ...auth,
  login: handleLogin,
  submitMfa: handleSubmitMfa,
  setupTotp: handleSetupTotp,
  confirmTotp: handleConfirmTotp,
  logout: handleLogout,
  loading,
  error,
}
```

- [ ] **2.2.6** TypeScript check:

```bash
cd admin-frontend && npx tsc --noEmit
# Expected: no output
```

- [ ] **2.2.7** Commit:

```bash
git add admin-frontend/src/context/AuthContext.tsx
git commit -m "fix(2fa): add requiresTotpSetup state, setupTotp and confirmTotp to AuthContext"
```

---

### Milestone 2.3 — LoginPage: enrollment UI

When `requiresTotpSetup` is true, render a two-part enrollment screen:
1. QR code (auto-fetched on mount) + secret text fallback
2. Code entry + "Enable 2FA" button

- [ ] **2.3.1** Replace the contents of `admin-frontend/src/pages/LoginPage.tsx` with:

```typescript
/**
 * Login Page
 * Handles email/password login, MFA verification, and first-time TOTP enrollment.
 */

import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { LoginForm } from '../components/forms/LoginForm'
import { useAuth } from '../context/AuthContext'
import { Card } from '../components/common/Card'
import { Input } from '../components/common/Input'
import { Button } from '../components/common/Button'
import { theme } from '../styles/design-system'

// ─── Shared card wrapper (logo + title) ──────────────────────────────────────

function AuthCard({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) {
  return (
    <div
      style={{
        display: 'flex',
        justifyContent: 'center',
        alignItems: 'center',
        minHeight: '100vh',
        background: theme.colors.bg.primary,
        padding: theme.spacing.lg,
      }}
    >
      <Card style={{ width: '100%', maxWidth: '400px' }}>
        <div style={{ textAlign: 'center', marginBottom: theme.spacing['2xl'] }}>
          <div style={{ display: 'flex', justifyContent: 'center', marginBottom: theme.spacing.md }}>
            <img src="/logo.svg" alt="Club Bar Logo" style={{ width: '120px', height: '120px' }} />
          </div>
          <h1
            style={{
              fontSize: theme.typography.fontSize['2xl'],
              fontWeight: theme.typography.fontWeight.bold,
              margin: 0,
              marginBottom: theme.spacing.sm,
              color: theme.colors.text.primary,
            }}
          >
            {title}
          </h1>
          <p style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.secondary, margin: 0 }}>
            {subtitle}
          </p>
        </div>
        {children}
      </Card>
    </div>
  )
}

function ErrorBanner({ message }: { message: string }) {
  return (
    <div
      style={{
        background: `${theme.colors.semantic.danger}20`,
        border: `1px solid ${theme.colors.semantic.danger}`,
        borderRadius: theme.borderRadius.md,
        padding: theme.spacing.md,
        fontSize: theme.typography.fontSize.sm,
        color: theme.colors.semantic.danger,
        marginBottom: theme.spacing.lg,
      }}
    >
      {message}
    </div>
  )
}

// ─── MFA verification step ────────────────────────────────────────────────────

function MfaStep() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { submitMfa, loading, error } = useAuth()
  const [code, setCode] = useState('')
  const [localError, setLocalError] = useState<string>()

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLocalError(undefined)
    const success = await submitMfa(code)
    if (success) {
      navigate('/dashboard')
    } else {
      setLocalError(error || t('auth.mfaInvalidCode'))
    }
  }

  return (
    <AuthCard title={t('auth.mfaTitle')} subtitle={t('auth.mfaInstruction')}>
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
        {localError && <ErrorBanner message={localError} />}
        <Input
          data-testid="mfa-code-input"
          label={t('auth.mfaCode')}
          type="text"
          inputMode="numeric"
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
          placeholder="000000"
          disabled={loading}
          autoFocus
        />
        <Button
          type="submit"
          disabled={loading || code.length !== 6}
          loading={loading}
          style={{ width: '100%' }}
          data-testid="mfa-submit-button"
        >
          {loading ? t('auth.mfaSubmitting') : t('auth.mfaSubmit')}
        </Button>
      </form>
    </AuthCard>
  )
}

// ─── TOTP enrollment step ─────────────────────────────────────────────────────

function TotpSetupStep() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { setupTotp, confirmTotp, loading, error } = useAuth()
  const [qrCode, setQrCode] = useState<string>()
  const [secret, setSecret] = useState<string>()
  const [code, setCode] = useState('')
  const [localError, setLocalError] = useState<string>()
  const [fetchError, setFetchError] = useState<string>()

  useEffect(() => {
    setupTotp()
      .then(({ qrCode, secret }) => {
        setQrCode(qrCode)
        setSecret(secret)
      })
      .catch(() => setFetchError(t('auth.setupFetchError')))
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLocalError(undefined)
    const success = await confirmTotp(code)
    if (success) {
      navigate('/dashboard')
    } else {
      setLocalError(error || t('auth.mfaInvalidCode'))
    }
  }

  return (
    <AuthCard title={t('auth.setupTitle')} subtitle={t('auth.setupInstruction')}>
      {fetchError && <ErrorBanner message={fetchError} />}

      {qrCode && (
        <div style={{ display: 'flex', justifyContent: 'center', marginBottom: theme.spacing.lg }}>
          <img
            src={qrCode}
            alt="TOTP QR Code"
            data-testid="totp-qr-code"
            style={{ width: '200px', height: '200px' }}
          />
        </div>
      )}

      {secret && (
        <p
          style={{
            textAlign: 'center',
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.secondary,
            wordBreak: 'break-all',
            marginBottom: theme.spacing.lg,
          }}
        >
          {t('auth.setupManualKey')}: <strong>{secret}</strong>
        </p>
      )}

      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
        {localError && <ErrorBanner message={localError} />}
        <Input
          data-testid="setup-code-input"
          label={t('auth.setupCodeLabel')}
          type="text"
          inputMode="numeric"
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
          placeholder="000000"
          disabled={loading || !qrCode}
          autoFocus={!!qrCode}
        />
        <Button
          type="submit"
          disabled={loading || code.length !== 6 || !qrCode}
          loading={loading}
          style={{ width: '100%' }}
          data-testid="setup-confirm-button"
        >
          {loading ? t('auth.setupConfirming') : t('auth.setupConfirm')}
        </Button>
      </form>
    </AuthCard>
  )
}

// ─── Login Page ───────────────────────────────────────────────────────────────

export function LoginPage() {
  const navigate = useNavigate()
  const { t } = useTranslation()
  const { login, requiresMfa, requiresTotpSetup, loading, error } = useAuth()
  const [localError, setLocalError] = useState<string>()

  if (requiresMfa) return <MfaStep />
  if (requiresTotpSetup) return <TotpSetupStep />

  const handleSubmit = async (email: string, password: string) => {
    setLocalError(undefined)
    const success = await login({ email, password })
    if (success) {
      navigate('/dashboard')
    } else if (!requiresMfa && !requiresTotpSetup) {
      setLocalError(error || t('auth.loginFailed'))
    }
  }

  return <LoginForm onSubmit={handleSubmit} loading={loading} error={localError} />
}
```

- [ ] **2.3.2** TypeScript check:

```bash
cd admin-frontend && npx tsc --noEmit
# Expected: no output
```

- [ ] **2.3.3** Commit:

```bash
git add admin-frontend/src/pages/LoginPage.tsx
git commit -m "fix(2fa): render TOTP enrollment QR screen for unenrolled admins on first login"
```

---

### Milestone 2.4 — i18n strings

- [ ] **2.4.1** Add to `admin-frontend/public/locales/en.json` inside the `"auth"` block:

```json
"setupTitle": "Set Up Two-Factor Authentication",
"setupInstruction": "Scan the QR code with your authenticator app (Google Authenticator, Authy, etc.), then enter the 6-digit code to confirm.",
"setupManualKey": "Manual entry key",
"setupCodeLabel": "Confirmation Code",
"setupConfirm": "Enable 2FA",
"setupConfirming": "Enabling...",
"setupFetchError": "Could not load QR code. Please refresh and try again."
```

- [ ] **2.4.2** Add to `admin-frontend/public/locales/de.json` inside the `"auth"` block:

```json
"setupTitle": "Zwei-Faktor-Authentifizierung einrichten",
"setupInstruction": "Scannen Sie den QR-Code mit Ihrer Authenticator-App (Google Authenticator, Authy etc.) und geben Sie dann den 6-stelligen Code zur Bestätigung ein.",
"setupManualKey": "Manueller Schlüssel",
"setupCodeLabel": "Bestätigungscode",
"setupConfirm": "2FA aktivieren",
"setupConfirming": "Wird aktiviert...",
"setupFetchError": "QR-Code konnte nicht geladen werden. Bitte Seite neu laden."
```

- [ ] **2.4.3** Commit:

```bash
git add admin-frontend/public/locales/en.json admin-frontend/public/locales/de.json
git commit -m "feat(i18n): add TOTP enrollment UI strings"
```

---

### Milestone 2.5 — Manual verification

> No automated browser UI test exists yet. Verify manually.

- [ ] **2.5.1** Build the frontend so changes are visible on port 8080:

```bash
cd admin-frontend && npm run build
```

- [ ] **2.5.2** Create a fresh admin user via the API (to test the enrollment flow):

```bash
# Get a CSRF token from an existing session first
curl -s -c /tmp/cookies.txt -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"password123"}' | jq .

# Note the mfa step - use TOTP code from authenticator app, or:
CODE=$(cd e2etests && npx tsx -e "import {generateTotp} from './utils/totp'; console.log(generateTotp('JBSWY3DPEHPK3PXP'))")
CSRF=$(curl -s -b /tmp/cookies.txt -c /tmp/cookies.txt -X POST http://localhost:8080/api/auth/mfa \
  -H 'Content-Type: application/json' \
  -d "{\"code\":\"$CODE\"}" | jq -r '.csrf_token')

# Create a new admin user
curl -s -b /tmp/cookies.txt -X POST http://localhost:8080/api/admin/admin-users \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF" \
  -d '{"email":"new@example.com","display_name":"New Admin","locale":"en"}' | jq .
# Note the one-time password in the response
```

- [ ] **2.5.3** Open `http://localhost:8080` (or `http://localhost:5176` for the Vite dev server).

- [ ] **2.5.4** Log in with the new admin's email + temporary password. Verify:
  - [ ] QR code is displayed (not dashboard, not error)
  - [ ] Scanning QR code works in an authenticator app
  - [ ] Entering the 6-digit code and clicking "Enable 2FA" navigates to the dashboard
  - [ ] A second login with the same user now shows the MFA code prompt (not the QR screen)

- [ ] **2.5.5** Run the existing TOTP E2E tests to ensure no regression:

```bash
cd e2etests && npm test -- tests/api/totp-2fa.spec.ts --workers=4
# Expected: 7 passed
```

- [ ] **2.5.6** Run the full suite to check for regressions:

```bash
cd e2etests && npm test -- --workers=4
# Expected: all passed (same count as before)
```

---

## Out of Scope

- `SESSION_REGEN_INTERVAL`: session ID regeneration interval is also configured but not implemented. This is a separate, lower-priority hardening task — it requires a per-request timestamp check in the middleware.
- Automated browser UI E2E test for the enrollment flow: would require Playwright browser mode + page navigation. Track as future work.
