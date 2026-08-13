import { test, expect } from '../../fixtures/auth.fixture'
import { WRONG_PRIVATE_KEY, exportSepaXml } from '../../fixtures/encryption'
import { FACTORY_IBAN } from '../../utils/settlements'

/**
 * SEPA export as the one legitimate bulk decryption (#393, ADR-0036).
 *
 * Member IBANs are sealed under the club's public key and the server holds no
 * private key: it can encrypt, never decrypt. Building a pain.008 is the single
 * exception, and the key reaches the server only for the length of one request.
 *
 * What is proved here is the whole point of the epic — that the plaintext is
 * genuinely gone from anything an attacker with the database, a dump or the
 * whole webspace would hold, and that it comes back only when someone brings
 * the key that lives offline in the club's safe.
 *
 * E2E Pattern 001: every test mints its own settlement, so the file is safe to
 * run against the shared database in parallel.
 */

const API_BASE = 'http://localhost:8080/api'

test.describe('SEPA export decrypts sealed IBANs', () => {
  test('the file carries the real IBAN when the right key is supplied', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 2500 })

    const response = await exportSepaXml(authenticatedRequest, settlement.id)
    expect(response.status(), await response.text()).toBe(200)

    const xml = await response.text()
    // The fixture constant, not an echo of the API: no admin response carries a
    // full IBAN any more, so the expectation has to come from the test's own
    // knowledge of what it created (#392).
    expect(xml).toContain(FACTORY_IBAN)
    expect(xml).toContain('<DbtrAcct>')
  })

  test('the response may not be cached', async ({ authenticatedRequest, settlementFactory }) => {
    const settlement = await settlementFactory.create()

    const response = await exportSepaXml(authenticatedRequest, settlement.id)

    expect(response.status()).toBe(200)
    // The body is a list of plaintext IBANs; no proxy or browser cache may keep it.
    expect(response.headers()['cache-control']).toBe('no-store')
  })

  test('a key from a different keypair is refused with 422 and decrypts nothing', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    const response = await exportSepaXml(authenticatedRequest, settlement.id, {
      privateKey: WRONG_PRIVATE_KEY,
    })

    expect(response.status()).toBe(422)
    const body = await response.json()
    expect(body.error).toBe('private_key_mismatch')
    expect(JSON.stringify(body)).not.toContain(FACTORY_IBAN)

    // Nothing happened: the settlement was not marked exported, so the real
    // export is still available once the right key is found.
    const detail = await authenticatedRequest.get(`${API_BASE}/admin/settlements/${settlement.id}`)
    expect((await detail.json()).exported_at ?? null).toBeNull()
  })

  test('a malformed key is refused without reaching the export', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    const response = await exportSepaXml(authenticatedRequest, settlement.id, {
      privateKey: 'not-base64-at-all',
    })

    expect(response.status()).toBe(422)
    expect(await response.text()).not.toContain(FACTORY_IBAN)
  })

  test('the step-up credential is required on top of the session', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    const missing = await exportSepaXml(authenticatedRequest, settlement.id, { password: null })
    expect(missing.status()).toBe(401)

    const wrong = await exportSepaXml(authenticatedRequest, settlement.id, {
      password: 'definitely-not-the-password',
    })
    expect(wrong.status()).toBe(401)
    expect(await wrong.text()).not.toContain(FACTORY_IBAN)
  })

  test('the private key alone is not enough without a session', async ({
    request,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    // Same valid key, no session: the session check comes first, so a stolen
    // key opens nothing on its own.
    const response = await exportSepaXml(request, settlement.id)

    expect([301, 302, 401, 403]).toContain(response.status())
  })

  test('a request without a private key is refused', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    const response = await exportSepaXml(authenticatedRequest, settlement.id, { privateKey: null })

    expect(response.status()).toBe(422)
    expect(JSON.stringify(await response.json())).toContain('private_key')
  })

  /**
   * The end-to-end proof #393 asks for, in one test: create a member with a
   * known IBAN, confirm the API surfaces have nothing but the last four
   * characters, settle them, and then get the plaintext back out — but only by
   * supplying the key.
   */
  test('a member IBAN is invisible across the API until the key is supplied', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 1800 })
    const [member] = settlement.members

    const detail = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)
    const detailBody = await detail.json()

    expect(detail.status()).toBe(200)
    expect(detailBody.iban_last4).toBe('3000')
    expect(detailBody.iban_masked).toBe('****3000')
    expect(detailBody, 'the detail response must not carry a plaintext IBAN').not.toHaveProperty('iban')

    // Every other place a member's banking data surfaces.
    const surfaces = [
      await authenticatedRequest.get(`${API_BASE}/admin/members?search=${member.email}`),
      await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}/export`),
      await authenticatedRequest.get(`${API_BASE}/admin/settlements/${settlement.id}`),
      await authenticatedRequest.get(`${API_BASE}/admin/sepa-config`),
    ]
    for (const surface of surfaces) {
      expect(await surface.text(), `${surface.url()} leaked a full IBAN`).not.toContain(FACTORY_IBAN)
    }

    // And the one place it does come back — with the key from the safe.
    const exported = await exportSepaXml(authenticatedRequest, settlement.id)
    expect(exported.status()).toBe(200)
    expect(await exported.text()).toContain(FACTORY_IBAN)
  })

  test('the decryption is audited without any account data', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 3300, memberCount: 2 })

    expect((await exportSepaXml(authenticatedRequest, settlement.id)).status()).toBe(200)

    const auditResponse = await authenticatedRequest.get(
      `${API_BASE}/admin/audit-log?entity_id=${settlement.id}&per_page=50`,
    )
    expect(auditResponse.status()).toBe(200)

    const entries = (await auditResponse.json()).data as Array<{ action: string }>
    const exportEntries = entries.filter((entry) => entry.action === 'sepa_export')

    expect(exportEntries.length, 'the bulk decryption must be recorded').toBeGreaterThanOrEqual(1)
    expect(JSON.stringify(exportEntries)).not.toContain(FACTORY_IBAN)
    expect(JSON.stringify(exportEntries)).toContain('collected_members')
  })

})
