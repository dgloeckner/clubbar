import { test, expect } from '../../fixtures/auth.fixture'
import { stepUp } from '../../fixtures/stepUp'
import { loginAs } from '../../utils/csrf'
import {
  createInvitedAdmin,
  tokenFromInvitationUrl,
  INVITED_ADMIN_PASSWORD,
} from '../../utils/adminInvitation'

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
    // The token is in the **fragment**, never in a path segment: everything
    // left of the `#` is a constant, so no web server in front of the
    // installation ever writes the token to an access log.
    expect(body.invitation.url).toContain('/invite#')
    expect(body.invitation.url).not.toContain('/invite/')
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

  test('the link names the invitee and their own role, and nothing about the club', async ({
    authenticatedRequest,
    request,
  }) => {
    const email = uniqueEmail('describe')
    const { invitation } = await createAdmin(authenticatedRequest, email, ['getraenkewart'])

    // Deliberately the *unauthenticated* context: this is the endpoint an
    // invitee reaches with no session at all.
    const response = await request.post(`${API_BASE}/invitations/lookup`, {
      data: { token: tokenFromInvitationUrl(invitation.url) },
    })

    expect(response.status()).toBe(200)
    const body = (await response.json()).invitation
    expect(body.email).toBe(email)
    expect(body.display_name).toBe('Invited Admin')

    // The account's own role, so the page can say what somebody is being
    // onboarded *as* before asking them to set a credential.
    expect(body.roles).toEqual(['getraenkewart'])

    // …and nothing beyond that account. A token proves its holder can read one
    // mailbox — a reason to describe *this* account, not the club's structure.
    expect(Object.keys(body).sort()).toEqual(['display_name', 'email', 'locale', 'roles'])
  })

  test('setting a password through the link makes the account usable', async ({
    authenticatedRequest,
    request,
    playwright,
  }) => {
    const email = uniqueEmail('accept')
    const { admin, invitation } = await createAdmin(authenticatedRequest, email)

    const accepted = await request.post(
      `${API_BASE}/invitations/accept`,
      {
        data: {
          token: tokenFromInvitationUrl(invitation.url),
          password: INVITED_ADMIN_PASSWORD,
          password_confirmation: INVITED_ADMIN_PASSWORD,
        },
      },
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

    await request.post(`${API_BASE}/invitations/accept`, {
      data: {
        token: tokenFromInvitationUrl(invitation.url),
        password: INVITED_ADMIN_PASSWORD,
        password_confirmation: INVITED_ADMIN_PASSWORD,
      },
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

    const first = await request.post(`${API_BASE}/invitations/accept`, {
      data: {
        token,
        password: INVITED_ADMIN_PASSWORD,
        password_confirmation: INVITED_ADMIN_PASSWORD,
      },
    })
    expect(first.status()).toBe(200)

    const second = await request.post(`${API_BASE}/invitations/accept`, {
      data: {
        token,
        password: 'Different0ne',
        password_confirmation: 'Different0ne',
      },
    })
    expect(second.status()).toBe(409)
    expect((await second.json()).reason).toBe('invitation_invalid')
  })

  test('an unknown token is refused, and says nothing about why', async ({ request }) => {
    const response = await request.post(`${API_BASE}/invitations/lookup`, {
      data: { token: 'thistokenneverexisted12345' },
    })

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
      const response = await request.post(`${API_BASE}/invitations/accept`, {
        data: {
          token,
          password: weak,
          password_confirmation: weak,
        },
      })
      expect(response.status()).toBe(422)
    }

    // A mismatched confirmation, likewise — and none of it spent the link.
    const mismatch = await request.post(`${API_BASE}/invitations/accept`, {
      data: {
        token,
        password: INVITED_ADMIN_PASSWORD,
        password_confirmation: 'Something3lse',
      },
    })
    expect(mismatch.status()).toBe(422)

    const good = await request.post(`${API_BASE}/invitations/accept`, {
      data: {
        token,
        password: INVITED_ADMIN_PASSWORD,
        password_confirmation: INVITED_ADMIN_PASSWORD,
      },
    })
    expect(good.status()).toBe(200)
  })

  /**
   * The bug of #798, at the level the guarantee actually lives on.
   *
   * An invitee follows their link in a browser somebody else is already signed
   * in to — the club laptop nobody logs out of. Accepting there used to leave
   * that session untouched, and the panel's redirect to `/login` then dropped
   * the new admin into the *other* account's dashboard, having proven nothing
   * but that they can read an email.
   *
   * So the endpoint ends the session it was called with. What is asserted is
   * the session's death, not a header: a client that keeps sending the cookie
   * must be answered as an anonymous one.
   */
  test('accepting through a signed-in browser ends that browser\'s session', async ({
    authenticatedRequest,
    playwright,
  }) => {
    // The admin who is already signed in — their own account, never the shared
    // seeded one, whose session the whole suite runs on (E2E Pattern 002).
    const occupant = await createInvitedAdmin(authenticatedRequest, {
      email: uniqueEmail('occupant'),
      display_name: 'Already Signed In',
    })
    const browser = await loginAs(playwright, occupant.admin.email, occupant.password)

    try {
      expect((await browser.get(`${API_BASE}/auth/profile`)).status()).toBe(200)

      // …and the invitee, sitting down at that same browser.
      const { invitation } = await createAdmin(authenticatedRequest, uniqueEmail('newcomer'))

      const accepted = await browser.post(`${API_BASE}/invitations/accept`, {
        data: {
          token: tokenFromInvitationUrl(invitation.url),
          password: INVITED_ADMIN_PASSWORD,
          password_confirmation: INVITED_ADMIN_PASSWORD,
        },
      })
      expect(accepted.status()).toBe(200)

      // The session that arrived with the request is gone: the browser holds
      // exactly one identity now, the one it is about to sign in with.
      const after = await browser.get(`${API_BASE}/auth/profile`)
      expect(after.status()).toBe(401)
    } finally {
      await browser.dispose()
    }
  })

  /**
   * The other half of the same rule: *reading* the page is not accepting. An
   * admin checking a link they issued stays signed in.
   */
  test('looking a link up leaves a signed-in browser signed in', async ({
    authenticatedRequest,
    playwright,
  }) => {
    const occupant = await createInvitedAdmin(authenticatedRequest, {
      email: uniqueEmail('looker'),
      display_name: 'Still Signed In',
    })
    const browser = await loginAs(playwright, occupant.admin.email, occupant.password)

    try {
      const { invitation } = await createAdmin(authenticatedRequest, uniqueEmail('looked-at'))

      const lookup = await browser.post(`${API_BASE}/invitations/lookup`, {
        data: { token: tokenFromInvitationUrl(invitation.url) },
      })
      expect(lookup.status()).toBe(200)

      expect((await browser.get(`${API_BASE}/auth/profile`)).status()).toBe(200)
    } finally {
      await browser.dispose()
    }
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
    const old = await request.post(`${API_BASE}/invitations/lookup`, { data: { token: firstToken } })
    expect(old.status()).toBe(409)

    const fresh = await request.post(`${API_BASE}/invitations/lookup`, {
      data: { token: secondToken },
    })
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

    await request.post(`${API_BASE}/invitations/accept`, {
      data: {
        token: tokenFromInvitationUrl(invitation.url),
        password: INVITED_ADMIN_PASSWORD,
        password_confirmation: INVITED_ADMIN_PASSWORD,
      },
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

    const deactivated = await authenticatedRequest.post(`${API_BASE}/admin/admin-users/${admin.id}/deactivate`)
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

  /**
   * **The token is never in a URL.**
   *
   * A request line is written verbatim to every access log in front of the
   * installation — in the shipped package twice per request, by php-fpm and by
   * httpd — and those logs outlive the mailbox the link was sent to and are
   * readable by anybody with hosting-panel access. So a token in a path is the
   * same credential handover this feature exists to abolish, one layer down,
   * and it is the shape the first draft of this endpoint had.
   *
   * Three assertions, because there are three ways the property could come
   * back: the URL the admin is handed, the endpoint the browser calls, and the
   * one that spends the token. The last two are pinned as **404**: the route
   * has to be gone, not merely unused, or a client that kept the old shape
   * would keep leaking quietly.
   */
  test('no token ever appears in a URL', async ({ authenticatedRequest, request }) => {
    const email = uniqueEmail('nourl')
    const { invitation } = await createAdmin(authenticatedRequest, email)
    const token = tokenFromInvitationUrl(invitation.url)

    // 1. What the admin is handed: everything left of the `#` is a constant,
    //    and a fragment is never sent to any server.
    const link = new URL(invitation.url)
    expect(link.pathname).toBe('/invite')
    expect(link.search).toBe('')
    expect(link.hash).toBe(`#${token}`)

    // 2. The lookup. A POST that reads, because a GET cannot carry a body and
    //    the body is the only place the token may travel.
    const pathLookup = await request.get(`${API_BASE}/invitations/${token}`)
    expect(pathLookup.status()).toBe(404)

    const bodyLookup = await request.post(`${API_BASE}/invitations/lookup`, { data: { token } })
    expect(bodyLookup.status()).toBe(200)
    expect((await bodyLookup.json()).invitation.email).toBe(email)

    // 3. The accept.
    const pathAccept = await request.post(`${API_BASE}/invitations/${token}/accept`, {
      data: { password: INVITED_ADMIN_PASSWORD, password_confirmation: INVITED_ADMIN_PASSWORD },
    })
    expect(pathAccept.status()).toBe(404)

    // …and the token still works, so the 404s above are the route being gone
    // rather than the token having been spent by one of them.
    const accepted = await request.post(`${API_BASE}/invitations/accept`, {
      data: { token, password: INVITED_ADMIN_PASSWORD, password_confirmation: INVITED_ADMIN_PASSWORD },
    })
    expect(accepted.status(), await accepted.text()).toBe(200)
  })

  /**
   * A body with no token at all takes the same path as a wrong one. An absent
   * token and an invented token are both simply not a token, and an endpoint
   * that distinguished them would be answering a question only somebody
   * probing it has a use for.
   */
  test('a missing token is refused like an invalid one', async ({ request }) => {
    const response = await request.post(`${API_BASE}/invitations/lookup`, { data: {} })

    expect(response.status()).toBe(409)
    expect((await response.json()).reason).toBe('invitation_invalid')
  })
})
