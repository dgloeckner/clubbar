import { expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { readFileSync } from 'node:fs'
import path from 'node:path'

import { activePrivateKey, WRONG_PRIVATE_KEY } from '../../fixtures/encryption'
import { test } from '../../fixtures/roleRequests'
import { stepUp } from '../../fixtures/stepUp'
import { lockClubConfig, unlockClubConfig } from '../../utils/clubConfigLock'
import { uniqueIban } from '../../utils/iban'
import { groupedIban, pdfPageCount, pdfText } from '../../utils/pdf'
import {
  clearRegistrationAttempts,
  configureSelfRegistration,
  restoreClubDocumentUrl,
  serveClubDocument,
  stopServingClubDocument,
} from '../../utils/sql'

/**
 * Accept first, print in bulk, sign later (ADR-0052, ADR-0036 and ADR-0051 as
 * amended 2026-11-28; UC-A17, UC-A18).
 *
 * The three steps of the new onboarding order, each carried through the real
 * stack and asserted by what it leaves behind:
 *
 * 1. a registration accepted **without** a signature is a member the terminal
 *    would refuse — and stays one until a signature date is recorded;
 * 2. the open mandates print as **one** PDF, the whole club document once per
 *    member, with the IBAN only when the club's private key was supplied;
 * 3. recording the date is what makes the member SEPA-valid.
 *
 * Every registration here arrives through the public endpoint and every member
 * through an approval, so the IBAN that ends up on a printed page is one a
 * stranger's phone sealed — which is the claim worth making.
 *
 * ### Isolation (Patterns 001, 003, 004)
 *
 * Each test submits registrations on accounts of its own (`uniqueIban()`): the
 * inbox flags a submission whose IBAN matches an existing mandate, the batch
 * skips a flagged row, and the shared fixture IBAN is on a seeded member. The
 * print is always narrowed with `member_ids` to the members this test created,
 * so its page count is a statement about them and not about whatever else in
 * the database happens to be waiting for a signature.
 *
 * Serial and under the club-configuration lock, for the reason every spec that
 * submits a registration is: the poster secret is a single row.
 */

const API = 'http://localhost:8080/api'

const TEMPLATE = path.resolve(__dirname, '../../../backend/tests/Fixtures/documents/club-anmeldung.pdf')

/** How many pages the club's own document has — every member gets all of them. */
const TEMPLATE_PAGES = pdfPageCount(readFileSync(TEMPLATE))

test.describe('Accept first, print in bulk, sign later', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(() => {
    serveClubDocument()
  })

  test.afterAll(() => {
    stopServingClubDocument()
  })

  test.beforeEach(() => {
    lockClubConfig()
    clearRegistrationAttempts()
    // The print reads the club's configured document URL, which other specs
    // deliberately clear or replace. Inside the lock it is ours for this test.
    restoreClubDocumentUrl()
  })

  test.afterEach(() => {
    restoreClubDocumentUrl()
    unlockClubConfig()
  })

  /** Submit through the public endpoint, the way an applicant does. */
  const submit = async (
    request: any,
    overrides: Record<string, unknown> = {},
  ): Promise<{ id: string; iban: string; email: string; lastName: string }> => {
    const secret = `secret-${randomUUID()}`
    configureSelfRegistration(secret)

    const unique = randomUUID().slice(0, 8)
    const data = {
      first_name: 'Lena',
      last_name: `Offen-${unique}`,
      email: `offen-${unique}@example.org`,
      date_of_birth: '1998-04-02',
      preferred_language: 'de',
      iban: uniqueIban(),
      ...overrides,
    }

    const response = await request.post(`${API}/public/registrations`, { data: { secret, ...data } })
    expect(response.status(), await response.text()).toBe(201)

    return {
      id: (await response.json()).id as string,
      iban: data.iban as string,
      email: data.email as string,
      lastName: data.last_name as string,
    }
  }

  const member = async (request: any, memberId: string) => {
    const response = await request.get(`${API}/admin/members/${memberId}`)
    expect(response.status()).toBe(200)

    return response.json()
  }

  /** Accept without a signature and hand back the member id. */
  const acceptUnsigned = async (authenticatedRequest: any, request: any, overrides = {}) => {
    const registration = await submit(request, overrides)
    const response = await authenticatedRequest.post(
      `${API}/admin/registrations/${registration.id}/approve`,
      { data: {} },
    )
    expect(response.status(), await response.text()).toBe(201)

    return { ...registration, memberId: (await response.json()).id as string }
  }

  const print = (ctx: any, data: Record<string, unknown>) =>
    ctx.post(`${API}/admin/members/mandate-documents`, { data })

  // ── step 1: accept ───────────────────────────────────────────────────────

  test('a registration accepted without a signature is a member who cannot buy yet', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id } = await submit(request)

    const response = await authenticatedRequest.post(`${API}/admin/registrations/${id}/approve`, {
      data: {},
    })
    expect(response.status()).toBe(201)
    const created = await response.json()

    // The mandate is there — reference, masked IBAN, bank — and only its
    // signature is missing. That one NULL is what `is_sepa_valid` reads, and
    // `is_sepa_valid` is what the terminal refuses a card on (ADR-0020).
    expect(created.mandate_reference).toBeTruthy()
    expect(created.iban_last4).toMatch(/^\d{4}$/)
    expect(created.mandate_signed_at).toBeNull()
    expect(created.is_sepa_valid).toBe(false)

    // The pending row is gone: this was an approval, not a parking place.
    expect((await authenticatedRequest.get(`${API}/admin/registrations/${id}`)).status()).toBe(404)

    // And the terminal agrees — the sync is what the kiosk decides from.
    const persisted = await member(authenticatedRequest, created.id)
    expect(persisted.is_sepa_valid).toBe(false)
  })

  test('recording the signature date is what makes the member SEPA-valid', async ({
    request,
    authenticatedRequest,
  }) => {
    const { memberId } = await acceptUnsigned(authenticatedRequest, request)
    const before = await member(authenticatedRequest, memberId)

    const patched = await authenticatedRequest.patch(`${API}/admin/members/${memberId}`, {
      data: { mandate_signed_at: '2026-11-28' },
    })
    expect(patched.status()).toBe(200)

    const after = await member(authenticatedRequest, memberId)
    expect(after.mandate_signed_at).toBe('2026-11-28')
    expect(after.is_sepa_valid).toBe(true)
    // The mandate in hand was completed — no new one was opened, so the
    // reference on the signed paper is still the one the bank will see.
    expect(after.mandate_reference).toBe(before.mandate_reference)
  })

  test('the signature stays a pair: half of it is refused', async ({ request, authenticatedRequest }) => {
    const { id } = await submit(request)

    // An attestation with no date to attach it to.
    const attestationOnly = await authenticatedRequest.post(
      `${API}/admin/registrations/${id}/approve`,
      { data: { signed_mandate_confirmed: true } },
    )
    expect(attestationOnly.status()).toBe(422)
    expect((await attestationOnly.json()).messages).toHaveProperty('mandate_signed_at')

    // A date with an explicit refusal to attest is still refused by name.
    const refused = await authenticatedRequest.post(`${API}/admin/registrations/${id}/approve`, {
      data: { mandate_signed_at: '2026-11-28', signed_mandate_confirmed: false },
    })
    expect(refused.status()).toBe(409)
    expect((await refused.json()).reason).toBe('registration_attestation_required')

    // Neither created anything.
    expect((await authenticatedRequest.get(`${API}/admin/registrations/${id}`)).status()).toBe(200)
  })

  // ── step 1, in bulk ──────────────────────────────────────────────────────

  test('a batch acceptance yields members that are SEPA-invalid until a date is set', async ({
    request,
    authenticatedRequest,
  }) => {
    const first = await submit(request)
    const second = await submit(request)

    const response = await authenticatedRequest.post(`${API}/admin/registrations/approve-batch`, {
      data: { ids: [first.id, second.id] },
    })
    expect(response.status(), await response.text()).toBe(200)
    const result = await response.json()

    expect(result.skipped).toEqual([])
    expect(result.accepted.map((entry: any) => entry.registration_id)).toEqual([first.id, second.id])

    for (const accepted of result.accepted) {
      const created = await member(authenticatedRequest, accepted.member_id)
      expect(created.mandate_signed_at).toBeNull()
      expect(created.is_sepa_valid).toBe(false)

      // The inbox no longer holds it.
      expect(
        (await authenticatedRequest.get(`${API}/admin/registrations/${accepted.registration_id}`)).status(),
      ).toBe(404)
    }

    // One of them signs.
    const signer = result.accepted[0].member_id
    await authenticatedRequest.patch(`${API}/admin/members/${signer}`, {
      data: { mandate_signed_at: '2026-11-28' },
    })

    expect((await member(authenticatedRequest, signer)).is_sepa_valid).toBe(true)
    expect((await member(authenticatedRequest, result.accepted[1].member_id)).is_sepa_valid).toBe(false)
  })

  test('a batch leaves a possible duplicate in the inbox and accepts the rest', async ({
    request,
    authenticatedRequest,
  }) => {
    // Somebody the club already has, with a mandate on a known account.
    const existing = await acceptUnsigned(authenticatedRequest, request)

    const sameAccount = await submit(request, { iban: existing.iban })
    const sameEmail = await submit(request, { email: existing.email })
    const stranger = await submit(request)

    const response = await authenticatedRequest.post(`${API}/admin/registrations/approve-batch`, {
      data: { ids: [sameAccount.id, sameEmail.id, stranger.id] },
    })
    expect(response.status()).toBe(200)
    const result = await response.json()

    expect(result.accepted.map((entry: any) => entry.registration_id)).toEqual([stranger.id])
    expect(result.skipped).toEqual([
      { registration_id: sameAccount.id, reason: 'registration_possible_duplicate' },
      { registration_id: sameEmail.id, reason: 'registration_possible_duplicate' },
    ])

    // Skipped means untouched: both are still there for a person to judge.
    for (const skipped of [sameAccount, sameEmail]) {
      expect((await authenticatedRequest.get(`${API}/admin/registrations/${skipped.id}`)).status()).toBe(200)
    }
  })

  test('two siblings on one parent\'s account are both accepted in one batch', async ({
    request,
    authenticatedRequest,
  }) => {
    const parentAccount = uniqueIban()
    const lena = await submit(request, { iban: parentAccount, account_holder_name: 'Petra Brandt' })
    const jonas = await submit(request, { iban: parentAccount, account_holder_name: 'Petra Brandt' })

    const response = await authenticatedRequest.post(`${API}/admin/registrations/approve-batch`, {
      data: { ids: [lena.id, jonas.id] },
    })
    const result = await response.json()

    // The flags are evaluated once, before anything is written. Row by row,
    // the second sibling would be flagged against the first — a member this
    // very request created.
    expect(result.skipped).toEqual([])
    expect(result.accepted).toHaveLength(2)
  })

  test('a batch reports a registration that has vanished instead of failing', async ({
    request,
    authenticatedRequest,
  }) => {
    const here = await submit(request)
    const gone = randomUUID()

    const response = await authenticatedRequest.post(`${API}/admin/registrations/approve-batch`, {
      data: { ids: [gone, here.id] },
    })
    expect(response.status()).toBe(200)
    const result = await response.json()

    expect(result.accepted.map((entry: any) => entry.registration_id)).toEqual([here.id])
    expect(result.skipped).toEqual([{ registration_id: gone, reason: 'registration_gone' }])
  })

  test('a batch needs explicit ids — there is no "all"', async ({ authenticatedRequest }) => {
    for (const data of [{}, { ids: [] }, { ids: 'all' }, { ids: ['all'] }]) {
      const response = await authenticatedRequest.post(`${API}/admin/registrations/approve-batch`, { data })
      expect(response.status(), JSON.stringify(data)).toBe(422)
      expect((await response.json()).messages).toHaveProperty('ids')
    }
  })

  // ── step 2: print ────────────────────────────────────────────────────────

  test('the batch PDF is the whole club document once per member', async ({
    request,
    authenticatedRequest,
  }) => {
    const a = await acceptUnsigned(authenticatedRequest, request)
    const b = await acceptUnsigned(authenticatedRequest, request)
    const c = await acceptUnsigned(authenticatedRequest, request)

    const response = await print(authenticatedRequest, { member_ids: [a.memberId, b.memberId, c.memberId] })

    expect(response.status(), await response.text()).toBe(200)
    expect(response.headers()['content-type']).toContain('application/pdf')
    expect(response.headers()['x-mandate-sheets']).toBe('3')

    const pdf = await response.body()
    expect(TEMPLATE_PAGES).toBeGreaterThan(1)
    expect(pdfPageCount(pdf)).toBe(TEMPLATE_PAGES * 3)

    // Each member is actually on a page, not merely counted.
    const text = pdfText(pdf)
    for (const printed of [a, b, c]) {
      expect(text).toContain(printed.lastName)
    }
  })

  test('the blank PDF carries no IBAN, and the keyed one carries it in full', async ({
    request,
    authenticatedRequest,
  }) => {
    const a = await acceptUnsigned(authenticatedRequest, request)
    const b = await acceptUnsigned(authenticatedRequest, request)
    const ids = { member_ids: [a.memberId, b.memberId] }

    // Without the key: the line is left for a pen, and the four digits beside
    // it are all the page knows.
    const blank = await print(authenticatedRequest, ids)
    expect(blank.status()).toBe(200)
    expect(blank.headers()['cache-control']).toBe('no-store')

    const blankText = pdfText(await blank.body())
    for (const printed of [a, b]) {
      expect(blankText).not.toContain(groupedIban(printed.iban))
      expect(blankText).not.toContain(printed.iban)
      expect(blankText).toContain(`endet auf ****${printed.iban.slice(-4)}`)
    }

    // With the key and a step-up: the same sheets, the number printed.
    const keyed = await print(authenticatedRequest, {
      ...ids,
      ...stepUp(),
      private_key: await activePrivateKey(authenticatedRequest),
    })
    expect(keyed.status(), await keyed.text()).toBe(200)
    expect(keyed.headers()['cache-control']).toBe('no-store')
    expect(keyed.headers()['x-mandate-sheets']).toBe('2')
    expect(keyed.headers()['x-mandate-sheets-without-iban']).toBe('0')

    const keyedPdf = await keyed.body()
    const keyedText = pdfText(keyedPdf)
    for (const printed of [a, b]) {
      expect(keyedText).toContain(groupedIban(printed.iban))
    }
    expect(pdfPageCount(keyedPdf)).toBe(TEMPLATE_PAGES * 2)
  })

  test('the keyed print asks what the SEPA export asks', async ({ request, authenticatedRequest }) => {
    const { memberId } = await acceptUnsigned(authenticatedRequest, request)
    const privateKey = await activePrivateKey(authenticatedRequest)

    // No credential: the key alone opens nothing.
    const noStepUp = await print(authenticatedRequest, { member_ids: [memberId], private_key: privateKey })
    expect(noStepUp.status()).toBe(401)
    expect((await noStepUp.json()).error).toBe('invalid_credentials')

    // A key that is not the club's, refused by comparison — never by trying it.
    const wrongKey = await print(authenticatedRequest, {
      member_ids: [memberId],
      ...stepUp(),
      private_key: WRONG_PRIVATE_KEY,
    })
    expect(wrongKey.status()).toBe(422)
    expect((await wrongKey.json()).error).toBe('private_key_mismatch')

    // An empty key is not "the blank print, please".
    const emptyKey = await print(authenticatedRequest, { member_ids: [memberId], private_key: '' })
    expect(emptyKey.status()).toBe(422)
  })

  test('a signed mandate drops out of the print, and an empty print is refused by name', async ({
    request,
    authenticatedRequest,
  }) => {
    const { memberId } = await acceptUnsigned(authenticatedRequest, request)

    expect((await print(authenticatedRequest, { member_ids: [memberId] })).status()).toBe(200)

    await authenticatedRequest.patch(`${API}/admin/members/${memberId}`, {
      data: { mandate_signed_at: '2026-11-28' },
    })

    const response = await print(authenticatedRequest, { member_ids: [memberId] })
    expect(response.status()).toBe(409)
    expect((await response.json()).reason).toBe('no_open_mandates')
  })

  test('each sheet is on the audit log against its member, with the masked IBAN only', async ({
    request,
    authenticatedRequest,
  }) => {
    const { memberId, iban } = await acceptUnsigned(authenticatedRequest, request)

    await print(authenticatedRequest, { member_ids: [memberId] })
    await print(authenticatedRequest, {
      member_ids: [memberId],
      ...stepUp(),
      private_key: await activePrivateKey(authenticatedRequest),
    })

    const response = await authenticatedRequest.get(
      `${API}/admin/audit-log?entity_id=${memberId}&per_page=50`,
    )
    expect(response.status()).toBe(200)
    const entries = ((await response.json()).data ?? []).filter(
      (entry: any) => entry.entity_id === memberId,
    )
    const actions = entries.map((entry: any) => entry.action)

    // No audit action was added for this: the two that exist say it.
    expect(actions).toContain('registration_printed')
    expect(actions).toContain('iban_full_view')

    for (const entry of entries.filter((e: any) => ['registration_printed', 'iban_full_view'].includes(e.action))) {
      expect(entry.entity_type).toBe('member')
      expect(entry.new_values.iban).toBe(`****${iban.slice(-4)}`)
    }
    expect(JSON.stringify(entries)).not.toContain(iban)
  })

  // ── who may do any of this ───────────────────────────────────────────────

  test('a Kassenwart may accept and print; a Getränkewart is refused on every new route', async ({
    request,
    kassenwartRequest,
    getraenkewartRequest,
  }) => {
    const single = await submit(request)
    const batched = await submit(request)

    // The bar office first, so the registrations are still pending when the
    // treasurer acts on them.
    const refusals = [
      await getraenkewartRequest.post(`${API}/admin/registrations/${single.id}/approve`, { data: {} }),
      await getraenkewartRequest.post(`${API}/admin/registrations/approve-batch`, {
        data: { ids: [batched.id] },
      }),
      await getraenkewartRequest.post(`${API}/admin/members/mandate-documents`, { data: {} }),
      await getraenkewartRequest.post(`${API}/admin/members/mandate-documents`, {
        data: { private_key: WRONG_PRIVATE_KEY, current_password: 'irrelevant' },
      }),
    ]
    for (const refusal of refusals) {
      expect(refusal.status()).toBe(403)
      expect((await refusal.json()).error).toBe('insufficient_role')
    }

    // Nothing was accepted on the way to being refused.
    expect((await kassenwartRequest.get(`${API}/admin/registrations/${single.id}`)).status()).toBe(200)

    // The treasurer does all three.
    const accepted = await kassenwartRequest.post(`${API}/admin/registrations/${single.id}/approve`, {
      data: {},
    })
    expect(accepted.status()).toBe(201)

    const batch = await kassenwartRequest.post(`${API}/admin/registrations/approve-batch`, {
      data: { ids: [batched.id] },
    })
    expect(batch.status()).toBe(200)
    const batchResult = await batch.json()
    expect(batchResult.accepted).toHaveLength(1)

    const printed = await kassenwartRequest.post(`${API}/admin/members/mandate-documents`, {
      data: { member_ids: [(await accepted.json()).id, batchResult.accepted[0].member_id] },
    })
    expect(printed.status()).toBe(200)
    expect(pdfPageCount(await printed.body())).toBe(TEMPLATE_PAGES * 2)
  })
})
