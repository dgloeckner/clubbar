import { test, expect, request as playwrightRequest } from '@playwright/test'

/**
 * E2E Test: the URL backup trigger (ADR-0049, #690)
 *
 * The second public, unauthenticated write in the application, and the heavier
 * of the two: hitting the drain sends what was already queued, while hitting
 * this one produces and stores a **database dump**. So what is checked here is
 * mostly what it refuses, and — the part unique to this endpoint — that it
 * cannot be used to fill the webspace quota.
 *
 * Exercised over real HTTP rather than only in-process, for the same reason as
 * the drain spec: the interesting failure modes are middleware-shaped. A route
 * that ended up behind the session guard or behind CSRF would still pass a
 * controller test and be unusable from a panel's cron.
 *
 * It shares the drain's secret on purpose (`CRON_SECRET` in
 * docker-compose.yml). One credential, one rotation, one thing to paste — two
 * would mean an installation that rotated one and not the other, with the
 * failure showing up as a job that silently stopped.
 *
 * **These tests do not assert that an archive was written**, and that is
 * deliberate rather than a gap: the stack ships with backups disabled
 * (`backup_config.enabled = 0`) and with no recipient key configured, which is
 * the shipped default because an installation with no key cannot write an
 * archive at all. A 204 here means "the request was accepted and the run was
 * attempted", which is exactly what the endpoint promises a scheduler. What the
 * run *does* is covered by `BackupServiceTest` against a real database and a
 * real filesystem.
 */
test.describe('Cron backup URL trigger', () => {
  const URL = 'http://localhost:8080/api/cron/backup'
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

  test('accepts the header form and never serves an archive', async () => {
    const response = await anonymous.post(URL, {
      headers: { 'X-Cron-Secret': SECRET },
    })

    expect(response.status()).toBe(204)
    // It triggers; it never serves. Not even a count: putting the state of the
    // club's backups behind one static credential is the wrong trade, and a
    // scheduler cannot act on the answer anyway.
    expect(await response.text()).toBe('')
  })

  test('accepts a GET, because panels differ on what they can schedule', async () => {
    const response = await anonymous.get(URL, {
      headers: { 'X-Cron-Secret': SECRET },
    })

    expect(response.status()).toBe(204)
    expect(await response.text()).toBe('')
  })

  test('accepts the degraded query-string form', async () => {
    const response = await anonymous.get(`${URL}?secret=${SECRET}`)

    expect(response.status()).toBe(204)
    expect(await response.text()).toBe('')
  })

  test('repeated calls stay 204 and cannot be told apart by the caller', async () => {
    // The minimum-interval guard lives in the service and is what stops a
    // caller in a loop filling the quota with dumps. The scheduler is told
    // nothing either way — a response that distinguished "ran" from "too soon"
    // would leak how often the club backs up to whoever holds the secret, and
    // a scheduler has nothing to do with the answer.
    for (let i = 0; i < 3; i++) {
      const response = await anonymous.post(URL, {
        headers: { 'X-Cron-Secret': SECRET },
      })

      expect(response.status()).toBe(204)
      expect(await response.text()).toBe('')
    }
  })

  test('is not reachable as a file, only as a route', async () => {
    // bin/backup.php ships inside the document root behind .htaccess rules the
    // host honours at its discretion (#383). If those are ignored it must not
    // become a URL that writes a database dump for anyone who finds it, which
    // is what its CLI-only guard is for.
    const direct = await anonymous.get('http://localhost:8080/backend/bin/backup.php')

    expect([403, 404]).toContain(direct.status())
  })
})
