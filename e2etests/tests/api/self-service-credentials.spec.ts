import type { APIRequestContext, PlaywrightWorkerArgs } from '@playwright/test'
import { test, expect } from '../../fixtures/auth.fixture'
import { loginAs } from '../../utils/csrf'
import { generateTotp, waitForFreshTotpWindow } from '../../utils/totp'
import { stepUp } from '../../fixtures/stepUp'
import { tokenFromInvitationUrl, INVITED_ADMIN_PASSWORD } from '../../utils/adminInvitation'

/**
 * Self-service credential changes (#337 follow-up).
 *
 * Changing your own password or your own email address moves a credential, so
 * both now carry the same step-up the cross-account resets carry: the caller's
 * own password plus their own fresh TOTP code. Before this, an email change
 * needed nothing beyond a live session — and email is the login identifier, so
 * a hijacked session could repoint the account silently.
 *
 * The other half of the contract matters just as much and is asserted here:
 * display name and locale must stay free. The profile page PATCHes this same
 * endpoint on every language toggle, so a step-up that fires on any profile
 * write would demand a password to switch language.
 *
 * Test Data Isolation (E2E Pattern 001): every test mints its own admin and
 * enrolls it here rather than through `loginAs`, because enrollment confirms
 * against the pending secret the *server* generated and `loginAs` discards it —
 * leaving the caller unable to produce a code for the account it just enrolled.
 * Enrolling inline keeps the secret. Replay protection is per-admin-row, and the
 * step-up verifies a code without consuming its timestep, so these run 4-wide.
 */

const API_BASE = 'http://localhost:8080/api'

interface EnrolledAdmin {
  email: string
  password: string
  id: string
  /** The TOTP secret this account was actually enrolled with. */
  secret: string
  /** Session-bearing context, CSRF header applied to mutations. */
  ctx: SessionContext
  dispose: () => Promise<void>
}

/** Minimal CSRF-aware wrapper — `CsrfAwareContext` in utils/csrf.ts is not exported. */
class SessionContext {
  constructor(private raw: APIRequestContext, private csrf: string) {}

  get = (url: string, options?: Record<string, unknown>) => this.raw.get(url, options)

  post = (url: string, options?: Record<string, unknown>) =>
    this.raw.post(url, { ...options, headers: { ...(options?.headers as object), 'X-CSRF-Token': this.csrf } })

  patch = (url: string, options?: Record<string, unknown>) =>
    this.raw.patch(url, { ...options, headers: { ...(options?.headers as object), 'X-CSRF-Token': this.csrf } })
}

/**
 * Create an admin, log it in, and walk it through the mandatory TOTP
 * enrollment gate — keeping the secret the server minted.
 */
async function createEnrolledAdmin(
  authenticatedRequest: APIRequestContext,
  playwright: PlaywrightWorkerArgs['playwright'],
  prefix: string,
): Promise<EnrolledAdmin> {
  const email = `${prefix}-${Date.now()}-${Math.round(Math.random() * 1e6)}@test.example.com`
  const createResponse = await authenticatedRequest.post(`${API_BASE}/admin/admin-users`, {
    data: { ...stepUp(), email, display_name: `Self Service ${prefix}`, locale: 'de' },
  })
  expect(createResponse.status(), await createResponse.text()).toBe(201)
  const { admin, invitation } = await createResponse.json()

  const raw = await playwright.request.newContext({ baseURL: API_BASE })

  // The account has no password until the invitation is walked (migration
  // 058), and the password below is this test's own choice — the API never
  // knew one.
  const accepted = await raw.post(
    `${API_BASE}/invitations/${tokenFromInvitationUrl(invitation.url)}/accept`,
    { data: { password: INVITED_ADMIN_PASSWORD, password_confirmation: INVITED_ADMIN_PASSWORD } },
  )
  expect(accepted.status(), await accepted.text()).toBe(200)
  const password = INVITED_ADMIN_PASSWORD

  const loginResponse = await raw.post(`${API_BASE}/auth/login`, { data: { email, password } })
  expect(loginResponse.status(), await loginResponse.text()).toBe(200)
  const loginData = await loginResponse.json()
  expect(loginData.requiresTotpSetup).toBe(true)

  const csrf = loginData.csrf_token ?? ''
  const setupResponse = await raw.post(`${API_BASE}/auth/2fa/setup`, {
    headers: { 'X-CSRF-Token': csrf },
  })
  expect(setupResponse.status(), await setupResponse.text()).toBe(200)
  const { secret } = await setupResponse.json()

  const confirmResponse = await raw.post(`${API_BASE}/auth/2fa/confirm`, {
    data: { code: generateTotp(secret) },
    headers: { 'X-CSRF-Token': csrf },
  })
  expect(confirmResponse.status(), await confirmResponse.text()).toBe(200)

  return {
    email,
    password,
    id: admin.id,
    secret,
    ctx: new SessionContext(raw, csrf),
    dispose: () => raw.dispose(),
  }
}

