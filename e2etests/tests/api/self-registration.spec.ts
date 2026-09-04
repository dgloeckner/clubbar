import { expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { test } from '../../fixtures/roleRequests'
import {
  clearRegistrationAttempts,
  clubInstanceName,
  configureSelfRegistration,
  countPendingRegistrations,
  execSql,
  expireRegistration,
  CLUB_DOCUMENT_URL,
  pendingRowsContainingPlaintext,
  queuedRegistrationLinks,
  restoreClubDocumentUrl,
  serveClubDocument,
  servePrefilledClubDocument,
  stopServingClubDocument,
  stopServingPrefilledClubDocument,
  PREFILLED_DOCUMENT_URL,
} from '../../utils/sql'
import { lockSelfRegistration, unlockSelfRegistration } from '../../utils/registrationLock'
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

  /**
   * One writer at a time on `self_registration_config` (#784).
   *
   * `mode: 'serial'` orders this file's tests and says nothing about the three
   * other spec files that write the same singleton row. Without the lock, a
   * concurrent worker overwrites the secret between the write and the request
   * presenting it, and the valid secret comes back as the uniform 404 — a
   * failure reported by whichever file lost the race rather than the one that
   * caused it. Held for the whole test, because the window spans the write and
   * the round trip that presents what was written.
   */
  test.beforeEach(() => {
    lockSelfRegistration()
  })

  test.afterEach(() => {
    unlockSelfRegistration()
  })

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

  /**
   * Leave the club switched on, whatever this test did to it (#784).
   *
   * A leaked disabled state fails *other* specs, with a refusal that is
   * entirely correct for a club that is off — so the report accuses whichever
   * file ran next. In SQL rather than through the API because switching off
   * needs a reason and this has to work from any state, including one a failed
   * test left half-applied.
   */
  test.afterEach(() => {
    execSql('UPDATE self_registration_config SET enabled = 1, disabled_reason = NULL WHERE id = 1')
  })

  /**
   * Serve a real Anmeldung the backend can fetch (#780).
   *
   * The document paths fetch the configured URL over HTTP from inside the
   * backend container, so a URL on somebody's real website would make this
   * suite depend on their webhost. The fixture — a genuine WeasyPrint
   * `--pdf-forms --uncompressed-pdf` build — is copied into the backend's own
   * web root instead, which is both reachable and honest: what is asserted
   * below is the real pipeline, not a stand-in.
   */
  test.beforeAll(() => {
    serveClubDocument()
    servePrefilledClubDocument()
  })

  test.afterAll(() => {
    stopServingClubDocument()
    stopServingPrefilledClubDocument()
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
    expect(Object.keys(body).sort()).toEqual(['document', 'id', 'mandate_reference'])

    // The claim is about what the endpoint *read back*, not about size. Two of
    // these three fields it just minted, and the third is the caller's own
    // submission drawn onto the club's public document — so `****3000` on that
    // sheet is their own IBAN on their own mandate, not a disclosure.
    //
    // What must not be here is anything from storage: whether the club already
    // knows this person, how many registrations are pending, what the club has
    // configured. So the assertion is on everything except the document.
    const { document: _document, ...saidBack } = body
    expect(Object.keys(saidBack).sort()).toEqual(['id', 'mandate_reference'])
    expect(JSON.stringify(saidBack)).not.toContain('3000')
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
    expect(body.privacy_notice_url).toBe(CLUB_DOCUMENT_URL)
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

  // ── the club's document (#780, ADR-0052 decision 5) ────────────────────

  /** Inflate a PDF's content streams, so drawn text is searchable. */
  const pdfText = (pdf: Buffer): string => {
    const zlib = require('node:zlib')
    let text = pdf.toString('latin1')

    // FPDF compresses the stream it draws into, and the imported pages arrive
    // Flate-compressed too, so nothing on a page is literal in the raw file.
    const pattern = /stream\r?\n([\s\S]*?)\r?\nendstream/g
    let match: RegExpExecArray | null
    while ((match = pattern.exec(pdf.toString('latin1'))) !== null) {
      try {
        text += zlib.inflateSync(Buffer.from(match[1], 'latin1')).toString('latin1')
      } catch {
        // Not a compressed stream — already counted in the raw text above.
      }
    }

    return text
  }

  test("the member's own document comes back with the submission, and only there", async ({
    request,
  }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const response = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission({ account_holder_name: 'Petra Brandt' }) },
    })

    expect(response.status()).toBe(201)
    // The one response in this API whose body is a bank detail.
    expect(response.headers()['cache-control']).toBe('no-store')

    const body = await response.json()
    expect(body.document).toBeTruthy()

    const pdf = Buffer.from(body.document, 'base64')
    expect(pdf.subarray(0, 5).toString()).toBe('%PDF-')

    const text = pdfText(pdf)
    // Grouped in fours, the way it is printed and the way somebody reads it
    // back to their bank.
    expect(text).toContain('DE89 3704 0044 0532 0130 00')
    expect(text).toContain('Petra Brandt')
    expect(text).toContain('****3000')

    // Flattened by construction: page 1 is imported without its annotations, so
    // there is no form left to fill or to edit.
    expect(pdf.toString('latin1')).not.toContain('/AcroForm')
  })

  test('every page of the club document survives the fill', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const response = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission() },
    })
    const pdf = Buffer.from((await response.json()).document, 'base64')

    // The fixture is three pages: the form page, the Datenschutzhinweise and
    // the Nutzungsordnung. The member signs the club's paper whole — an output
    // that kept only page 1 would be a different document wearing its cover.
    const pages = (pdf.toString('latin1').match(/\/Type \/Page[^s]/g) ?? []).length
    expect(pages).toBe(3)
  })

  /**
   * The fail-soft rule: a club webhost outage must not cost a registration that
   * has already been written. The submission stands and the document is simply
   * absent — and nothing else is substituted, because handing the applicant a
   * *different* mandate would be one they never read, missing the very pages
   * they were pointed at.
   */
  test('an unreachable club document costs the document, not the registration', async ({
    request,
  }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret, { documentUrl: 'http://localhost/nothing-is-here.pdf' })

    const before = countPendingRegistrations()
    const response = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission() },
    })

    expect(response.status()).toBe(201)
    const body = await response.json()
    expect(body.id).toMatch(/^[0-9a-f-]{36}$/)
    expect(body.document).toBeNull()
    expect(countPendingRegistrations()).toBe(before + 1)
  })

  test("the admin print leaves the IBAN blank and prints the hint", async ({
    request,
    authenticatedRequest,
  }) => {
    const { id } = await submitOne(request)

    const response = await authenticatedRequest.get(`${API}/admin/registrations/${id}/document`)

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toContain('application/pdf')
    expect(response.headers()['cache-control']).toBe('no-store')

    const text = pdfText(Buffer.from(await response.body()))

    // The distinction ADR-0036 rests on: the debtor IBAN is mandatory mandate
    // content, not mandatory *machine-printed* content. The member writes it
    // into the comb by hand at signature; the admin checks it against the hint.
    expect(text).not.toContain('DE89 3704')
    expect(text).not.toContain(TEST_IBAN)
    expect(text).toContain('endet auf ****3000')
  })

  /**
   * The variant that still works weeks later. It needs no plaintext IBAN at
   * all — nobody on the server has one — so a member whose phone lost the tab
   * is never stuck.
   */
  test('the admin print still works long after the plaintext is gone', async ({
    request,
    authenticatedRequest,
  }) => {
    const { id } = await submitOne(request)

    for (const attempt of [1, 2]) {
      const response = await authenticatedRequest.get(`${API}/admin/registrations/${id}/document`)
      expect(response.status(), `attempt ${attempt}`).toBe(200)
      expect((await response.body()).subarray(0, 5).toString()).toBe('%PDF-')
    }
  })

  test('a club document that is not a PDF is refused by name', async ({
    request,
    authenticatedRequest,
  }) => {
    // The bad URL has to be configured *before* the submission, not after.
    // A print renders the document the applicant was actually pointed at —
    // recorded on their row — rather than whatever the club has configured
    // today, so that a club republishing its Anmeldung cannot silently change
    // the terms of a submission made last week. Reconfiguring afterwards
    // therefore changes nothing about this row, which is the point.
    //
    // `localhost` with no port, because the fetch runs *inside* the backend
    // container where nginx listens on 80 — the runner's `:8080` resolves to
    // nothing there, and the test would pass for the wrong reason
    // (unreachable rather than not-a-PDF).
    const secret = uniqueSecret()
    configureSelfRegistration(secret, { documentUrl: 'http://localhost/api/health' })

    const submitted = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission() },
    })
    // The submission itself stands: an unusable document is not the
    // applicant's problem, and they have already typed everything in.
    expect(submitted.status()).toBe(201)
    const body = await submitted.json()
    expect(body.document).toBeNull()

    const response = await authenticatedRequest.get(`${API}/admin/registrations/${body.id}/document`)

    expect(response.status()).toBe(409)
    expect((await response.json()).reason).toBe('document_template_not_a_pdf')
  })

  test('the Getränkewart cannot print somebody else’s mandate', async ({
    request,
    getraenkewartRequest,
  }) => {
    const { id } = await submitOne(request)

    const response = await getraenkewartRequest.get(`${API}/admin/registrations/${id}/document`)

    expect(response.status()).toBe(403)
  })

  // ── the entry lookup (#781) ────────────────────────────────────────────

  test('the context tells the page to render a form', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const response = await request.post(`${API}/public/registrations/context`, {
      data: { secret },
    })

    expect(response.status()).toBe(200)
    // A page still showing yesterday's answer is what this endpoint exists to
    // prevent: the club's switch takes effect the moment it is flipped.
    expect(response.headers()['cache-control']).toBe('no-store')

    const body = await response.json()
    expect(body.available).toBe(true)
    expect(body.reason).toBeNull()
    expect(body.document_url).toBe(CLUB_DOCUMENT_URL)
    expect(body.languages).toContain('de')
    // The done screen's "deleted after N days" sentence reads N from here
    // rather than from its own markup (#804).
    expect(body.retention_days).toBeGreaterThan(0)
  })

  test('a paused club answers with its own words, as a 200 rather than a refusal', async ({
    request,
  }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret, { enabled: false, disabledReason: 'Beta-Phase schon voll' })

    const response = await request.post(`${API}/public/registrations/context`, {
      data: { secret },
    })

    // The page is asking a question and this is the answer. Only a bad secret
    // is a refusal.
    expect(response.status()).toBe(200)
    const body = await response.json()
    expect(body.available).toBe(false)
    expect(body.reason).toBe('registration_disabled')
    expect(body.message).toBe('Beta-Phase schon voll')
  })

  test('the context fails closed with no club document', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret, { documentUrl: null })

    const response = await request.post(`${API}/public/registrations/context`, {
      data: { secret },
    })

    const body = await response.json()
    expect(body.available).toBe(false)
    expect(body.reason).toBe('document_url_missing')
  })

  /**
   * Uniform with the submit path, and for the same reason — but it matters more
   * here: this endpoint costs no form to try, so an oracle that confirmed a
   * valid secret exists would be the cheapest one in the system.
   */
  test('a wrong secret and a missing one answer identically here too', async ({ request }) => {
    configureSelfRegistration(uniqueSecret())

    const wrong = await request.post(`${API}/public/registrations/context`, {
      data: { secret: 'not-the-secret' },
    })
    const missing = await request.post(`${API}/public/registrations/context`, { data: {} })

    expect(wrong.status()).toBe(404)
    expect(missing.status()).toBe(404)
    expect(await wrong.text()).toBe(await missing.text())
  })

  test('the context reveals nothing about members or the queue', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const response = await request.post(`${API}/public/registrations/context`, {
      data: { secret },
    })

    // Club configuration a poster-holder is standing in front of anyway — and
    // nothing else. No counts, no names, no hint whether the club knows them.
    expect(Object.keys(await response.json()).sort()).toEqual([
      'available',
      'club_name',
      'document_url',
      'languages',
      'logo_url',
      'message',
      'reason',
      'retention_days',
    ])
  })

  /**
   * Who is asking (#781).
   *
   * A page that wants a name, a birth date and an IBAN and does not say whose
   * form it is is indistinguishable from a phishing page. The name comes from
   * `instance_config` — the same value the club's mail is signed with — so a
   * club that has branded one has branded the other.
   */
  test('the context names the club, so the page can say who is asking', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const response = await request.post(`${API}/public/registrations/context`, {
      data: { secret },
    })

    const body = await response.json()
    // Whatever the club is called right now, rather than a fixed string: this
    // row is a singleton another spec renames as part of its own assertions.
    expect(body.club_name).toBe(clubInstanceName())
    expect(body.club_name).not.toBe('')
  })

  /**
   * The page loads this every time it opens, and a member who reads the club's
   * document before filling anything in opens it twice. Charging that against
   * the submission budget would refuse the sixth honest visitor at a signup
   * evening for the crime of being careful.
   */
  test('reading the context does not spend the submission budget', async ({ request }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    for (let i = 0; i < 12; i++) {
      const response = await request.post(`${API}/public/registrations/context`, {
        data: { secret },
      })
      expect(response.status(), `context read ${i + 1}`).toBe(200)
    }

    const submitted = await request.post(`${API}/public/registrations`, {
      data: { secret, ...submission() },
    })
    expect(submitted.status()).toBe(201)
  })

  // ── the terminal gate (#784, ADR-0052 decision 9) ───────────────────────
  //
  // The claim the whole epic rests on: **a pending registration is not a
  // member**. Not a member with a flag, not a member awaiting activation — no
  // `members` row at all, so there is nothing for the terminal to return and
  // nothing for it to recognise. It is asserted here from the terminal's own
  // surface, with its own bearer token, because that is the only place the
  // claim is actually observable: the admin API deliberately shows the queue.

  test('a submitted registration is invisible to the terminal, and visible after approval', async ({
    request,
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const { id, data } = await submitOne(request)

    // The terminal payload carries no email (ADR-0045: the till has no business
    // knowing one), so the applicant is matched on the surname `submitOne`
    // makes unique per test — the same reason Pattern 003 searches by identity
    // rather than position.
    const surname = data.last_name as string

    const before = await authenticatedTerminalRequest.get(`${API}/sync/members?since=0`)
    expect(before.status()).toBe(200)
    const pending = (await before.json()).members ?? []
    expect(
      pending.filter((member: { last_name: string }) => member.last_name === surname),
      'a pending registration must not reach the terminal',
    ).toHaveLength(0)

    const approved = await authenticatedRequest.post(`${API}/admin/registrations/${id}/approve`, {
      data: { mandate_signed_at: '2026-08-30', signed_mandate_confirmed: true },
    })
    expect(approved.status()).toBe(201)

    const after = await authenticatedTerminalRequest.get(`${API}/sync/members?since=0`)
    const now = ((await after.json()).members ?? []).filter(
      (member: { last_name: string }) => member.last_name === surname,
    )
    expect(now, 'the approved member must reach the terminal').toHaveLength(1)

    // The mandate travelled with them: the till may serve somebody the club can
    // actually collect from, which is the point of the attestation gate.
    expect(now[0].is_sepa_valid).toBe(true)
    // And the terminal still learns nothing it has no business with — no email,
    // no IBAN in any form.
    expect(Object.keys(now[0])).not.toContain('email')
    expect(JSON.stringify(now[0])).not.toContain(TEST_IBAN)
  })

  test('a rejected registration reaches the terminal in neither direction', async ({
    request,
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const { id, data } = await submitOne(request)
    const surname = data.last_name as string

    const rejected = await authenticatedRequest.post(`${API}/admin/registrations/${id}/reject`, {
      data: { reason: 'Not a member of the club' },
    })
    expect(rejected.status()).toBe(204)

    // The mirror of the test above, and worth its own: a rejection deletes the
    // submission outright rather than marking it, so the failure it guards
    // against would be a *deleted* applicant somehow reaching the till.
    const sync = await authenticatedTerminalRequest.get(`${API}/sync/members?since=0`)
    const rows = ((await sync.json()).members ?? []).filter(
      (member: { last_name: string }) => member.last_name === surname,
    )
    expect(rows).toHaveLength(0)
  })

  // ── the club's own controls (#783, UC-A69) ──────────────────────────────
  //
  // These write the same singleton row every test above reads, which is why
  // they live inside this serial block rather than in a file of their own.
  // They also *undo* what they do: the row they mutate is the one the public
  // specs depend on, and a test that left registration switched off would fail
  // whichever spec ran next rather than itself.

  test('the settings answer describes the surface and carries no secret', async ({
    authenticatedRequest,
  }) => {
    configureSelfRegistration(uniqueSecret())

    const response = await authenticatedRequest.get(`${API}/admin/self-registration`)

    expect(response.status()).toBe(200)
    const body = await response.json()

    // The exact key set, asserted rather than spot-checked: the guarantee here
    // is about what is *absent*. A `secret`, `secret_hash` or `secret_cipher`
    // appearing later would be a live credential in every page load of this
    // screen, and nothing else in the suite would notice.
    expect(Object.keys(body).sort()).toEqual([
      'disabled_reason',
      'document_url',
      'enabled',
      'has_secret',
      'retention_days',
      'secret_rotated_at',
    ])
    expect(body.has_secret).toBe(true)
    expect(body.document_url).toBe(CLUB_DOCUMENT_URL)
  })

  test('rotating mints a new secret, kills the old one and never returns either', async ({
    request,
    authenticatedRequest,
  }) => {
    const old = uniqueSecret()
    configureSelfRegistration(old)

    const rotated = await authenticatedRequest.post(`${API}/admin/self-registration/secret`)
    expect(rotated.status()).toBe(200)

    const body = await rotated.json()
    // The answer is the settings, not the secret. The new poster is fetched by
    // the download below, so the credential travels in a PDF an admin prints
    // rather than in a JSON body a browser keeps.
    expect(Object.keys(body)).not.toContain('secret')
    expect(body.has_secret).toBe(true)
    expect(body.secret_rotated_at).toBeTruthy()

    // The poster on the wall is dead the moment this commits, and that is the
    // point of rotation — asserted from the public surface, which is where it
    // matters, and with the uniform 404 an unknown secret gets.
    const withOld = await request.post(`${API}/public/registrations/context`, {
      data: { secret: old },
    })
    expect(withOld.status()).toBe(404)

    // Put a known secret back: the specs above present one they wrote.
    configureSelfRegistration(uniqueSecret())
  })

  test('the poster carries a working secret and reprinting does not rotate', async ({
    authenticatedRequest,
  }) => {
    // Minted through the API, not through `sql.ts`, and it has to be: printing
    // reads the *sealed* copy back, and the SQL helper writes only the hash —
    // which is all the public surface needs and none of what a reprint does.
    const before = await (
      await authenticatedRequest.post(`${API}/admin/self-registration/secret`)
    ).json()

    const first = await authenticatedRequest.post(`${API}/admin/self-registration/poster`, {
      data: { language: 'de' },
    })

    expect(first.status()).toBe(200)
    expect(first.headers()['content-type']).toContain('application/pdf')
    // The body is the club's gate rendered as a picture; nothing between here
    // and the printer is entitled to keep a copy.
    expect(first.headers()['cache-control']).toContain('no-store')
    expect((await first.body()).subarray(0, 5).toString('latin1')).toBe('%PDF-')

    const second = await authenticatedRequest.post(`${API}/admin/self-registration/poster`, {
      data: { language: 'en' },
    })
    expect(second.status()).toBe(200)

    // Two prints, same secret. A club that had to rotate in order to reprint
    // would invalidate every poster in the building every time somebody spilled
    // a drink on one.
    const after = await (await authenticatedRequest.get(`${API}/admin/self-registration`)).json()
    expect(after.secret_rotated_at).toBe(before.secret_rotated_at)

    // Put a secret the public specs know back on the row.
    configureSelfRegistration(uniqueSecret())
  })

  test('switching off requires the club to say why, and the words reach the poster', async ({
    request,
    authenticatedRequest,
  }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    const silent = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
      data: { enabled: false },
    })
    expect(silent.status()).toBe(409)
    expect((await silent.json()).reason).toBe('registration_reason_required')

    // Still on: a refused switch changes nothing.
    const stillOn = await request.post(`${API}/public/registrations/context`, { data: { secret } })
    expect((await stillOn.json()).available).toBe(true)

    const off = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
      data: { enabled: false, disabled_reason: '  Wir pausieren bis zur Versammlung  ' },
    })
    expect(off.status()).toBe(200)
    // Trimmed on the way in — the sentence is shown to a member, not logged.
    expect((await off.json()).disabled_reason).toBe('Wir pausieren bis zur Versammlung')

    const paused = await request.post(`${API}/public/registrations/context`, { data: { secret } })
    const context = await paused.json()
    expect(context.available).toBe(false)
    expect(context.message).toBe('Wir pausieren bis zur Versammlung')

    // Back on, and the stale sentence goes with it: "we paused for the AGM"
    // left on a live surface is worse than no sentence at all.
    const on = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
      data: { enabled: true },
    })
    expect(on.status()).toBe(200)
    expect((await on.json()).disabled_reason).toBeNull()
  })

  test('a document URL is fetched and checked before it is stored', async ({
    authenticatedRequest,
  }) => {
    configureSelfRegistration(uniqueSecret())

    const refused = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
      data: { document_url: 'http://localhost/does-not-exist.pdf' },
    })

    expect(refused.status()).toBe(409)
    expect((await refused.json()).reason).toBe('document_template_unreachable')

    // Nothing was written. A saved-but-unusable URL is the state this check
    // exists to make unreachable — the club would discover it weeks later, at
    // the moment a Kassenwart tried to print.
    const settings = await (
      await authenticatedRequest.get(`${API}/admin/self-registration`)
    ).json()
    expect(settings.document_url).toBe(CLUB_DOCUMENT_URL)

    // The real one is accepted, which is what makes the refusal above mean
    // something other than "this endpoint refuses everything".
    const accepted = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
      data: { document_url: CLUB_DOCUMENT_URL },
    })
    expect(accepted.status()).toBe(200)
    expect((await accepted.json()).document_url).toBe(CLUB_DOCUMENT_URL)
  })

  /**
   * A template that arrives with somebody's data in its fields is refused
   * (#812) — and it is refused for a reason that has nothing to do with
   * whether it can be filled.
   *
   * The fill is unaffected: FPDI imports page 1 without annotations, so the
   * values are dropped with the widgets that held them. What makes this a leak
   * is the *other* job the same URL does. ADR-0052 decision 6 links it to every
   * applicant before they type anything, because pages 2+ are the club's
   * Datenschutzhinweise — so an IBAN left in a field is published to every
   * stranger who scans the poster, which is exactly how a real one became
   * readable from the onboarding page.
   */
  test('a document URL carrying somebody else\'s data in its fields is refused', async ({
    authenticatedRequest,
  }) => {
    configureSelfRegistration(uniqueSecret())

    const refused = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
      data: { document_url: PREFILLED_DOCUMENT_URL },
    })

    expect(refused.status()).toBe(409)
    const body = await refused.json()
    expect(body.reason).toBe('document_template_prefilled')
    // The refusal names the field, because "your template is invalid" sends a
    // club to audit a document that is otherwise perfectly correct.
    expect(body.params?.fields ?? body.message).toContain('iban')

    // And nothing was stored: the URL an admin cannot be talked out of is the
    // one that is already saved.
    const settings = await (
      await authenticatedRequest.get(`${API}/admin/self-registration`)
    ).json()
    expect(settings.document_url).toBe(CLUB_DOCUMENT_URL)
  })

  test('the club document and the poster secret are both preconditions of switching on', async ({
    authenticatedRequest,
  }) => {
    // No secret at all — the row exists, the credential does not.
    execSql('UPDATE self_registration_config SET enabled = 0, secret_hash = NULL, secret_cipher = NULL WHERE id = 1')

    const noSecret = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
      data: { enabled: true },
    })
    expect(noSecret.status()).toBe(409)
    // Named, not greyed out: a disabled control that will not say why is a
    // support call.
    expect((await noSecret.json()).reason).toBe('registration_no_secret')

    await authenticatedRequest.post(`${API}/admin/self-registration/secret`)

    // Now the document is what is missing. Clearing it also switches the club
    // off, so this asserts both halves of decision 6 in one step.
    const cleared = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
      data: { document_url: '' },
    })
    expect(cleared.status()).toBe(200)
    expect((await cleared.json()).enabled).toBe(false)

    const noDocument = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
      data: { enabled: true },
    })
    expect(noDocument.status()).toBe(409)
    expect((await noDocument.json()).reason).toBe('document_url_missing')

    // Both preconditions met in the one request an admin actually sends —
    // configure and switch on — which is why the URL is applied first.
    const both = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
      data: { document_url: CLUB_DOCUMENT_URL, enabled: true },
    })
    expect(both.status()).toBe(200)
    expect((await both.json()).enabled).toBe(true)

    configureSelfRegistration(uniqueSecret())
  })

  /**
   * ADR-0044, and the narrowing is the point.
   *
   * The Kassenwart reviews submissions — every endpoint in the block above is
   * open to them — and does not mint the credential that produces them. That is
   * the same rule that puts a terminal token's expiry behind `admin` alone,
   * applied to a poster secret, and it is worth its own test because it is a
   * *difference* between two adjacent route groups rather than a blanket.
   */
  test('the controls are admin-only, while the review inbox is not', async ({
    kassenwartRequest,
    getraenkewartRequest,
  }) => {
    configureSelfRegistration(uniqueSecret())

    // The office that reviews submissions can reach the inbox…
    expect((await kassenwartRequest.get(`${API}/admin/registrations?per_page=1`)).status()).toBe(200)

    // …and not the club's own credential.
    for (const call of [
      () => kassenwartRequest.get(`${API}/admin/self-registration`),
      () => kassenwartRequest.patch(`${API}/admin/self-registration`, { data: { enabled: true } }),
      () => kassenwartRequest.post(`${API}/admin/self-registration/secret`),
      () => kassenwartRequest.post(`${API}/admin/self-registration/poster`, { data: {} }),
    ]) {
      expect((await call()).status()).toBe(403)
    }

    // The bar stock office reaches none of it, inbox included: member balances
    // and member data are outside their remit on every surface.
    expect((await getraenkewartRequest.get(`${API}/admin/self-registration`)).status()).toBe(403)
    expect((await getraenkewartRequest.get(`${API}/admin/registrations?per_page=1`)).status()).toBe(403)
  })

  /**
   * The Anmeldelink (#821, ADR-0053, UC-A70).
   *
   * In this file rather than one of its own, for the reason the review
   * endpoints are: it writes `self_registration_config`, and a second file
   * writing that singleton would clobber this one from another worker — the
   * failure landing in whichever file lost the race.
   *
   * Nothing here drains. What is asserted is the row a send leaves behind, and
   * the shape of that row *is* the design: a message addressed to somebody this
   * database holds no record of. What actually arrives in a mailbox is
   * `mail-anmeldelink`'s job (Pattern 010).
   */
  test.describe('Sending the Anmeldelink', () => {
    /** Unique per test, so two workers never assert on each other's rows. */
    function prospect(): string {
      return `interessent-${randomUUID().slice(0, 8)}@example.org`
    }

    /**
     * One row, addressed to nobody, with a nonce for a key.
     *
     * Every assertion here is one of ADR-0053's claims made checkable: no
     * member id, no admin id, no invitee record anywhere — the outbox row is
     * the whole of what the club keeps about somebody who may never join.
     */
    test('a send queues exactly one row that names no member and no admin', async ({
      authenticatedRequest,
    }) => {
      configureSelfRegistration(uniqueSecret())
      const email = prospect()

      const response = await authenticatedRequest.post(`${API}/admin/registrations/link`, {
        data: { email },
      })

      // 202: queued, not delivered. The drain sends on its next tick.
      expect(response.status(), await response.text()).toBe(202)

      const rows = queuedRegistrationLinks(email)
      expect(rows).toHaveLength(1)
      expect(rows[0].memberId).toBe('NULL')
      expect(rows[0].adminUserId).toBe('NULL')
      // German, frozen at enqueue: there is no club-level default to read, and
      // inventing one belongs to #820 rather than to this feature.
      expect(rows[0].language).toBe('de')
    })

    /**
     * The regression test for decision 4, and the one most likely to be
     * "fixed" by somebody later.
     *
     * `UNIQUE (kind, subject_id, dedup_key)` exists so a repeating *scan* is
     * idempotent. There is no scan here — a human clicks send — and a
     * `dedup_key` of the bare address would let the database refuse the second
     * send silently, behind a 202, when the second send is precisely the one
     * that answers "I never got it".
     */
    test('sending twice to the same address queues twice', async ({ authenticatedRequest }) => {
      configureSelfRegistration(uniqueSecret())
      const email = prospect()

      for (const _ of [1, 2]) {
        const response = await authenticatedRequest.post(`${API}/admin/registrations/link`, {
          data: { email },
        })
        expect(response.status(), await response.text()).toBe(202)
      }

      const rows = queuedRegistrationLinks(email)
      expect(rows).toHaveLength(2)
      expect(rows[0].dedupKey).not.toBe(rows[1].dedupKey)
    })

    /**
     * Sending is a promise. A poster has an excuse for going stale — it is
     * paper the club cannot recall — and a message composed one second ago has
     * none: mailing a link to a refusal page makes the club look broken to
     * exactly the person it is courting.
     *
     * Each state is refused by the availability switch's *own* reason, so the
     * admin is told which precondition to fix rather than being handed a
     * generic failure.
     */
    for (const scenario of [
      {
        name: 'self-registration is switched off',
        configure: () => configureSelfRegistration(uniqueSecret(), { enabled: false, disabledReason: 'Beta-Phase schon voll' }),
        reason: 'registration_disabled',
      },
      {
        name: 'no poster secret has ever been generated',
        configure: () => {
          configureSelfRegistration(uniqueSecret())
          execSql("UPDATE self_registration_config SET secret_hash = NULL, secret_cipher = NULL WHERE id = 1")
        },
        reason: 'registration_no_secret',
      },
      {
        name: 'no club document is configured',
        configure: () => configureSelfRegistration(uniqueSecret(), { documentUrl: null }),
        reason: 'document_url_missing',
      },
    ]) {
      test(`the send is refused, and nothing is queued, when ${scenario.name}`, async ({
        authenticatedRequest,
      }) => {
        scenario.configure()
        const email = prospect()

        const response = await authenticatedRequest.post(`${API}/admin/registrations/link`, {
          data: { email },
        })

        expect(response.status(), await response.text()).toBe(409)
        expect((await response.json()).reason).toBe(scenario.reason)

        // A refusal is not a half-send: the queue must hold nothing.
        expect(queuedRegistrationLinks(email)).toHaveLength(0)
      })
    }

    test('an address that is not one is refused before anything is queued', async ({
      authenticatedRequest,
    }) => {
      configureSelfRegistration(uniqueSecret())

      for (const body of [{}, { email: '' }, { email: 'not-an-address' }]) {
        const response = await authenticatedRequest.post(`${API}/admin/registrations/link`, {
          data: body,
        })
        expect(response.status(), await response.text()).toBe(422)
      }
    })

    /**
     * The role set, and it is the interesting half of decision 5: this send is
     * TREASURY and the poster's own controls beside it are ADMIN_ONLY.
     *
     * That is not an inconsistency. The rule is to mirror the grant on the
     * surface the mail points at, and the mail points at the registration
     * surface (UC-A17, TREASURY) rather than at the credential surface (UC-A69,
     * ADMIN). Nothing secret crosses: what travels is the URL a wall in the
     * clubhouse already publishes.
     */
    test('a Kassenwart may send, a Getränkewart may not', async ({
      kassenwartRequest,
      getraenkewartRequest,
    }) => {
      configureSelfRegistration(uniqueSecret())
      const email = prospect()

      const allowed = await kassenwartRequest.post(`${API}/admin/registrations/link`, {
        data: { email },
      })
      expect(allowed.status(), await allowed.text()).toBe(202)
      expect(queuedRegistrationLinks(email)).toHaveLength(1)

      const refusedEmail = prospect()
      const refused = await getraenkewartRequest.post(`${API}/admin/registrations/link`, {
        data: { email: refusedEmail },
      })
      expect(refused.status()).toBe(403)
      expect(queuedRegistrationLinks(refusedEmail)).toHaveLength(0)
    })

    /**
     * Decision 9. An admin causing the installation to write to a named third
     * party is the shape of everything else in the log, and the address is part
     * of the entry rather than exempted from it.
     */
    test('the audit log names the act and the address', async ({ authenticatedRequest }) => {
      configureSelfRegistration(uniqueSecret())
      const email = prospect()

      const response = await authenticatedRequest.post(`${API}/admin/registrations/link`, {
        data: { email },
      })
      expect(response.status(), await response.text()).toBe(202)

      const entries = await authenticatedRequest.get(
        `${API}/admin/audit-log?filters[action]=registration_link_sent&limit=100`,
      )
      expect(entries.status()).toBe(200)

      const body = await entries.json()
      // Searched by id rather than taken from position 0 (Pattern 003): other
      // workers send links of their own while this one runs.
      const mine = (body.data ?? []).filter((entry: { new_values?: { recipient?: string } }) =>
        entry.new_values?.recipient === email,
      )
      expect(mine).toHaveLength(1)
      expect(mine[0].admin_user_id).toBeTruthy()
    })
  })
})
