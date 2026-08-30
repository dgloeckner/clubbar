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

/** The token out of an invitation URL — `<base>/invite/<token>`. */
export function tokenFromInvitationUrl(url: string): string {
  const marker = '/invite/'
  const at = url.lastIndexOf(marker)
  if (at === -1) {
    throw new Error(`Not an invitation URL: ${url}`)
  }
  return url.slice(at + marker.length)
}

/**
 * Create an admin account and walk its invitation, so the account ends up with
 * a password the caller knows.
 *
 * `request` must be an authenticated admin context — creating an account is
 * `admin`-only and carries a step-up. The accept call deliberately does not:
 * it is the public endpoint an invitee reaches with no session at all, and
 * using the authenticated context for it would quietly stop testing that.
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

  const acceptResponse = await request.post(
    `${API_BASE}/invitations/${tokenFromInvitationUrl(invitationUrl)}/accept`,
    { data: { password, password_confirmation: password } },
  )

  if (acceptResponse.status() !== 200) {
    throw new Error(
      `Could not accept the invitation for ${options.email}: ` +
        `${acceptResponse.status()} ${await acceptResponse.text()}`,
    )
  }

  return { admin: created.admin, password, invitationUrl }
}
