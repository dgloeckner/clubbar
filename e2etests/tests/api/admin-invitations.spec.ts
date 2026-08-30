import { test, expect } from '../../fixtures/auth.fixture'
import { stepUp } from '../../fixtures/stepUp'
import { loginAs } from '../../utils/csrf'
import { tokenFromInvitationUrl, INVITED_ADMIN_PASSWORD } from '../../utils/adminInvitation'

const API_BASE = 'http://localhost:8080/api'

/**
 * Admin onboarding by invitation link, end to end through the API
 * (migration 058, UC-A68).
 *
 * What is being protected here is a change in *where a credential lives*.
 * Creating an admin used to mint a password and hand it to whoever pressed the
 * button, who then moved a live credential to their colleague by chat, note or
 * word of mouth. These tests hold the replacement to the properties that make
 * it stronger rather than merely different: nothing usable comes back, the
 * link works once, it dies when replaced, and it can never re-credential an
 * account that already has a password.
 *
 * Serial within this file: several tests perform a full password+MFA login as
 * an account they just created, and `totp_last_timestep` (#338) accepts one
 * code per 30-second step per account — the same reason `admin-users.spec.ts`
 * runs in order (E2E Pattern 004).
 */
test.describe.configure({ mode: 'default' })

/** Unique per test — E2E Pattern 001. */
const uniqueEmail = (label: string) =>
  `invite-${label}-${Date.now()}-${Math.floor(Math.random() * 10000)}@test.example.com`

async function createAdmin(request: any, email: string, roles?: string[]) {
  const response = await request.post(`${API_BASE}/admin/admin-users`, {
    data: {
      ...stepUp(),
      email,
      display_name: 'Invited Admin',
      locale: 'de',
      ...(roles ? { roles } : {}),
    },
  })
  expect(response.status()).toBe(201)
  return response.json()
}

