import { expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { test } from '../../fixtures/roleRequests'
import {
  clearRegistrationAttempts,
  configureSelfRegistration,
  countPendingRegistrations,
  expireRegistration,
  pendingRowsContainingPlaintext,
  restoreClubDocumentUrl,
} from '../../utils/sql'
import { drainMailQueue } from '../../utils/drain'

/**
 * The public submission endpoint (UC-P01, ADR-0052).
 *
 * Every request here uses the plain `request` fixture — no session, no bearer
 * token — because that is the whole point of the surface: an anonymous phone in
 * a clubhouse, holding a secret printed on a poster.
 *
 * The club's configuration is written through `sql.ts` rather than an API,
 * because the secret is minted server-side and shown once; no endpoint hands a
 * *known* secret back on demand, and one that did would defeat the credential.
 *
 * ### Why the review endpoints (#779) are in this file and not their own
 *
 * They need a pending registration to review, and the honest way to get one is
 * to submit it — which needs the poster secret, which is the same singleton
 * config row this file already serialises around. A second spec file writing
 * that row would clobber this one from another worker, and the failure would
 * read as "a valid secret answered 404" in whichever file lost the race. One
 * file, one serial block, one owner of that row.
 *
 * It buys something as well as costing a longer file: these tests carry a real
 * submission all the way through to a real member, so the claim that matters
 * most — the IBAN sealed by a stranger's phone is the one the club will collect
 * on — is asserted across the whole chain rather than at either end of it.
 */

const API = 'http://localhost:8080/api'
const TEST_IBAN = 'DE89370400440532013000'

/**
 * A second account, at a different bank, for the edit-before-approve path.
 *
 * Its BLZ (37050299 — Kreissparkasse Köln) is one the seed actually carries, and
 * that is the point: the bank name is resolved from the BLZ at write time and
 * can never be resolved again once the row is sealed (ADR-0036), so a
 * corrected IBAN landing with the *old* bank's name would be invisible
 * afterwards. Picking an unseeded BLZ would have asserted only that the lookup
 * returned null.
 */
const CORRECTED_IBAN = 'DE39370502991234567890'

/**
 * Run `bin/cron.php` for its purge tick without delivering any mail.
 *
 * The empty DSN is load-bearing: a drain claims the whole queue, so a run with
 * a transport configured would deliver messages other specs are waiting to
 * assert on (CLAUDE.md's note on why the stack's own MAIL_DSN is empty).
 */
function runPurgeTick(): void {
  drainMailQueue({ dsn: '' })
}

/** Unique per test, so specs running in parallel never share a poster. */
function uniqueSecret(): string {
  return `secret-${randomUUID()}`
}

function submission(overrides: Record<string, unknown> = {}) {
  const unique = randomUUID().slice(0, 8)

  return {
    first_name: 'Lena',
    last_name: `Brandt-${unique}`,
    email: `lena-${unique}@example.org`,
    date_of_birth: '2010-04-02',
    preferred_language: 'de',
    iban: TEST_IBAN,
    ...overrides,
  }
}

test.describe('Public self-registration', () => {
  /**
   * Serial, and it has to be.
   *
   * `self_registration_config` is a **single row** — one club, one poster
   * secret, one switch — which is right for the product and fatal for parallel
   * tests: each spec below writes the secret it is about to present, and under
   * `fullyParallel` the next worker overwrites it mid-flight, so a valid secret
   * comes back as the uniform 404. That is not a flake to retry; it is shared
   * state, and Pattern 001's answer — unique data per test — cannot apply to a
   * row the schema allows exactly one of.
   *
   * Nothing else in the suite touches this table, so serialising this file is
   * enough; the rest of `api-tests` keeps its four workers.
   */
  test.describe.configure({ mode: 'serial' })

  // The rate-limit meter is per source address, and every spec here arrives
  // from the same one. Starting each from an empty meter keeps a 429 meaning
  // "the throttle works" rather than "the previous test spent the budget".
  test.beforeEach(() => {
    clearRegistrationAttempts()
  })

  /**
   * Leave `sepa_config.mandate_template_url` set, always.
   *
   * This block is serial, but only *within this file* — the rest of
   * `api-tests` runs beside it on three other workers, and that column is not
   * ours. `SepaConfigDto` treats an empty one as incomplete SEPA configuration,
   * so the fail-closed test below, which nulls it deliberately, would otherwise
   * leave a window in which a concurrent `settlements.spec.ts` export fails for
   * a reason that has nothing to do with settlements. Restoring after every
   * test closes the window even when one fails part-way.
   */
  test.afterEach(() => {
    restoreClubDocumentUrl()
  })

  test('a submission behind the poster secret is accepted and sealed', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const before = countPendingRegistrations()

    const response = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission({ account_holder_name: 'Petra Brandt' }) },
    })

    expect(response.status()).toBe(201)
    const body = await response.json()
    expect(body.id).toMatch(/^[0-9a-f-]{36}$/)
    // Minted at submission, in ADR-0006's format, because it is printed on the
    // paper before the mandate exists.
    expect(body.mandate_reference).toMatch(/^[0-9a-f]{32}$/)

    expect(countPendingRegistrations()).toBe(before + 1)

    // The guarantee the store exists to keep, asserted from outside the app.
    expect(pendingRowsContainingPlaintext(TEST_IBAN)).toBe(0)
  })

  test('the response is a receipt and never reads anything back', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const response = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission() },
    })

    const body = await response.json()
    expect(Object.keys(body).sort()).toEqual(['id', 'mandate_reference'])
    // Nothing about the person, the club, or what else is stored.
    expect(JSON.stringify(body)).not.toContain('3000')
  })

  test('a wrong secret and a missing one answer identically', async ({ request }) => {
    configureSelfRegistration(uniqueSecret())

    const wrong = await request.post(`${API}/public/registrations`, {
      data: { secret: 'not-the-secret', ...submission() },
    })
    const missing = await request.post(`${API}/public/registrations`, {
      data: submission(),
    })

    expect(wrong.status()).toBe(404)
    expect(missing.status()).toBe(404)
    // Byte-identical: anything that told them apart would confirm to a prober
    // that a valid secret exists.
    expect(await wrong.text()).toBe(await missing.text())
  })

  test('while disabled, the right secret is still refused — with the club’s reason', async ({
    request,
  }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret, { enabled: false, disabledReason: 'Beta-Phase schon voll' })

    const before = countPendingRegistrations()
    const response = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission() },
    })

    expect(response.status()).toBe(409)
    const body = await response.json()
    expect(body.reason).toBe('registration_disabled')
    expect(body.params.reason).toBe('Beta-Phase schon voll')
    // Hiding the form is not the gate; the endpoint refuses on its own.
    expect(countPendingRegistrations()).toBe(before)
  })

  test('with no club document configured it fails closed', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret, { documentUrl: null })

    const before = countPendingRegistrations()
    const response = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission() },
    })

    expect(response.status()).toBe(409)
    expect((await response.json()).reason).toBe('document_url_missing')
    // Collecting from somebody who was never shown a notice is the failure this
    // condition exists to prevent.
    expect(countPendingRegistrations()).toBe(before)
  })

  test('a duplicate submission is accepted exactly like a first one', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const data = submission()
    const first = await request.post(`${API}/public/registrations`, { data: { secret, ...data } })
    const second = await request.post(`${API}/public/registrations`, { data: { secret, ...data } })

    expect(first.status()).toBe(201)
    expect(second.status()).toBe(second.status())
    expect(second.status()).toBe(201)

    const firstBody = await first.json()
    const secondBody = await second.json()
    expect(secondBody.id).not.toBe(firstBody.id)
    expect(secondBody.mandate_reference).not.toBe(firstBody.mandate_reference)
  })

  test('a filled honeypot looks accepted and stores nothing', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const before = countPendingRegistrations()
    const response = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission(), website: 'http://spam.example' },
    })

    expect(response.status()).toBe(201)
    const body = await response.json()
    expect(body.id).toMatch(/^[0-9a-f-]{36}$/)
    // Indistinguishable from a real receipt, and nothing was written.
    expect(countPendingRegistrations()).toBe(before)
  })

  test('a rejected field is named, and nothing is stored', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const before = countPendingRegistrations()
    const response = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission({ iban: 'DE89370400440532013001', email: 'not-an-address' }) },
    })

    expect(response.status()).toBe(422)
    const body = await response.json()
    expect(body.messages).toHaveProperty('iban')
    expect(body.messages).toHaveProperty('email')
    expect(countPendingRegistrations()).toBe(before)
  })

  test('the secret is never named in a validation error', async ({ request }) => {
    configureSelfRegistration(uniqueSecret())

    const response = await request.post(`${API}/public/registrations`, {
      data: { secret: 'wrong-but-that-is-not-the-point', ...submission({ first_name: '' }) },
    })

    // Validation runs first, so this is a 422 — and it must not disclose that
    // `secret` is even a field, let alone that this one was wrong.
    expect(response.status()).toBe(422)
    expect(await response.text()).not.toContain('secret')
  })

  test('an oversized body is refused before it is parsed', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const response = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission({ first_name: 'x'.repeat(20000) }) },
    })

    expect(response.status()).toBe(413)
    expect((await response.json()).error).toBe('payload_too_large')
  })

  test('an expired registration is purged by a real cron run, a fresh one survives', async ({
    request,
  }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const stale = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission() },
    })
    const fresh = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission() },
    })
    const staleId = (await stale.json()).id
    const freshId = (await fresh.json()).id

    expireRegistration(staleId)
    const before = countPendingRegistrations()

    // `bin/cron.php` is the one entrypoint, so the purge tick is reached
    // through the drain helper — but with an **empty** MAIL_DSN. A drain claims
    // the whole queue, and delivering here would sweep announcements the admin
    // and mail specs are asserting on. The tick under test writes no mail.
    runPurgeTick()

    expect(countPendingRegistrations()).toBe(before - 1)
    // The fresh one is untouched: the purge takes only what has expired.
    expireRegistration(freshId)
    runPurgeTick()
    expect(countPendingRegistrations()).toBe(before - 2)
  })

  // ── the review inbox (#779, UC-A17) ────────────────────────────────────
  //
  // Each of these submits through the *public* endpoint first, so what is being
  // reviewed is a real submission and not a hand-written row. That is what
  // makes the sealed-material assertion below worth anything: the IBAN a
  // stranger's phone sealed is the one the club ends up with a mandate on.

  /** Submit one registration and return its id and its details. */
  const submitOne = async (
    request: any,
    overrides: Record<string, unknown> = {},
  ): Promise<{ id: string; mandateReference: string; data: Record<string, unknown> }> => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const data = submission(overrides)
    const response = await request.post(`${API}/public/registrations`, {
      data: { secret, ...data },
    })
    expect(response.status()).toBe(201)
    const body = await response.json()

    return { id: body.id, mandateReference: body.mandate_reference, data }
  }

  test('the inbox lists a submission, masked, with no sealed material', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id, data } = await submitOne(request)

    const response = await authenticatedRequest.get(`${API}/admin/registrations?per_page=100`)
    expect(response.status()).toBe(200)

    const body = await response.json()
    // Pattern 003: find the row by id rather than assuming a position.
    const row = body.data.find((r: any) => r.id === id)
    expect(row).toBeTruthy()
    expect(row.email).toBe(data.email)
    expect(row.iban_masked).toBe('****3000')

    // The two values that must never leave the server: the ciphertext is the
    // IBAN, and the fingerprint identifies the account to anyone holding a
    // candidate number.
    expect(row).not.toHaveProperty('iban_ciphertext')
    expect(row).not.toHaveProperty('iban_fingerprint')
    expect(JSON.stringify(body)).not.toContain(TEST_IBAN)
  })

  test('the detail view carries the notice the applicant was shown', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id, mandateReference } = await submitOne(request)

    const response = await authenticatedRequest.get(`${API}/admin/registrations/${id}`)
    expect(response.status()).toBe(200)

    const body = await response.json()
    expect(body.mandate_reference).toBe(mandateReference)
    // The club's evidence that the Datenschutzhinweise were reachable before
    // anything was collected (ADR-0052 decision 6).
    expect(body.privacy_notice_url).toBe('https://club.example/Anmeldung_Ruderbar.pdf')
    expect(body.privacy_notice_shown_at).toBeTruthy()
    expect(body.expires_at).toBeTruthy()
  })

  test('an edit corrects a typo and re-seals a corrected IBAN', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id } = await submitOne(request, { first_name: 'Lenna' })

    const before = await (await authenticatedRequest.get(`${API}/admin/registrations/${id}`)).json()
    expect(before.bank_name).toBe('Commerzbank')

    const response = await authenticatedRequest.patch(`${API}/admin/registrations/${id}`, {
      data: { first_name: 'Lena', iban: CORRECTED_IBAN },
    })

    expect(response.status()).toBe(200)
    const body = await response.json()
    expect(body.first_name).toBe('Lena')
    // The whole quartet moved: a new last four, and a bank name re-resolved
    // from the new BLZ — there is nothing left to derive it from afterwards.
    expect(body.iban_masked).toBe('****7890')
    expect(body.bank_name).toBe('Kreissparkasse Köln')

    // And the corrected number is no more readable in the database than the
    // original was.
    expect(pendingRowsContainingPlaintext(CORRECTED_IBAN)).toBe(0)
  })

  test('an edit cannot rewrite the reference printed on the signed paper', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id, mandateReference } = await submitOne(request)

    const response = await authenticatedRequest.patch(`${API}/admin/registrations/${id}`, {
      data: { mandate_reference: 'somebodyelses', first_name: 'Lena' },
    })

    expect(response.status()).toBe(200)
    // Ignored rather than refused — a form echoing the whole row back should
    // not fail — but it is not an admin's to change (ADR-0006).
    expect((await response.json()).mandate_reference).toBe(mandateReference)
  })

  test('an invalid edit is refused and changes nothing', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id, data } = await submitOne(request)

    const response = await authenticatedRequest.patch(`${API}/admin/registrations/${id}`, {
      data: { email: 'not-an-address' },
    })

    expect(response.status()).toBe(422)
    expect((await response.json()).messages).toHaveProperty('email')

    const after = await authenticatedRequest.get(`${API}/admin/registrations/${id}`)
    expect((await after.json()).email).toBe(data.email)
  })

  test('approval creates the member and mandate, and empties the queue', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id, mandateReference, data } = await submitOne(request)
    const before = countPendingRegistrations()

    const response = await authenticatedRequest.post(
      `${API}/admin/registrations/${id}/approve`,
      { data: { mandate_signed_at: '2026-08-30', signed_mandate_confirmed: true } },
    )

    expect(response.status()).toBe(201)
    const member = await response.json()
    expect(member.email).toBe(data.email)
    // The reference minted at submission travels to the mandate unchanged: it
    // is what the bank will see on the collection, and it is printed on the
    // paper this member signed.
    expect(member.mandate_reference).toBe(mandateReference)
    expect(member.mandate_signed_at).toBe('2026-08-30')
    expect(member.iban_masked).toBe('****3000')
    // No card yet — that is a separate, deliberate step, and it is the card
    // that welcomes them (ADR-0021, UC-A67).
    expect(member.card_uid).toBeNull()

    expect(countPendingRegistrations()).toBe(before - 1)

    // Reachable as an ordinary member from here on, and gone from the queue.
    const fetched = await authenticatedRequest.get(`${API}/admin/members/${member.id}`)
    expect(fetched.status()).toBe(200)
    const gone = await authenticatedRequest.get(`${API}/admin/registrations/${id}`)
    expect(gone.status()).toBe(404)
  })

  test('approval without the attestation is refused, and the row survives', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id } = await submitOne(request)

    const silent = await authenticatedRequest.post(`${API}/admin/registrations/${id}/approve`, {
      data: { mandate_signed_at: '2026-08-30' },
    })
    // A request that said nothing about the attestation is a validation error.
    expect(silent.status()).toBe(422)
    expect((await silent.json()).messages).toHaveProperty('signed_mandate_confirmed')

    const refused = await authenticatedRequest.post(`${API}/admin/registrations/${id}/approve`, {
      data: { mandate_signed_at: '2026-08-30', signed_mandate_confirmed: false },
    })
    // An explicit `false` is somebody stating they cannot attest — a different
    // conversation, and one the panel translates.
    expect(refused.status()).toBe(409)
    expect((await refused.json()).reason).toBe('registration_attestation_required')

    const still = await authenticatedRequest.get(`${API}/admin/registrations/${id}`)
    expect(still.status()).toBe(200)
  })

  test('approving twice creates one member, not two', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id } = await submitOne(request)
    const payload = { data: { mandate_signed_at: '2026-08-30', signed_mandate_confirmed: true } }

    const first = await authenticatedRequest.post(`${API}/admin/registrations/${id}/approve`, payload)
    expect(first.status()).toBe(201)

    const second = await authenticatedRequest.post(`${API}/admin/registrations/${id}/approve`, payload)
    expect(second.status()).toBe(404)
  })

  test('an email the club already has refuses the approval by name', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id, data } = await submitOne(request)
    const payload = { data: { mandate_signed_at: '2026-08-30', signed_mandate_confirmed: true } }

    expect(
      (await authenticatedRequest.post(`${API}/admin/registrations/${id}/approve`, payload)).status(),
    ).toBe(201)

    // The same person submits again — and this time the club already has them.
    const again = await submitOne(request, { email: data.email as string })

    const response = await authenticatedRequest.post(
      `${API}/admin/registrations/${again.id}/approve`,
      payload,
    )

    // `members.email` carries no UNIQUE constraint, so nothing below would have
    // refused: the club would have ended up with two member records for one
    // person and found out at the next settlement, when both got a statement.
    expect(response.status()).toBe(409)
    expect((await response.json()).reason).toBe('registration_member_email_exists')

    const flagged = await authenticatedRequest.get(`${API}/admin/registrations/${again.id}`)
    expect((await flagged.json()).duplicate_email).toBe(true)
  })

  test('rejection deletes the submission and creates nothing', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id } = await submitOne(request)
    const before = countPendingRegistrations()

    const response = await authenticatedRequest.post(`${API}/admin/registrations/${id}/reject`, {
      data: { reason: 'No signed mandate arrived' },
    })

    expect(response.status()).toBe(204)
    expect(countPendingRegistrations()).toBe(before - 1)
    expect((await authenticatedRequest.get(`${API}/admin/registrations/${id}`)).status()).toBe(404)
  })

  /**
   * Pattern 011, and the ADR-0044 derivation this route was classified by: the
   * review inbox is member management arriving by a different door, so the
   * Kassenwart works it and the Getränkewart is nowhere near it — same as
   * `GET /api/admin/members`, and for the same reason.
   */
  test('the Kassenwart can work the queue; the Getränkewart cannot see it', async ({
    request,
    kassenwartRequest,
    getraenkewartRequest,
  }) => {
    const { id } = await submitOne(request)

    const treasurerList = await kassenwartRequest.get(`${API}/admin/registrations?per_page=100`)
    expect(treasurerList.status()).toBe(200)
    expect((await treasurerList.json()).data.some((r: any) => r.id === id)).toBe(true)

    const treasurerApproval = await kassenwartRequest.post(
      `${API}/admin/registrations/${id}/approve`,
      { data: { mandate_signed_at: '2026-08-30', signed_mandate_confirmed: true } },
    )
    expect(treasurerApproval.status()).toBe(201)

    const barList = await getraenkewartRequest.get(`${API}/admin/registrations`)
    expect(barList.status()).toBe(403)
    expect((await barList.json()).error).toBe('insufficient_role')

    const barDetail = await getraenkewartRequest.get(`${API}/admin/registrations/${id}`)
    // 403 and not 404: the refusal is about the caller's role, and it must not
    // depend on whether the row exists — an answer that varied would let a
    // Getränkewart probe the queue they cannot read.
    expect(barDetail.status()).toBe(403)
  })

  test('an anonymous caller reaches none of the review endpoints', async ({ request }) => {
    const { id } = await submitOne(request)

    for (const call of [
      request.get(`${API}/admin/registrations`),
      request.get(`${API}/admin/registrations/${id}`),
      request.patch(`${API}/admin/registrations/${id}`, { data: { first_name: 'X' } }),
      request.post(`${API}/admin/registrations/${id}/approve`, {
        data: { mandate_signed_at: '2026-08-30', signed_mandate_confirmed: true },
      }),
      request.post(`${API}/admin/registrations/${id}/reject`, { data: {} }),
    ]) {
      const response = await call
      expect([401, 403]).toContain(response.status())
    }

    // Nothing happened: the submission is still there to review.
    expect(countPendingRegistrations()).toBeGreaterThan(0)
  })
})
