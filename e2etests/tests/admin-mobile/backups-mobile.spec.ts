/**
 * The backups page on a phone (#849; page #693, ADR-0049).
 *
 * The page carries the two widest strings in the admin panel: an archive
 * filename with a timestamp and a hash in it, and a 64-character fingerprint.
 * As a five-column table at 390px the result was not merely cramped — the
 * column headers ran together into one unreadable word and the download button
 * was clipped off the right edge, so the page's only action could not be
 * reached at all.
 *
 * These tests assert the card layout is what rendered, that nothing an archive
 * carries was dropped on the way there, that the page does not scroll sideways
 * with the widest content it can hold, and that the download still fires from a
 * card.
 *
 * Project `admin-mobile` — iPhone 14, which Playwright drives with **WebKit**,
 * not Chromium.
 *
 * Test Data Isolation (E2E Pattern 001): read-only; both routes are
 * intercepted, so nothing on the stack is touched and the counts do not depend
 * on what other specs have left in the backup directory.
 * Playwright Assertions (E2E Pattern 008): `expect()` throughout, no try-catch.
 */

import { test, expect } from '../../fixtures/auth.fixture'

const ARCHIVE = 'clubbar-20260906-220035-39e48684.cbb'
const FINGERPRINT = 'a'.repeat(64)

const INVENTORY = {
  archives: [
    {
      name: ARCHIVE,
      bytes: 57_344,
      at: 1_788_000_000,
      readable: true,
      created_at: '2026-09-06T22:00:35+00:00',
      config_included: true,
      plaintext_bytes: 8_000_000,
      recipients: [
        { label: 'admin', fingerprint: FINGERPRINT },
        { label: 'treasurer', fingerprint: 'b'.repeat(64) },
      ],
    },
    {
      name: 'clubbar-20260905-220034-1a0115dc.cbb',
      bytes: 54_272,
      at: 1_787_900_000,
      readable: false,
      created_at: null,
      config_included: null,
      plaintext_bytes: null,
      recipients: [],
    },
  ],
  keys: [
    {
      label: 'admin',
      fingerprint: FINGERPRINT,
      archives: 2,
      first_seen: '2026-09-05T22:00:34+00:00',
      last_seen: '2026-09-06T22:00:35+00:00',
    },
  ],
}

/** The widest answer the note can hold: a live check naming a WebDAV URL. */
const LIVE_REMOTE = {
  source: 'live',
  remote: 'hidrive://webdav.hidrive.ionos.com/users/frgs-clubbar-backup/archives',
  taken_at: 1_788_000_500,
  names: [ARCHIVE],
  error: null,
}

async function stub(page: import('@playwright/test').Page, remote: unknown = LIVE_REMOTE) {
  await page.route('**/api/admin/backups', (route) => route.fulfill({ json: INVENTORY }))
  await page.route('**/api/admin/backups/remote', (route) => route.fulfill({ json: remote }))
}

