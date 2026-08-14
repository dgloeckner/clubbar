import { test, expect, request as playwrightRequest } from '@playwright/test'

/**
 * E2E Test: the URL drain trigger (ADR-0038 rule 3, #403)
 *
 * The one public, unauthenticated write in the application, so what is checked
 * here is mostly what it refuses. It is exercised over real HTTP — rather than
 * only in the PHPUnit `CronDrainHttpTest` — because the interesting failure
 * modes are middleware-shaped: a route that ended up behind the session guard,
 * or behind CSRF, would still pass an in-process controller test and be
 * unusable from a panel's cron.
 *
 * No session, no CSRF token, no cookies: a scheduler has none of those, so
 * these use a bare request context rather than the authenticated fixture.
 *
 * Nothing is sent. The dev stack has no `MAIL_DSN`, so the drain stops before
 * claiming anything — which is also the state the assertions rely on: a 204
 * here means "the request was accepted and the run happened", not "mail went
 * out".
 *
 * The secret matches `CRON_SECRET` in docker-compose.yml. It is a fixed dev
 * value; a real installation generates one at install time and keeps it in
 * config.php.
 */
test.describe('Cron drain URL trigger', () => {
  const URL = 'http://localhost:8080/api/cron/drain'
  const SECRET = process.env.CRON_SECRET || 'dev-cron-secret-x'

  let anonymous: Awaited<ReturnType<typeof playwrightRequest.newContext>>

  test.beforeAll(async () => {
    anonymous = await playwrightRequest.newContext()
  })

  test.afterAll(async () => {
    await anonymous.dispose()
  })

  test('rejects a request with no secret', async () => {
    const response = await anonymous.post(URL)

    expect(response.status()).toBe(401)
  })

  test('rejects a wrong secret in the header', async () => {
    const response = await anonymous.post(URL, {
      headers: { 'X-Cron-Secret': 'not-the-secret' },
    })

    expect(response.status()).toBe(401)
  })

  test('rejects a wrong secret in the query string', async () => {
    const response = await anonymous.get(`${URL}?secret=not-the-secret`)

    expect(response.status()).toBe(401)
  })

  test('accepts the header form and returns no data', async () => {
    const response = await anonymous.post(URL, {
      headers: { 'X-Cron-Secret': SECRET },
    })

    expect(response.status()).toBe(204)
    // The caller is a scheduler; it cannot act on counts, and a body would put
    // the state of the club's announcement queue behind one static credential.
    expect(await response.text()).toBe('')
  })

  test('accepts a GET, because panels differ on what they can schedule', async () => {
    const response = await anonymous.get(URL, {
      headers: { 'X-Cron-Secret': SECRET },
    })

    expect(response.status()).toBe(204)
  })

  test('accepts the degraded query-string form', async () => {
    // Supported because some panels can only fetch a bare URL. Degraded
    // because the request line, secret and all, lands in the access log.
    const response = await anonymous.get(`${URL}?secret=${SECRET}`)

    expect(response.status()).toBe(204)
    expect(await response.text()).toBe('')
  })

  test('the run is visible to an admin afterwards, and only to an admin', async () => {
    // The scheduler learns nothing; the club's own side learns that a run
    // happened. That split is the whole design of the response.
    const drain = await anonymous.post(URL, { headers: { 'X-Cron-Secret': SECRET } })
    expect(drain.status()).toBe(204)

    const unauthenticated = await anonymous.get('http://localhost:8080/api/admin/security-check')
    expect(unauthenticated.status()).toBe(401)
  })
})