test.describe('Admin Invitations API', () => {
  // ========== CREATING AN ACCOUNT ==========

  test('creating an admin returns an invitation and no password', async ({ authenticatedRequest }) => {
    const email = uniqueEmail('created')
    const body = await createAdmin(authenticatedRequest, email)

    // The whole point of the change: nothing usable comes back to the caller.
    expect(body).not.toHaveProperty('password')
    expect(body.admin.email).toBe(email)

    expect(body.invitation.email).toBe(email)
    expect(body.invitation.url).toContain('/invite/')
    // A week out, so a link sitting in a mailbox does not outlive its reason.
    const expiresIn = new Date(body.invitation.expires_at).getTime() - Date.now()
    expect(expiresIn).toBeGreaterThan(6 * 24 * 3600 * 1000)
    expect(expiresIn).toBeLessThan(8 * 24 * 3600 * 1000)
  })

  test('a brand-new account cannot sign in before its invitation is used', async ({
    authenticatedRequest,
    request,
  }) => {
    const email = uniqueEmail('nologin')
    await createAdmin(authenticatedRequest, email)

    // There is no password to try, which is the property being asserted: the
    // account is written with a hash of random bytes nobody has ever seen, so
    // every guess — including the empty one — is refused.
    for (const attempt of ['', 'password', INVITED_ADMIN_PASSWORD]) {
      const response = await request.post(`${API_BASE}/auth/login`, {
        data: { email, password: attempt },
      })
      expect([401, 422, 429]).toContain(response.status())
    }
  })

  test('the account is marked as waiting for its invitation', async ({ authenticatedRequest }) => {
    const email = uniqueEmail('pending')
    const { admin } = await createAdmin(authenticatedRequest, email)

    const response = await authenticatedRequest.get(`${API_BASE}/admin/admin-users/${admin.id}`)
    expect(response.status()).toBe(200)
    expect((await response.json()).admin.invitation_pending).toBe(true)
  })

  // ========== FOLLOWING THE LINK ==========

  test('the link names the invitee, and nothing about the club', async ({
    authenticatedRequest,
    request,
  }) => {
    const email = uniqueEmail('describe')
    const { invitation } = await createAdmin(authenticatedRequest, email)

    // Deliberately the *unauthenticated* context: this is the endpoint an
    // invitee reaches with no session at all.
    const response = await request.get(
      `${API_BASE}/invitations/${tokenFromInvitationUrl(invitation.url)}`,
    )

    expect(response.status()).toBe(200)
    const body = (await response.json()).invitation
    expect(body.email).toBe(email)
    expect(body.display_name).toBe('Invited Admin')
    // A token proves its holder can read one mailbox. It is not a reason to
    // tell them what the account may do.
    expect(body).not.toHaveProperty('roles')
    expect(body).not.toHaveProperty('id')
  })

  test('setting a password through the link makes the account usable', async ({
    authenticatedRequest,
    request,
    playwright,
  }) => {
    const email = uniqueEmail('accept')
    const { admin, invitation } = await createAdmin(authenticatedRequest, email)

    const accepted = await request.post(
      `${API_BASE}/invitations/${tokenFromInvitationUrl(invitation.url)}/accept`,
      { data: { password: INVITED_ADMIN_PASSWORD, password_confirmation: INVITED_ADMIN_PASSWORD } },
    )
    expect(accepted.status()).toBe(200)
    // The address to sign in with, so the panel need not ask for it again.
    expect((await accepted.json()).email).toBe(email)

    // And it really is the password now — a fresh, unauthenticated login.
    const context = await loginAs(playwright, email, INVITED_ADMIN_PASSWORD)
    const profile = await context.get(`${API_BASE}/auth/profile`)
    expect(profile.status()).toBe(200)
    await context.dispose()

    // No longer pending: the account has a password its owner chose.
    const detail = await authenticatedRequest.get(`${API_BASE}/admin/admin-users/${admin.id}`)
    expect((await detail.json()).admin.invitation_pending).toBe(false)
  })

  /**
   * The first sign-in still runs the ordinary enrolment gate. Accepting an
   * invitation sets the first factor and nothing else — a mail link must not
   * be able to skip the second.
   */
  test('the first sign-in after accepting demands Authenticator enrolment', async ({
    authenticatedRequest,
    request,
  }) => {
    const email = uniqueEmail('totpgate')
    const { invitation } = await createAdmin(authenticatedRequest, email)

    await request.post(`${API_BASE}/invitations/${tokenFromInvitationUrl(invitation.url)}/accept`, {
      data: { password: INVITED_ADMIN_PASSWORD, password_confirmation: INVITED_ADMIN_PASSWORD },
    })

    const login = await request.post(`${API_BASE}/auth/login`, {
      data: { email, password: INVITED_ADMIN_PASSWORD },
    })

    expect(login.status()).toBe(200)
    expect((await login.json()).requiresTotpSetup).toBe(true)
  })

  test('a link works once', async ({ authenticatedRequest, request }) => {
    const email = uniqueEmail('once')
    const { invitation } = await createAdmin(authenticatedRequest, email)
    const token = tokenFromInvitationUrl(invitation.url)

    const first = await request.post(`${API_BASE}/invitations/${token}/accept`, {
      data: { password: INVITED_ADMIN_PASSWORD, password_confirmation: INVITED_ADMIN_PASSWORD },
    })
    expect(first.status()).toBe(200)

    const second = await request.post(`${API_BASE}/invitations/${token}/accept`, {
      data: { password: 'Different0ne', password_confirmation: 'Different0ne' },
    })
    expect(second.status()).toBe(409)
    expect((await second.json()).reason).toBe('invitation_invalid')
  })

  test('an unknown token is refused, and says nothing about why', async ({ request }) => {
    const response = await request.get(`${API_BASE}/invitations/thistokenneverexisted12345`)

    expect(response.status()).toBe(409)
    const body = await response.json()
    // The same code an expired or spent link gets: distinguishing them for an
    // anonymous caller confirms that a token exists.
    expect(body.reason).toBe('invitation_invalid')
    expect(JSON.stringify(body)).not.toContain('expired')
  })

  test('a password that does not meet the rules is refused', async ({
    authenticatedRequest,
    request,
  }) => {
    const email = uniqueEmail('weak')
    const { invitation } = await createAdmin(authenticatedRequest, email)
    const token = tokenFromInvitationUrl(invitation.url)

    for (const weak of ['short1A', 'alllowercase1', 'ALLUPPERCASE1', 'NoDigitsHere']) {
      const response = await request.post(`${API_BASE}/invitations/${token}/accept`, {
        data: { password: weak, password_confirmation: weak },
      })
      expect(response.status()).toBe(422)
    }

    // A mismatched confirmation, likewise — and none of it spent the link.
    const mismatch = await request.post(`${API_BASE}/invitations/${token}/accept`, {
      data: { password: INVITED_ADMIN_PASSWORD, password_confirmation: 'Something3lse' },
    })
    expect(mismatch.status()).toBe(422)

    const good = await request.post(`${API_BASE}/invitations/${token}/accept`, {
      data: { password: INVITED_ADMIN_PASSWORD, password_confirmation: INVITED_ADMIN_PASSWORD },
    })
    expect(good.status()).toBe(200)
  })

  // ========== RESENDING ==========

  test('a resend issues a new link and kills the old one', async ({
    authenticatedRequest,
    request,
  }) => {
    const email = uniqueEmail('resend')
    const { admin, invitation } = await createAdmin(authenticatedRequest, email)
    const firstToken = tokenFromInvitationUrl(invitation.url)

    const resend = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users/${admin.id}/invitation`,
      { data: stepUp() },
    )
    expect(resend.status()).toBe(201)
    const secondToken = tokenFromInvitationUrl((await resend.json()).invitation.url)
    expect(secondToken).not.toBe(firstToken)

    // An admin who believes they have replaced something has.
    const old = await request.get(`${API_BASE}/invitations/${firstToken}`)
    expect(old.status()).toBe(409)

    const fresh = await request.get(`${API_BASE}/invitations/${secondToken}`)
    expect(fresh.status()).toBe(200)
  })

  test('a resend carries a step-up, like every other credential this mints', async ({
    authenticatedRequest,
  }) => {
    const email = uniqueEmail('stepup')
    const { admin } = await createAdmin(authenticatedRequest, email)

    const missing = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users/${admin.id}/invitation`,
      { data: {} },
    )
    expect(missing.status()).toBe(422)

    const wrong = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users/${admin.id}/invitation`,
      { data: stepUp({ password: 'definitely-wrong-password' }) },
    )
    expect(wrong.status()).toBe(401)
  })

  /**
   * The load-bearing refusal. An emailed link able to re-credential an
   * established admin would be a second way past the step-up guarding
   * `POST /admin-users/{id}/reset-password` — and the weaker of the two paths,
   * which is the one an attacker picks.
   */
  test('an account that has accepted can never be invited again', async ({
    authenticatedRequest,
    request,
  }) => {
    const email = uniqueEmail('onboarded')
    const { admin, invitation } = await createAdmin(authenticatedRequest, email)

    await request.post(`${API_BASE}/invitations/${tokenFromInvitationUrl(invitation.url)}/accept`, {
      data: { password: INVITED_ADMIN_PASSWORD, password_confirmation: INVITED_ADMIN_PASSWORD },
    })

    const resend = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users/${admin.id}/invitation`,
      { data: stepUp() },
    )

    expect(resend.status()).toBe(409)
    expect((await resend.json()).reason).toBe('admin_already_onboarded')
  })

  /**
   * Without this, deactivating a colleague would not stop their outstanding
   * invitation from being renewed.
   */
  test('a deactivated account cannot be invited', async ({ authenticatedRequest }) => {
    const email = uniqueEmail('inactive')
    const { admin } = await createAdmin(authenticatedRequest, email, ['kassenwart'])

    const deactivated = await authenticatedRequest.delete(`${API_BASE}/admin/admin-users/${admin.id}`)
    expect(deactivated.status()).toBe(200)

    const resend = await authenticatedRequest.post(
      `${API_BASE}/admin/admin-users/${admin.id}/invitation`,
      { data: stepUp() },
    )

    expect(resend.status()).toBe(409)
    expect((await resend.json()).reason).toBe('admin_account_inactive')
  })

  test('the public endpoints need no session, and the resend does', async ({ request }) => {
    const response = await request.post(
      `${API_BASE}/admin/admin-users/00000000-0000-0000-0000-000000000000/invitation`,
      { data: stepUp() },
    )
    expect(response.status()).toBe(401)
  })
})
