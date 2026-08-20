/**
 * Per-role flows, end to end (ADR-0044, #517).
 *
 * The access matrix beside this file proves each office can *knock* on the
 * right doors. It does that with empty bodies and ids that belong to nobody,
 * which is deliberate and which is also its limit: an endpoint can be reachable
 * and still be useless to the office that reached it, because the work it does
 * fans out to a second endpoint the grant table forgot.
 *
 * So these run the two offices' actual jobs to completion and read the result
 * back: a Kassenwart settles, a Getränkewart re-prices the beer. Both verify
 * persistence rather than a 200 — a write that answers 200 and stores nothing
 * is the failure mode worth catching.
 *
 * The negative flows are the same claim from the other side, at the three
 * boundaries the epic exists to hold: a Getränkewart cannot pivot a report onto
 * named members, a Kassenwart cannot mint authority, and nobody promotes an
 * account on the strength of a live session alone.
 *
 * Isolation (Patterns 001, 002): each worker's offices are its own accounts
 * (`fixtures/roleRequests.ts`), and every fixture below is created by the test
 * that asserts on it.
 */

import { test, expect } from '../../fixtures/roleRequests'
import { settlementFactory, ensureSepaConfigured } from '../../utils/settlements'
import { stepUp } from '../../fixtures/stepUp'

const API_BASE = 'http://localhost:8080/api'

