import { test, expect } from '@playwright/test'
import fs from 'node:fs'
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
 * It has since caught a second bug of the same family, in the other direction.
 * #691 made `open()` return a promise (inflating gzip is async), but every
 * refusal — a wrong key, a truncated file, a tampered chunk — was still thrown
 * *synchronously*, so it escaped past the page's `.then(ok, fail)` and became
 * an unhandled rejection. A holder with the wrong key would have been shown
 * nothing at all. `open()` now always returns a promise; the third test below
 * is what says so from the page's side.
 *
 * No stack, no fixtures, no database: the page and the committed golden archive
 * are the whole system under test.
 */

// Relative to e2etests/, which is where Playwright runs from — the specs are
// transpiled to CommonJS, so `import.meta` is not available here.
const REPO = path.resolve(__dirname, '../../..')
const TOOL = 'file://' + path.join(REPO, 'tools/backup-decryptor.html')
const ARCHIVE = path.join(REPO, 'backend/tests/Fixtures/backup/golden.cbb')

const FIXTURES = path.join(REPO, 'backend/tests/Fixtures/backup')

/** The published development private key (ADR-0036) the golden fixture is sealed to. */
const DEV_SECRET = 'f678fb17b592c29db54e43f808ee74fd67f7dd5c6c405b24e3e31ead38f3058a'

/**
 * The plaintext's length, read from the fixture rather than written here.
 *
 * It used to be a literal, and the literal broke the moment the golden archive
 * legitimately changed (#692 appended a `config.php` block to it) — presenting
 * as a failed assertion about a byte count, which reads like a decryptor bug
 * and is not one. `regenerate.php` now emits this alongside the checksum, so the
 * fixture states its own size and the spec cannot disagree with it.
 */
const PLAINTEXT_BYTES = Number(
  fs.readFileSync(path.join(FIXTURES, 'golden.plaintext.bytes'), 'utf8').trim()
)

/** What the page prints — `Number.toLocaleString()`, the same call the tool makes. */
const EXPECTED_SIZE = `${PLAINTEXT_BYTES.toLocaleString('en-US')} bytes`

/** The config the golden archive carries, which the page must offer separately. */
const EXPECTED_CONFIG = fs.readFileSync(path.join(FIXTURES, 'golden.config.php.txt'), 'utf8')

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
    await expect(page.locator('#contents')).toContainText('2,978 rows')
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
    await expect(page.locator('#size')).toContainText(EXPECTED_SIZE)

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

  test('offers config.php as a second download, with the bytes the archive carries', async ({ page }) => {
    // #692 put the installation's `config.php` inside the archive, because
    // restoring the rows alone yields a database nobody can log in to:
    // `security.totp_encryption_key` decrypts every admin's TOTP secret, is not
    // in the database, and cannot be regenerated.
    //
    // `backup-decryptor-interop.test.mjs` proves the *module* can find the
    // block. This proves the *page* wires it to a link the holder can click —
    // the same gap that once left a correct decryption with no download at all.
    await page.goto(TOOL)
    await page.setInputFiles('#archive', ARCHIVE)
    await page.fill('#key', DEV_SECRET)
    await page.click('#decrypt')

    await expect(page.locator('#config-row')).toBeVisible()
    await expect(page.locator('#download-config')).toHaveAttribute('href', /^blob:/)
    await expect(page.locator('#download-config')).toHaveAttribute('download', 'config.php')

    // Not merely present — the right bytes. A link to the wrong blob is worse
    // than no link: it is discovered on the new host, after the migration.
    const served = await page.evaluate(async () => {
      const href = (document.getElementById('download-config') as HTMLAnchorElement).href
      return await (await fetch(href)).text()
    })

    expect(served).toBe(EXPECTED_CONFIG)
  })

  test('offers each table as its own importable piece, with the session settings in front', async ({ page }) => {
    // phpMyAdmin has an upload limit a club-sized database eventually exceeds,
    // and the runbook used to answer that by asking the operator to cut the
    // file at the table markers and paste the header lines in front of every
    // piece — by hand, at the worst moment of the club's year. The last of
    // those three steps is the one with no symptom when it is forgotten.
    //
    // `backup-decryptor-interop.test.mjs` proves the *module* cuts the same
    // bytes PHP does. This proves the *page* turns them into links, which is
    // the half that has twice been where this tool actually broke.
    await page.goto(TOOL)
    await page.setInputFiles('#archive', ARCHIVE)
    await page.fill('#key', DEV_SECRET)
    await page.click('#decrypt')

    await expect(page.locator('#result')).toBeVisible()
    await expect(page.locator('#pieces tr')).toHaveCount(1)

    const piece = page.locator('#pieces tr').first()
    await expect(piece.locator('td').nth(0)).toHaveText('members')

    // Read from the manifest the page already showed rather than written here,
    // so a regenerated fixture cannot turn into a failing assertion about a row
    // count that reads like a decryptor bug and is not one.
    const manifestRows = await page.locator('#manifest tr td').nth(1).textContent()
    await expect(piece.locator('td').nth(1)).toHaveText(manifestRows ?? '')

    const link = piece.locator('a')
    await expect(link).toHaveAttribute('download', 'clubbar-members.sql')
    await expect(link).toHaveAttribute('href', /^blob:/)

    const served = await page.evaluate(async () => {
      const href = document.querySelector('#pieces a') as HTMLAnchorElement
      return await (await fetch(href.href)).text()
    })

    // Importable on its own: the settings first, then exactly one section.
    expect(served).toMatch(/^-- Club Bar database dump/)
    expect(served, 'the setting whose absence has no symptom').toContain("SET time_zone = '+00:00'")
    expect(served).toContain('SET FOREIGN_KEY_CHECKS = 0;')
    expect(served).toContain('-- >>> TABLE members')
    expect(served.trimEnd()).toMatch(/-- <<< TABLE members$/)

    // It would retarget whichever schema the operator has open, because it
    // names no database.
    expect(served).not.toContain('ALTER DATABASE')

    // The config block trails the dump's footer and is not a table; it is
    // offered as its own download, above.
    expect(served).not.toContain('CONFIG config.php')
  })

  test('a key the archive was not sealed to is refused by name, visibly', async ({ page }) => {
    // A wrong key must say which envelope to fetch instead, not fail with a
    // decryption error the holder cannot act on — and it must reach the page
    // at all, which is the part #691 nearly broke: the refusal is thrown
    // before the inflate, so a synchronous throw would sail past the promise
    // handler and show the holder nothing.
    const pageErrors: string[] = []
    page.on('pageerror', (error) => pageErrors.push(error.message))

    await page.goto(TOOL)
    await page.setInputFiles('#archive', ARCHIVE)
    await page.fill('#key', '11'.repeat(32))
    await page.click('#decrypt')

    await expect(page.locator('#error')).toBeVisible()
    await expect(page.locator('#error')).toContainText('not sealed to this key')
    await expect(page.locator('#result')).toBeHidden()

    expect(pageErrors, 'A refusal must be shown, not escape as an uncaught error.').toEqual([])
  })
})
