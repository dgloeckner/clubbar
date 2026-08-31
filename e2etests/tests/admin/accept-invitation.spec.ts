import { test, expect } from '../../fixtures/pageObjects'
import { loginAs } from '../../utils/csrf'
import { TEST_CREDENTIALS } from '../../config/test-credentials'
import { stepUp } from '../../fixtures/stepUp'
import { tokenFromInvitationUrl, INVITED_ADMIN_PASSWORD } from '../../utils/adminInvitation'

/**
 * The invitee's own journey through the panel (migration 058, UC-A68):
 *
 *     open the link → set a password → sign in → enrol an authenticator
 *
 * The API suite proves the endpoints; this proves the half only a browser can:
 * that `/invite#<token>` is reachable **without a session** — it is the one
 * screen in the panel that has to be — that it renders, that setting a password
 * carries the invitee to the sign-in form, and that signing in there lands them
 * exactly where every other new admin lands: the Authenticator setup gate.
 *
 * These tests start signed out. That is the point rather than a detail: an
 * invitation opened in a browser that already holds an admin session would
 * exercise nothing about the state the invitee is actually in.
 */
test.use({ storageState: { cookies: [], origins: [] } })

const API_BASE = 'http://localhost:8080/api'

/** Pattern 001: a fresh account per test, never a shared one. */
function uniqueEmail(label: string): string {
  return `accept-${label}-${Date.now()}-${Math.floor(Math.random() * 10000)}@test.example.com`
}

/**
 * Create an account through the API as the seeded admin and hand back its
 * invitation link — the browser under test never gains a session from this.
 */