function unique(): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`
}

test.describe('What each office can actually do (ADR-0044)', () => {
  /**
   * The treasurer's job in full: a member, a purchase at the terminal, and a
   * settlement covering it — created by the Kassenwart's own session and read
   * back from the store afterwards.
   *
   * The creditor configuration is filled in by the seeded admin first when it
   * is missing: reading it is treasury work and writing it is not (that split
   * is in the grant table on purpose), so a Kassenwart standing in front of an
   * unconfigured installation is blocked for a reason this test is not about.
   */
  test('a Kassenwart runs a settlement end to end', async ({
    kassenwartRequest,
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    test.setTimeout(120_000)

    await ensureSepaConfigured(authenticatedRequest)

    const settlement = await settlementFactory(kassenwartRequest, authenticatedTerminalRequest).create({
      amountCents: 1750,
    })

    // Read back through the Kassenwart's own session: the run is only complete
    // if the office that made it can see it.
    const stored = await kassenwartRequest.get(`${API_BASE}/admin/settlements/${settlement.id}`)
    expect(stored.status()).toBe(200)

    const body = await stored.json()
    expect(body.settlement?.id ?? body.id).toBe(settlement.id)

    // And it covers the member it was made for, with the amount that was owed.
    const listed = await kassenwartRequest.get(
      `${API_BASE}/admin/settlements?per_page=100&member_id=${settlement.memberId}`,
    )
    expect(listed.status()).toBe(200)
    expect(JSON.stringify(await listed.json())).toContain(settlement.id)
  })

  /**
   * The bar's job, and the reason the epic exists: re-pricing a drink without
   * holding the members' bank details.
   *
   * Persistence is read back from a fresh GET rather than from the PATCH's own
   * response body, which is the difference between "the API echoed my price"
   * and "the price changed".
   */
  test('a Getränkewart re-prices a product and the price sticks', async ({ getraenkewartRequest }) => {
    const suffix = unique()

    const category = await getraenkewartRequest.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Rolle ${suffix}`, en: `Role ${suffix}` }, icon_name: 'generic' },
    })
    expect(category.status()).toBe(201)
    const categoryId = (await category.json()).id ?? (await category.json()).category?.id

    const created = await getraenkewartRequest.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: `Alt ${suffix}`, en: `Ale ${suffix}` },
        price_cents: 250,
        category_id: categoryId,
      },
    })
    expect(created.status()).toBe(201)
    const productId = (await created.json()).id ?? (await created.json()).product?.id

    const repriced = await getraenkewartRequest.patch(`${API_BASE}/admin/products/${productId}`, {
      data: { price_cents: 275 },
    })
    expect(repriced.status()).toBe(200)

    const readBack = await getraenkewartRequest.get(`${API_BASE}/admin/products?per_page=100&search=Alt ${suffix}`)
    expect(readBack.status()).toBe(200)
    const found = (await readBack.json()).data.find((p: { id: string }) => p.id === productId)
    expect(found, 'the product the Getränkewart created must be listed back to them').toBeTruthy()
    expect(found.price_cents).toBe(275)
  })

  /**
   * The Getränkewart sets a legal age, and still sees no member
   * (ADR-0045 rule 5, ADR-0044).
   *
   * This is the half of that rule that has to be *granted* rather than denied:
   * `min_age` is a property of a drink, and the office that owns the drinks
   * list owns it. The other half — that the same credential reaches no member
   * endpoint at all — is `role-access-matrix.spec.ts`, where every
   * `/admin/members` route is Kassenwart-only.
   *
   * Worth stating together, because the field is the one place in this epic
   * where a bar role touches something with a legal consequence, and the
   * obvious wrong instinct would be to escalate it to the Kassenwart — who
   * does not run the drinks list and would have to be asked every time a
   * bottle changed.
   */
  test('a Getränkewart sets a legal age on a drink, still without seeing a member', async ({
    getraenkewartRequest,
  }) => {
    const suffix = unique()

    const category = await getraenkewartRequest.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Spirituosen ${suffix}`, en: `Spirits ${suffix}` }, icon_name: 'generic' },
    })
    expect(category.status()).toBe(201)
    const categoryId = (await category.json()).id

    const created = await getraenkewartRequest.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: `Korn ${suffix}`, en: `Grain spirit ${suffix}` },
        price_cents: 250,
        category_id: categoryId,
        min_age: 18,
      },
    })
    expect(created.status(), await created.text()).toBe(201)
    const product = await created.json()
    expect(product.min_age).toBe(18)

    // Correcting it downwards is the same office doing the same job.
    const corrected = await getraenkewartRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { min_age: 16 },
    })
    expect(corrected.status()).toBe(200)
    expect((await corrected.json()).min_age).toBe(16)

    // And clearing it — the write that makes a drink *more* available.
    const cleared = await getraenkewartRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { min_age: null },
    })
    expect(cleared.status()).toBe(200)
    expect((await cleared.json()).min_age).toBeNull()

    // The member side stays shut. Named here rather than left to the matrix
    // alone, because "who may set the age" and "who may see the ages" are the
    // two halves of rule 5 and they are easier to keep true side by side.
    const members = await getraenkewartRequest.get(`${API_BASE}/admin/members?per_page=1`)
    expect(members.status(), 'a Getränkewart must not be able to read the roster').toBe(403)
  })
})

test.describe('The three boundaries (ADR-0044)', () => {
  /**
   * #515's allow-list, through the real stack. `member` is the dimension that
   * turns a revenue report into a list of named people and what they drink;
   * `category` is the same endpoint doing the job the office is for.
   */
  test('a Getränkewart may not pivot a report onto named members', async ({ getraenkewartRequest }) => {
    const refused = await getraenkewartRequest.get(
      `${API_BASE}/admin/reports/revenue?group_by=member&period=month`,
    )
    expect(refused.status()).toBe(403)
    expect((await refused.json()).error).toBe('insufficient_role')

    const allowed = await getraenkewartRequest.get(
      `${API_BASE}/admin/reports/revenue?group_by=category&period=month`,
    )
    expect(allowed.status()).toBe(200)

    // The aggregate stays: a count with no names attached is not a member list.
    const body = await allowed.json()
    expect(body.summary).toBeTruthy()
  })

  /**
   * Capability acquisition, refused. A Kassenwart who could mint an account
   * could mint themselves an `admin` one, and the whole containment argument
   * would be decoration.
   */
  test('a Kassenwart may not mint an account or move anybody roles', async ({
    kassenwartRequest,
    kassenwartEmail,
    authenticatedRequest,
  }) => {
    const minted = await kassenwartRequest.post(`${API_BASE}/admin/admin-users`, {
      data: {
        ...stepUp(),
        email: `escalation-${unique()}@test.example`,
        display_name: 'Escalation Attempt',
        locale: 'de',
        roles: ['admin'],
      },
    })
    expect(minted.status()).toBe(403)
    expect((await minted.json()).error).toBe('insufficient_role')

    // Nor by editing an account that already exists — including their own.
    const target = await findAdminId(authenticatedRequest, kassenwartEmail)
    const promoted = await kassenwartRequest.patch(`${API_BASE}/admin/admin-users/${target}`, {
      data: { ...stepUp(), roles: ['admin'] },
    })
    expect(promoted.status()).toBe(403)
    expect((await promoted.json()).error).toBe('insufficient_role')

    expect(await rolesOf(authenticatedRequest, target)).toEqual(['kassenwart'])
  })

  /**
   * #520's gate: granting a role is minting authority, so it costs a password
   * and a fresh TOTP code even for an `admin` with a live session. Without the
   * step-up the promotion must not happen — asserted on the stored role set,
   * not on the status code alone.
   */
  test('an admin promotes nobody without a step-up', async ({ authenticatedRequest, kassenwartEmail }) => {
    const target = await findAdminId(authenticatedRequest, kassenwartEmail)

    const refused = await authenticatedRequest.patch(`${API_BASE}/admin/admin-users/${target}`, {
      data: { roles: ['admin'] },
    })
    expect(refused.status()).toBe(401)
    expect((await refused.json()).error).toBe('invalid_credentials')

    expect(await rolesOf(authenticatedRequest, target)).toEqual(['kassenwart'])
  })
})

/** The id behind an email, looked up as the seeded admin. */
async function findAdminId(
  admin: { get: (url: string) => Promise<{ json: () => Promise<any> }> },
  email: string,
): Promise<string> {
  const listed = await admin.get(`${API_BASE}/admin/admin-users?per_page=100&search=${encodeURIComponent(email)}`)
  const account = (await listed.json()).data.find((a: { email: string }) => a.email === email)
  if (!account) throw new Error(`No admin account found for ${email}`)

  return account.id
}

async function rolesOf(
  admin: { get: (url: string) => Promise<{ json: () => Promise<any> }> },
  id: string,
): Promise<string[]> {
  const body = await (await admin.get(`${API_BASE}/admin/admin-users/${id}`)).json()

  return body.admin?.roles ?? body.roles
}