test.describe('Backups page — mobile', () => {
  test('both listings render as cards, not as overflowing tables', async ({ page }) => {
    await stub(page)
    await page.goto('/backups')

    await expect(page.getByTestId('backups-page')).toBeVisible()

    // Cards, one per row, with the row test ids unchanged.
    await expect(page.getByTestId('backups-archives-cards')).toBeVisible()
    await expect(page.getByTestId('backups-archive-row')).toHaveCount(2)
    await expect(page.getByTestId('backups-keys-cards')).toBeVisible()
    await expect(page.getByTestId('backups-key-row')).toHaveCount(1)

    // ...and neither table is on the page at this width.
    await expect(page.getByTestId('backups-archives-table')).toHaveCount(0)
    await expect(page.getByTestId('backups-keys-table')).toHaveCount(0)
  })

  /**
   * A narrower layout must not be a shorter one. Everything the table column
   * said is still said, because each of these is something a club acts on: the
   * size, who can open it, whether a second copy exists, and the badge that
   * says a file will not open at all.
   */
  test('an archive card keeps everything its table row carried', async ({ page }) => {
    await stub(page)
    await page.goto('/backups')

    const card = page.getByTestId('backups-archive-row').first()
    await expect(card.getByTestId('backups-archive-name')).toHaveText(ARCHIVE)
    await expect(card).toContainText('56 KB')
    await expect(card).toContainText('admin, treasurer')
    await expect(card.getByTestId('backups-archive-offsite')).toHaveText(/^(ja|yes)$/)
    await expect(card).toContainText('config.php')

    // The damaged archive is listed and marked, on a phone as on a desktop:
    // omitting it would let a club count backups it does not have.
    await expect(page.getByTestId('backups-archive-unreadable')).toBeVisible()
  })

  /**
   * The desktop cell truncates the fingerprint to sixteen characters and puts
   * the full value in a `title`, which a phone cannot hover to read. A card has
   * the width, so it prints all 64: this is the string a key holder compares
   * against the envelope in the club safe, and half of it compares nothing.
   */
  test('a key card shows the whole fingerprint', async ({ page }) => {
    await stub(page)
    await page.goto('/backups')

    const fingerprint = page.getByTestId('backups-key-fingerprint')
    await expect(fingerprint).toHaveText(FINGERPRINT)
    await expect(fingerprint).toHaveAttribute('title', FINGERPRINT)
  })

  test('the page does not scroll sideways', async ({ page }) => {
    // The live note interpolates a storage URL — one unbroken token, and the
    // widest thing the page can be asked to render.
    await stub(page)
    await page.goto('/backups')

    await expect(page.getByTestId('backups-archive-row')).toHaveCount(2)
    await expect(page.getByTestId('backups-remote-note')).toContainText('hidrive://')

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth
    )
    expect(overflow, 'the body must not scroll horizontally at phone width').toBeLessThanOrEqual(1)
  })

  /**
   * The failure that motivated the layout: the download button used to be in a
   * fifth column past the right edge of the viewport, so the page's only action
   * was unreachable. Asserting it is *visible and enabled without scrolling*
   * is the regression test — a clipped button is still in the DOM.
   */
  test('the download button is reachable and fires from the card', async ({ page }) => {
    await stub(page)
    // The fetch itself is stubbed too: what is under test is that the button
    // can be pressed, not what the server sends back.
    await page.route(`**/api/admin/backups/${ARCHIVE}`, (route) =>
      route.fulfill({ status: 200, contentType: 'application/octet-stream', body: 'stub' })
    )
    await page.goto('/backups')

    const button = page.getByTestId('backups-archive-download').first()
    await expect(button).toBeEnabled()

    // The regression test: the button used to sit in a fifth column past the
    // right edge, which leaves it in the DOM and visible to Playwright while
    // being unpressable in the hand. So assert on geometry — it must lie
    // wholly within the viewport's width.
    const box = await button.boundingBox()
    const width = page.viewportSize()?.width ?? 0
    expect(box, 'the download button must have a box to press').not.toBeNull()
    expect(box!.x).toBeGreaterThanOrEqual(0)
    expect(box!.x + box!.width).toBeLessThanOrEqual(width)

    const requested = page.waitForRequest((r) => r.url().includes(`/api/admin/backups/${ARCHIVE}`))
    await button.click()
    await requested
  })

  test('an empty inventory says so instead of rendering nothing', async ({ page }) => {
    await page.route('**/api/admin/backups', (route) =>
      route.fulfill({ json: { archives: [], keys: [] } })
    )
    await page.route('**/api/admin/backups/remote', (route) =>
      route.fulfill({ json: { source: 'unavailable', remote: null, taken_at: null, names: [], error: null } })
    )

    await page.goto('/backups')

    await expect(page.getByTestId('backups-archives-empty')).toBeVisible()
    await expect(page.getByTestId('backups-keys-empty')).toBeVisible()
  })
})