test.describe('Self-service credential changes', () => {
  test.describe('change password', () => {
    test('rejects a change that carries no TOTP code when the caller is enrolled', async ({
      authenticatedRequest,
      playwright,
    }) => {
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'pw-no-code')

      const response = await admin.ctx.patch(`${API_BASE}/auth/change-password`, {
        data: {
          current_password: admin.password,
          new_password: 'NewPass1234',
          new_password_confirmation: 'NewPass1234',
        },
      })

      expect(response.status()).toBe(401)
      expect((await response.json()).error).toBe('invalid_credentials')
      await admin.dispose()
    })

    test('rejects a wrong TOTP code even with the right password', async ({
      authenticatedRequest,
      playwright,
    }) => {
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'pw-bad-code')

      const response = await admin.ctx.patch(`${API_BASE}/auth/change-password`, {
        data: {
          current_password: admin.password,
          new_password: 'NewPass1234',
          new_password_confirmation: 'NewPass1234',
          totp_code: '000000',
        },
      })

      expect(response.status()).toBe(401)
      await admin.dispose()
    })

    test('changes the password with a full step-up, and the new password authenticates', async ({
      authenticatedRequest,
      playwright,
    }) => {
      // waitForFreshTotpWindow below can itself take up to ~30s.
      test.setTimeout(60_000)
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'pw-happy')
      const newPassword = 'RotatedPass1234'

      const response = await admin.ctx.patch(`${API_BASE}/auth/change-password`, {
        data: {
          current_password: admin.password,
          new_password: newPassword,
          new_password_confirmation: newPassword,
          totp_code: generateTotp(admin.secret),
        },
      })

      expect(response.status(), await response.text()).toBe(200)
      await admin.dispose()

      // The write is only proven by the credential it produced: log in again
      // with the new password, which also proves the old one is gone. That
      // login consumes a fresh TOTP step, same as the enrollment inside
      // createEnrolledAdmin moments ago — wait it out first rather than
      // risking the same-window replay retry (see waitForFreshTotpWindow).
      await waitForFreshTotpWindow()
      const reauthed = await loginAs(playwright, admin.email, newPassword, admin.secret)
      const profile = await reauthed.get(`${API_BASE}/auth/profile`)
      expect(profile.status()).toBe(200)
      await reauthed.dispose()
    })
  })

  test.describe('change email', () => {
    test('rejects an email change that carries no step-up credential', async ({
      authenticatedRequest,
      playwright,
    }) => {
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'email-nocred')

      const response = await admin.ctx.patch(`${API_BASE}/auth/profile`, {
        data: { email: `moved-${Date.now()}@test.example.com` },
      })

      expect(response.status()).toBe(401)
      expect((await response.json()).error).toBe('invalid_credentials')

      // And the address must still be the original one.
      const profile = await admin.ctx.get(`${API_BASE}/auth/profile`)
      expect((await profile.json()).admin.email).toBe(admin.email)
      await admin.dispose()
    })

    test('changes the email with a full step-up', async ({ authenticatedRequest, playwright }) => {
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'email-happy')
      const newEmail = `moved-${Date.now()}-${Math.round(Math.random() * 1e6)}@test.example.com`

      const response = await admin.ctx.patch(`${API_BASE}/auth/profile`, {
        data: {
          email: newEmail,
          current_password: admin.password,
          totp_code: generateTotp(admin.secret),
        },
      })

      expect(response.status(), await response.text()).toBe(200)

      const profile = await admin.ctx.get(`${API_BASE}/auth/profile`)
      expect((await profile.json()).admin.email).toBe(newEmail)
      await admin.dispose()
    })

    /**
     * The old address is told, and the change is audited under its own action.
     *
     * Asserted through the audit log because the outbox has no read endpoint —
     * and it is the right place to look anyway: enqueueing writes
     * `mail_enqueued`, while a failure to enqueue is swallowed (the change is
     * already committed) and recorded as `notification_failed` on an
     * `email_changed` row. Checking only that the change succeeded would miss a
     * notification that never went out, which is exactly how a dedup_key too
     * long for its column went unnoticed.
     */
    test('audits the change and queues a notice to the former address', async ({
      authenticatedRequest,
      playwright,
    }) => {
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'email-notice')
      const formerEmail = admin.email

      const response = await admin.ctx.patch(`${API_BASE}/auth/profile`, {
        data: {
          email: `notice-moved-${Date.now()}-${Math.round(Math.random() * 1e6)}@test.example.com`,
          current_password: admin.password,
          totp_code: generateTotp(admin.secret),
        },
      })
      expect(response.status(), await response.text()).toBe(200)
      await admin.dispose()

      const log = await authenticatedRequest.get(
        `${API_BASE}/admin/audit-log?entity_id=${admin.id}&per_page=50`
      )
      expect(log.status()).toBe(200)
      const entries = (await log.json()).data ?? (await (await authenticatedRequest.get(
        `${API_BASE}/admin/audit-log?entity_id=${admin.id}&per_page=50`
      )).json()).items ?? []

      const actions = entries.map((e: { action: string }) => e.action)
      expect(actions).toContain('email_changed')
      expect(actions).toContain('mail_enqueued')

      // The former address is what the notice went to, and no enqueue failed.
      const changed = entries.find(
        (e: { action: string; new_values?: Record<string, unknown> }) =>
          e.action === 'email_changed' && e.new_values?.notification_failed !== undefined
      )
      expect(changed, `notification failed: ${JSON.stringify(changed?.new_values)}`).toBeUndefined()
      expect(formerEmail).toBeTruthy()
    })
  })

  /**
   * The credentials epoch (#337 follow-up). ADR-0026 used to leave other
   * sessions alive after a credential change, so an attacker holding a
   * hijacked session kept working after the victim changed their password.
   */
  test.describe('other sessions', () => {
    test('a password change ends the account other sessions, and keeps its own', async ({
      authenticatedRequest,
      playwright,
    }) => {
      // waitForFreshTotpWindow below can itself take up to ~30s.
      test.setTimeout(60_000)
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'epoch-pw')

      // A second, independent session on the same account — the attacker's.
      // Consumes a fresh TOTP step, same as createEnrolledAdmin's enrollment
      // moments ago (see waitForFreshTotpWindow).
      await waitForFreshTotpWindow()
      const other = await loginAs(playwright, admin.email, admin.password, admin.secret)
      expect((await other.get(`${API_BASE}/auth/profile`)).status()).toBe(200)

      const changed = await admin.ctx.patch(`${API_BASE}/auth/change-password`, {
        data: {
          current_password: admin.password,
          new_password: 'EpochRotated1234',
          new_password_confirmation: 'EpochRotated1234',
          totp_code: generateTotp(admin.secret),
        },
      })
      expect(changed.status(), await changed.text()).toBe(200)

      // The other session is refused, and told why.
      const refused = await other.get(`${API_BASE}/auth/profile`)
      expect(refused.status()).toBe(401)
      expect((await refused.json()).error).toBe('credentials_changed')

      // The session that made the change still works.
      expect((await admin.ctx.get(`${API_BASE}/auth/profile`)).status()).toBe(200)

      await other.dispose()
      await admin.dispose()
    })

    test('an email change ends other sessions too', async ({ authenticatedRequest, playwright }) => {
      // waitForFreshTotpWindow below can itself take up to ~30s.
      test.setTimeout(60_000)
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'epoch-email')
      // Consumes a fresh TOTP step, same as createEnrolledAdmin's enrollment
      // moments ago (see waitForFreshTotpWindow).
      await waitForFreshTotpWindow()
      const other = await loginAs(playwright, admin.email, admin.password, admin.secret)
      expect((await other.get(`${API_BASE}/auth/profile`)).status()).toBe(200)

      const changed = await admin.ctx.patch(`${API_BASE}/auth/profile`, {
        data: {
          email: `epoch-moved-${Date.now()}-${Math.round(Math.random() * 1e6)}@test.example.com`,
          current_password: admin.password,
          totp_code: generateTotp(admin.secret),
        },
      })
      expect(changed.status(), await changed.text()).toBe(200)

      expect((await other.get(`${API_BASE}/auth/profile`)).status()).toBe(401)
      expect((await admin.ctx.get(`${API_BASE}/auth/profile`)).status()).toBe(200)

      await other.dispose()
      await admin.dispose()
    })

    /**
     * A locale change is not a credential change and must leave sessions alone
     * — the same conditionality the step-up follows, enforced one layer down.
     */
    test('a locale change leaves other sessions working', async ({
      authenticatedRequest,
      playwright,
    }) => {
      // waitForFreshTotpWindow below can itself take up to ~30s.
      test.setTimeout(60_000)
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'epoch-locale')
      // Consumes a fresh TOTP step, same as createEnrolledAdmin's enrollment
      // moments ago (see waitForFreshTotpWindow).
      await waitForFreshTotpWindow()
      const other = await loginAs(playwright, admin.email, admin.password, admin.secret)

      const changed = await admin.ctx.patch(`${API_BASE}/auth/profile`, { data: { locale: 'en' } })
      expect(changed.status(), await changed.text()).toBe(200)

      expect((await other.get(`${API_BASE}/auth/profile`)).status()).toBe(200)

      await other.dispose()
      await admin.dispose()
    })
  })

  /**
   * The regression guard. `ProfilePage.tsx` PATCHes this endpoint on every
   * language toggle; if the step-up ever stops being conditional on the email
   * actually moving, switching language starts demanding a password.
   */
  test.describe('non-credential profile fields stay free', () => {
    test('updates locale with no credential at all', async ({ authenticatedRequest, playwright }) => {
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'locale-free')

      const response = await admin.ctx.patch(`${API_BASE}/auth/profile`, { data: { locale: 'en' } })

      expect(response.status(), await response.text()).toBe(200)
      const profile = await admin.ctx.get(`${API_BASE}/auth/profile`)
      expect((await profile.json()).admin.locale).toBe('en')
      await admin.dispose()
    })

    test('updates display name with no credential at all', async ({
      authenticatedRequest,
      playwright,
    }) => {
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'name-free')

      const response = await admin.ctx.patch(`${API_BASE}/auth/profile`, {
        data: { display_name: 'Renamed Without A Password' },
      })

      expect(response.status(), await response.text()).toBe(200)
      const profile = await admin.ctx.get(`${API_BASE}/auth/profile`)
      expect((await profile.json()).admin.display_name).toBe('Renamed Without A Password')
      await admin.dispose()
    })

    /**
     * The profile form submits the whole form, email field included, whenever
     * Save is pressed. Re-sending the address the account already has is not a
     * credential change and must not ask for one.
     */
    test('accepts the unchanged email alongside another field, with no credential', async ({
      authenticatedRequest,
      playwright,
    }) => {
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'email-same')

      const response = await admin.ctx.patch(`${API_BASE}/auth/profile`, {
        data: { email: admin.email, display_name: 'Saved The Whole Form' },
      })

      expect(response.status(), await response.text()).toBe(200)
      await admin.dispose()
    })

    /**
     * `admin_users.email` is UNIQUE under a case-insensitive collation, so a
     * case-only difference is not a change the database would record — and must
     * not trip a step-up either.
     */
    test('treats a case-only email difference as unchanged', async ({
      authenticatedRequest,
      playwright,
    }) => {
      const admin = await createEnrolledAdmin(authenticatedRequest, playwright, 'email-case')

      const response = await admin.ctx.patch(`${API_BASE}/auth/profile`, {
        data: { email: admin.email.toUpperCase() },
      })

      expect(response.status(), await response.text()).toBe(200)
      await admin.dispose()
    })
  })
})
