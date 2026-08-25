import { test, expect } from '@playwright/test'
import path from 'node:path'

/**
 * E2E Test: the offline backup decryptor (ADR-0049, #689/#703)
 *
 * The file a key holder opens at the worst moment of the club's year, on a
 * machine with no server, no network and no help. So it is driven here as a
 * real page in a real browser, from `file://` — which is how it is actually
 * used, and which is the part no other test covers.
 *
 * **The gap this closes was not theoretical.** `backup-decryptor-interop.test.mjs`
 * exercises `tools/backup-decryptor.js` under node, so the container format has
 * two independent readers and cannot drift. Nothing exercised the HTML around
 * it — and the page called `sodium.crypto_hash_sha256`, which the *standard*
 * libsodium build does not export (only `sumo` does). Every decryption
 * therefore threw at the last step, after the plaintext existed, so the result
 * panel and its download link never appeared. A tool that decrypts correctly
 * and then refuses to hand over the file is indistinguishable, to the holder,
 * from one that cannot open the archive at all.
 *
 * No stack, no fixtures, no database: the page and the committed golden archive
 * are the whole system under test.
 */

// Relative to e2etests/, which is where Playwright runs from — the specs are
// transpiled to CommonJS, so `import.meta` is not available here.
const REPO = path.resolve(__dirname, '../../..')
const TOOL = 'file://' + path.join(REPO, 'tools/backup-decryptor.html')
const ARCHIVE = path.join(REPO, 'backend/tests/Fixtures/backup/golden.cbb')

/** The published development private key (ADR-0036) the golden fixture is sealed to. */
const DEV_SECRET = 'f678fb17b592c29db54e43f808ee74fd67f7dd5c6c405b24e3e31ead38f3058a'

test.describe('Offline backup decryptor', () => {
  test('describes the archive before asking for a key', async ({ page }) => {
    // ADR-0049 decision 8: there is no backup state in the database, so what an
    // archive *is* has to be answerable from the file alone — and before the
    // holder has fetched a private key, since which key to fetch is one of the
    // questions it answers.
    await page.goto(TOOL)
    await page.setInputFiles('#archive', ARCHIVE)

    await expect(page.locator('#archive-info')).toBeVisible()
    await expect(page.locator('#recipients')).toContainText('admin')
    await expect(page.locator('#recipients')).toContainText('vorstand')
    await expect(page.locator('#instance')).toContainText('SV Musterstadt')
    await expect(page.locator('#database')).toHaveText('clubbar')
    await expect(page.locator('#schema')).toHaveText('054_credit_limit_digest.sql')
    await expect(page.locator('#format')).toHaveText('1')
    await expect(page.locator('#contents')).toContainText('1 tables')
    await expect(page.locator('#expected')).toContainText('SHA-256')

    // The per-table split the manifest exists for.
    await expect(page.locator('#manifest tr')).toHaveCount(1)
    await expect(page.locator('#manifest')).toContainText('members')
  })

  test('decrypts to a downloadable .sql and confirms the header checksum', async ({ page }) => {
    const pageErrors: string[] = []
    page.on('pageerror', (error) => pageErrors.push(error.message))

    await page.goto(TOOL)
    await page.setInputFiles('#archive', ARCHIVE)
    await page.fill('#key', DEV_SECRET)
    await page.click('#decrypt')

    await expect(page.locator('#result')).toBeVisible()
    await expect(page.locator('#error')).toBeHidden()
    await expect(page.locator('#size')).toContainText('135,248 bytes')

    // The download link is what the holder came for; it must exist and point at
    // the decrypted bytes rather than at nothing.
    await expect(page.locator('#download')).toHaveAttribute('href', /^blob:/)

    // The header states the plaintext's checksum so a restore can prove it
    // decrypted what was sealed. Saying whether it holds is the whole reason.
    await expect(page.locator('#verified')).toHaveText(/matches the checksum/i)
    await expect(page.locator('#sha')).toHaveText(/^[0-9a-f]{64}$/)

    expect(
      pageErrors,
      'An uncaught error after decryption loses the download link, which is exactly '
        + 'how the crypto_hash_sha256 bug survived: the archive opened and the holder '
        + 'was shown nothing.',
    ).toEqual([])
  })

  test('a key the archive was not sealed to is refused by name', async ({ page }) => {
    // A wrong key must say which envelope to fetch instead, not fail with a
    // decryption error the holder cannot act on.
    await page.goto(TOOL)
    await page.setInputFiles('#archive', ARCHIVE)
    await page.fill('#key', '11'.repeat(32))
    await page.click('#decrypt')

    await expect(page.locator('#error')).toBeVisible()
    await expect(page.locator('#error')).toContainText('not sealed to this key')
    await expect(page.locator('#result')).toBeHidden()
  })
})
