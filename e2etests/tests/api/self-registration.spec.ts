import { test, expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
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
 */

const API = 'http://localhost:8080/api'
const TEST_IBAN = 'DE89370400440532013000'

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
})
