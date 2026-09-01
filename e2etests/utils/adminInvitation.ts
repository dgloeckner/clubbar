/**
 * Creating an admin account that can actually sign in (migration 058, UC-A68).
 *
 * `POST /api/admin/admin-users` no longer answers with a password — it answers
 * with an invitation. So a test that needs a usable account now has two steps
 * rather than one, and this is the seam where that lives: without it, every
 * spec that wanted "an admin with a known password" would carry its own copy of
 * the accept call, and the next change to the flow would have to find them all.
 *
 * It also keeps the tests honest about what changed. The password a test signs
 * in with is one the *test* chose, and nothing in the system ever knew it —
 * which is the property the feature exists to create.
 */

import { request as apiRequest } from '@playwright/test'
import type { APIRequestContext } from '@playwright/test'
import { stepUp } from '../fixtures/stepUp'

const API_BASE = 'http://localhost:8080/api'

/**
 * A password that satisfies `InvitationController::accept`'s rule — eight
 * characters, with a lower-case letter, an upper-case letter and a digit.
 * Fixed rather than random: a test that fails should not also make the reader
 * wonder whether the password was the problem.
 */
export const INVITED_ADMIN_PASSWORD = 'Str0ngPassword'

export interface CreatedAdmin {
  /** The account as the create endpoint returned it. */
  admin: {
    id: string
    email: string
    display_name: string
    locale: string
    is_active: boolean
    roles?: string[]
    [key: string]: unknown
  }
  /** The password the invitation was used to set — what to sign in with. */
  password: string
  /** The invitation link, in case a test wants to assert on it directly. */
  invitationUrl: string
}

export interface CreateAdminOptions {
  email: string
  display_name?: string
  locale?: string
  roles?: string[]
  /** Overrides {@link INVITED_ADMIN_PASSWORD}. */
  password?: string
}

/**
 * The token out of an invitation URL — `<base>/invite#<token>`.
 *
 * The token is in the **fragment**, not in a path segment, so that it never
 * reaches an access log (`InvitationLink::url()`). Splitting on `#` rather than
 * on `/invite/` is what keeps that property asserted rather than assumed: a
 * link that went back to a path form would fail every test that starts here.
 */
export function tokenFromInvitationUrl(url: string): string {
  const at = url.indexOf('#')
  if (at === -1) {
    throw new Error(`Not an invitation URL — no token fragment: ${url}`)
  }
  return decodeURIComponent(url.slice(at + 1))
}

/**
 * Walk an invitation link the way its recipient does: from a browser holding
 * **no session at all**.
 *
 * The anonymous context is the point, not a detail (#798). `POST
 * /api/invitations/accept` ends whatever session the caller presented — an
 * invitee who sets a password on the club laptop must not stay signed in as
 * the admin who invited them — so a helper that accepted through an
 * authenticated context would quietly destroy that context's session, and in
 * this suite the most convenient context to reach for is the one shared by
 * every test in the run (E2E Pattern 002).
 *
 * @param invitationUrlOrToken Either the link as issued, or the bare token.
 * @returns The address the account signs in with, as the endpoint reports it.
 */
export async function acceptInvitation(
  invitationUrlOrToken: string,
  password: string = INVITED_ADMIN_PASSWORD,
): Promise<{ email: string }> {
  const token = invitationUrlOrToken.includes('#')
    ? tokenFromInvitationUrl(invitationUrlOrToken)
    : invitationUrlOrToken

  // `storageState` is spelled out, and it is the load-bearing part: inside a
  // project whose `use` carries one — `admin-chromium` uses the shared seeded
  // admin's — `newContext()` inherits it, and the "anonymous" context would
  // arrive holding that session and destroy it for every worker in the run.
  const anonymous = await apiRequest.newContext({
    baseURL: API_BASE,
    storageState: { cookies: [], origins: [] },
  })
  try {
    const response = await anonymous.post(`${API_BASE}/invitations/accept`, {
      data: { token, password, password_confirmation: password },
    })

    if (response.status() !== 200) {
      throw new Error(
        `Could not accept the invitation: ${response.status()} ${await response.text()}`,
      )
    }

    return await response.json()
  } finally {
    await anonymous.dispose()
  }
}

/**
 * Create an admin account and walk its invitation, so the account ends up with
 * a password the caller knows.
 *
 * `request` must be an authenticated admin context — creating an account is
 * `admin`-only and carries a step-up. The accept call deliberately does not:
 * it goes through {@link acceptInvitation}, from a context holding no session,
 * which is both what an invitee really does and — since #798 — the only safe
 * way to call it, because accepting ends the caller's session.
 */
export async function createInvitedAdmin(
  request: APIRequestContext,
  options: CreateAdminOptions,
): Promise<CreatedAdmin> {
  const password = options.password ?? INVITED_ADMIN_PASSWORD

  const createResponse = await request.post(`${API_BASE}/admin/admin-users`, {
    data: {
      ...stepUp(),
      email: options.email,
      display_name: options.display_name ?? 'Test Admin',
      locale: options.locale ?? 'de',
      ...(options.roles ? { roles: options.roles } : {}),
    },
  })

  if (createResponse.status() !== 201) {
    throw new Error(
      `Could not create ${options.email}: ${createResponse.status()} ${await createResponse.text()}`,
    )
  }

  const created = await createResponse.json()
  const invitationUrl: string = created.invitation.url

  await acceptInvitation(invitationUrl, password)

  return { admin: created.admin, password, invitationUrl }
}