async function inviteAdmin(
  playwright: Parameters<typeof loginAs>[0],
  email: string,
  roles?: string[],
): Promise<string> {
  const ctx = await loginAs(playwright, TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
  try {
    const response = await ctx.post(`${API_BASE}/admin/admin-users`, {
      data: {
        ...stepUp(),
        email,
        display_name: 'Invited Colleague',
        locale: 'de',
        ...(roles ? { roles } : {}),
      },
    })
    expect(response.status(), await response.text()).toBe(201)

    return (await response.json()).invitation.url
  } finally {
    await ctx.dispose()
  }
}

/**
 * The link points at APP_URL; the SPA under test is served elsewhere in dev.
 *
 * The token goes back into a **fragment**, which is where the real link carries
 * it: never sent to a server, so never written to an access log.
 */
function invitePath(url: string): string {
  return `/invite#${tokenFromInvitationUrl(url)}`
}

test.describe('Accepting an invitation (UI)', () => {
  test('a new admin sets a password and is sent to sign in', async ({ page, playwright }) => {
    const email = uniqueEmail('happy')
    const invitationUrl = await inviteAdmin(playwright, email)

    await page.goto(invitePath(invitationUrl))

    // Greeted by name, and shown the address the account signs in with — which
    // they have otherwise only ever seen in an email.
    await expect(page.getByTestId('invite-email')).toHaveValue(email)
    // Said before it happens, so the authenticator prompt on the next screen
    // reads as the next step rather than as an obstacle.
    await expect(page.getByTestId('invite-next-step')).toBeVisible()

    await page.getByTestId('invite-password-input').fill(INVITED_ADMIN_PASSWORD)
    await page.getByTestId('invite-password-confirmation-input').fill(INVITED_ADMIN_PASSWORD)
    await page.getByTestId('invite-submit-button').click()

    // Carried to the sign-in form, with the address already filled in.
    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByTestId('login-email-input')).toHaveValue(email)
    await expect(page.getByTestId('login-notice')).toBeVisible()

    // And the password really is set: signing in lands on the enrolment gate,
    // because accepting an invitation sets the first factor and nothing else.
    await page.getByTestId('login-password-input').fill(INVITED_ADMIN_PASSWORD)
    await page.getByTestId('login-submit-button').click()

    await expect(page.getByTestId('totp-qr-code')).toBeVisible({ timeout: 10000 })
  })

  /**
   * Onboarding a **Getränkewart**, the office that sees no member at all.
   *
   * The role is chosen on the create form before the account exists, and this
   * is where the invitee finds out about it: named on the page, before they
   * are asked to set a credential. Without it they are told only that "an
   * account was created for you" and have to sign in and infer their job from
   * which pages happen to open.
   */
  test('a Getränkewart is told which role they are being onboarded into', async ({
    page,
    playwright,
  }) => {
    const email = uniqueEmail('getraenkewart')
    const invitationUrl = await inviteAdmin(playwright, email, ['getraenkewart'])

    await page.goto(invitePath(invitationUrl))

    // Named as the club names the office — untranslated, as Storno and Deckel
    // are — and shown before the password fields, not after.
    await expect(page.getByTestId('invite-role-getraenkewart')).toHaveText('Getränkewart')
    await expect(page.getByTestId('invite-roles')).not.toContainText('Admin')

    await page.getByTestId('invite-password-input').fill(INVITED_ADMIN_PASSWORD)
    await page.getByTestId('invite-password-confirmation-input').fill(INVITED_ADMIN_PASSWORD)
    await page.getByTestId('invite-submit-button').click()

    // The rest of the path is every account's: sign in, then enrol.
    await expect(page).toHaveURL(/\/login$/)
    await page.getByTestId('login-password-input').fill(INVITED_ADMIN_PASSWORD)
    await page.getByTestId('login-submit-button').click()
    await expect(page.getByTestId('totp-qr-code')).toBeVisible({ timeout: 10000 })
  })

  test('a password that does not meet the rules is refused before it is sent', async ({
    page,
    playwright,
  }) => {
    const email = uniqueEmail('weak')
    const invitationUrl = await inviteAdmin(playwright, email)

    await page.goto(invitePath(invitationUrl))
    await expect(page.getByTestId('invite-email')).toHaveValue(email)

    // No request should leave the browser for a password the server would
    // refuse anyway — the point of checking here as well as there.
    let acceptCalls = 0
    await page.route('**/api/invitations/accept', (route) => {
      acceptCalls += 1
      return route.continue()
    })

    await page.getByTestId('invite-password-input').fill('short1A')
    await page.getByTestId('invite-password-confirmation-input').fill('short1A')
    await page.getByTestId('invite-submit-button').click()

    await expect(page).toHaveURL(/\/invite#/)
    expect(acceptCalls).toBe(0)

    // A mismatch is caught the same way.
    await page.getByTestId('invite-password-input').fill(INVITED_ADMIN_PASSWORD)
    await page.getByTestId('invite-password-confirmation-input').fill('Something3lse')
    await page.getByTestId('invite-submit-button').click()

    await expect(page).toHaveURL(/\/invite#/)
    expect(acceptCalls).toBe(0)
  })

  test('a spent link says so instead of offering a form', async ({ page, playwright }) => {
    const email = uniqueEmail('spent')
    const invitationUrl = await inviteAdmin(playwright, email)

    // Spend it out of band, the way a second click on a link in a mailbox
    // would — or a colleague forwarding the mail on.
    const ctx = await loginAs(playwright, TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
    const accepted = await ctx.post(
      `${API_BASE}/invitations/accept`,
      {
        data: {
          token: tokenFromInvitationUrl(invitationUrl),
          password: INVITED_ADMIN_PASSWORD,
          password_confirmation: INVITED_ADMIN_PASSWORD,
        },
      },
    )
    expect(accepted.status(), await accepted.text()).toBe(200)
    await ctx.dispose()

    await page.goto(invitePath(invitationUrl))

    // The refusal replaces the form rather than sitting above it: there is
    // nothing here the invitee can do but ask for a new invitation.
    await expect(page.getByTestId('invite-link-error')).toBeVisible()
    await expect(page.getByTestId('invite-password-input')).toHaveCount(0)

    // …and the way onward is offered rather than left to be guessed.
    await page.getByTestId('invite-to-login-button').click()
    await expect(page).toHaveURL(/\/login$/)
  })

  test('an invented token is refused the same way', async ({ page }) => {
    await page.goto('/invite#thistokenneverexisted1234567890')

    await expect(page.getByTestId('invite-link-error')).toBeVisible()
    await expect(page.getByTestId('invite-password-input')).toHaveCount(0)
  })
})
