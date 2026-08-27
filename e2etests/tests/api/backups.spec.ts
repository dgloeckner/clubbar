/**
 * The backups page's API (#693, ADR-0049).
 *
 * Three routes with three costs, and the split is the design rather than REST
 * tidiness: the page renders the local list immediately and asks for the remote
 * column afterwards, so a throttled tenant costs one column instead of the
 * table. What is asserted here is that the split actually exists over HTTP, and
 * that the download refuses what it should.
 *
 * Test Data Isolation (E2E Pattern 001): read-only; creates and deletes nothing.
 * Authentication (E2E Pattern 002): session auth via the authenticatedRequest fixture.
 * Database-Agnostic Assertions (E2E Pattern 003): the stack's archive count depends
 * on what else has run, so shapes and invariants are asserted, never a fixed list.
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { request as playwrightRequest } from '@playwright/test'

const API_BASE = 'http://localhost:8080/api'
const BACKUPS = `${API_BASE}/admin/backups`

test.describe('Backups API', () => {
  test.beforeAll(async () => {
    // Give the list something to be about. The trigger is the same endpoint a
    // hosting panel would call, and it is idempotent enough for this: a run
    // inside the minimum interval is skipped and an archive already exists.
    const anonymous = await playwrightRequest.newContext()

    try {
      await anonymous.post(`${API_BASE}/cron/backup`, {
        headers: { 'X-Cron-Secret': process.env.CRON_SECRET || 'dev-cron-secret-x' },
      })
    } finally {
      await anonymous.dispose()
    }
  })

  test('refuses an anonymous caller on all three routes', async ({ request }) => {
    // The list names every archive this club holds and the keys that open them;
    // the download hands over the database sealed.
    for (const path of ['', '/remote', '/clubbar-20260101-030000-deadbeef.cbb']) {
      expect((await request.get(`${BACKUPS}${path}`)).status(), path).toBe(401)
    }
  })

  test('the local list carries each archive and the keys that open it', async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(BACKUPS)
    expect(response.status()).toBe(200)

    const body = await response.json()
    expect(Array.isArray(body.archives)).toBe(true)
    expect(Array.isArray(body.keys)).toBe(true)
    expect(body.archives.length, 'the beforeAll triggered a run').toBeGreaterThan(0)

    const archive = body.archives[0]
    expect(archive.name).toContain('.cbb')
    expect(archive.bytes).toBeGreaterThan(0)
    expect(typeof archive.readable).toBe('boolean')

    // The key list is the point of the page: #703 removed the application's key
    // register, and this is what a club walks its paper one against.
    expect(body.keys.length, 'an archive names the keys that open it').toBeGreaterThan(0)
    for (const key of body.keys) {
      expect(key.fingerprint, 'a key is identified by its fingerprint, not its label').toMatch(/^[0-9a-f]{64}$/)
      expect(key.archives).toBeGreaterThan(0)
      expect(key.first_seen).toBeTruthy()
      expect(key.last_seen).toBeTruthy()
    }
  })

  /**
   * **No key material, ever.** The header names recipients by *public* key
   * fingerprint and label; a private half appearing here would be the failure
   * ADR-0049 exists to prevent, and the server does not hold one to leak.
   */
  test('the list carries no key material and no filesystem path', async ({ authenticatedRequest }) => {
    const body = await (await authenticatedRequest.get(BACKUPS)).text()

    expect(body).not.toContain('PRIVATE')
    expect(body).not.toContain('/app/backups')
    expect(body).not.toContain('dataDir')
  })

  /**
   * Its own route so the page never waits on the store, and a closed vocabulary
   * so a reader is always told *which* answer this is. "The store says it is
   * gone" and "we could not ask, and last night it was there" lead a club to
   * different actions.
   */
  test('the remote column is a separate call that names its own source', async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.get(`${BACKUPS}/remote`)
    expect(response.status()).toBe(200)

    const body = await response.json()
    expect(['live', 'snapshot', 'unavailable']).toContain(body.source)
    expect(Array.isArray(body.names)).toBe(true)

    // A live or snapshot answer has to date itself; "as of last night" must
    // never be passed off as "right now".
    if (body.source !== 'unavailable') {
      expect(body.taken_at).toBeGreaterThan(0)
    }
  })

  test('an archive can be downloaded whole', async ({ authenticatedRequest }) => {
    const { archives } = await (await authenticatedRequest.get(BACKUPS)).json()
    const archive = archives[0]

    const response = await authenticatedRequest.get(`${BACKUPS}/${archive.name}`)

    expect(response.status()).toBe(200)
    expect(response.headers()['content-disposition']).toContain(archive.name)
    // Sealed, but still the club's whole database: no shared cache should keep
    // a copy once the download is done.
    expect(response.headers()['cache-control']).toContain('no-store')

    const body = await response.body()
    expect(body.length).toBe(archive.bytes)
    // The sealed container's magic, which is what makes this an archive rather
    // than whatever else the directory held.
    expect(body.subarray(0, 14).toString()).toBe('CLUBBAR-BACKUP')
  })

  /**
   * **The name arrives from the URL.** A caller reaching outside the backup
   * directory would be reading arbitrary server files through an admin session,
   * `config.php` among them.
   */
  test('the download cannot be walked out of the backup directory', async ({
    authenticatedRequest,
  }) => {
    for (const attempt of [
      '..%2F..%2Fconfig.php',
      '..%2f..%2fconfig.php',
      'index.jsonl',
      'remote.json',
      'notes.txt',
    ]) {
      const response = await authenticatedRequest.get(`${BACKUPS}/${attempt}`)

      expect([400, 404], `${attempt} must not be served`).toContain(response.status())
    }
  })
})
