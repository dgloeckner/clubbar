/**
 * The backups page (#693, ADR-0049).
 *
 * What only a browser test can show is the property the whole page is shaped
 * around: **the local table does not wait for the storage provider**. The API
 * spec proves the two routes exist and answer; this proves the page renders the
 * first before the second has replied, which is the difference between a
 * throttled tenant costing one column and costing the page.
 *
 * Test Data Isolation (E2E Pattern 001): read-only; the routes are intercepted,
 * so nothing on the stack is touched and the assertions do not depend on how
 * many archives other specs have left behind.
 * Playwright Assertions (E2E Pattern 008): `expect()` throughout, no try-catch.
 */

import { test, expect } from '../../fixtures/auth.fixture'

const ARCHIVE = 'clubbar-20260825-030000-1a2b3c4d.cbb'
const FINGERPRINT = 'a'.repeat(64)

const INVENTORY = {
  archives: [
    {
      name: ARCHIVE,
      bytes: 2_097_152,
      at: 1_787_000_000,
      readable: true,
      created_at: '2026-08-25T03:00:00+00:00',
      config_included: true,
      plaintext_bytes: 8_000_000,
      recipients: [{ label: 'vorstand', fingerprint: FINGERPRINT }],
    },
    {
      name: 'clubbar-20260824-030000-99887766.cbb',
      bytes: 1024,
      at: 1_786_900_000,
      readable: false,
      created_at: null,
      config_included: null,
      plaintext_bytes: null,
      recipients: [],
    },
  ],
  keys: [
    {
      label: 'vorstand',
      fingerprint: FINGERPRINT,
      archives: 1,
      first_seen: '2026-08-25T03:00:00+00:00',
      last_seen: '2026-08-25T03:00:00+00:00',
    },
  ],
}

test.describe('Backups page', () => {
  test('lists the archives and the keys that open them', async ({ page }) => {
    await page.route('**/api/admin/backups', (route) => route.fulfill({ json: INVENTORY }))
    await page.route('**/api/admin/backups/remote', (route) =>
      route.fulfill({ json: { source: 'live', remote: 'msgraph://tenant/drive', taken_at: 1_787_000_500, names: [ARCHIVE], error: null } })
    )

    await page.goto('/backups')

    await expect(page.getByTestId('backups-page')).toBeVisible()
    await expect(page.getByTestId('backups-archive-row')).toHaveCount(2)

    // The key list is what the page exists for: #703 removed the application's
    // key register, and this is the checklist a club walks the paper one
    // against.
    await expect(page.getByTestId('backups-key-label')).toHaveText('vorstand')
    // Truncated for reading, but the full value has to be recoverable — it is
    // what gets compared against an envelope.
    await expect(page.getByTestId('backups-key-fingerprint')).toHaveAttribute('title', FINGERPRINT)
  })

  /**
   * The key column is a range of *archive creation dates*, and a key named by
   * exactly one archive therefore has one date, not a window (#736). The old
   * rendering printed the same timestamp either side of a dash, which reads as
   * a validity window of zero length — and validity is the one thing a backup
   * key does not have.
   */
  test('shows one timestamp for a single archive and a range for several', async ({ page }) => {
    const TWO_KEYS = {
      ...INVENTORY,
      keys: [
        ...INVENTORY.keys,
        {
          label: 'kassenwart',
          fingerprint: 'b'.repeat(64),
          archives: 2,
          first_seen: '2026-08-24T03:00:00+00:00',
          last_seen: '2026-08-25T03:00:00+00:00',
        },
      ],
    }
    await page.route('**/api/admin/backups', (route) => route.fulfill({ json: TWO_KEYS }))
    await page.route('**/api/admin/backups/remote', (route) =>
      route.fulfill({ json: { source: 'unavailable', remote: null, taken_at: null, names: [], error: null } })
    )

    await page.goto('/backups')

    const ranges = page.getByTestId('backups-key-archive-range')
    await expect(ranges).toHaveCount(2)
    // One archive: one timestamp, and no dash to suggest a second bound.
    await expect(ranges.first()).not.toContainText('–')
    await expect(ranges.first()).toContainText('2026')
    // Two archives that differ: both bounds, in that order.
    await expect(ranges.last()).toContainText('–')
  })

  /**
   * **The property the two-route split exists for.** The remote call is left
   * hanging; the archive table must still be on screen.
   */
  test('renders the local table while the store is still being asked', async ({ page }) => {
    await page.route('**/api/admin/backups', (route) => route.fulfill({ json: INVENTORY }))
    // Never fulfilled: this is a tenant that has gone away mid-request.
    await page.route('**/api/admin/backups/remote', () => {})

    await page.goto('/backups')

    await expect(page.getByTestId('backups-archive-row')).toHaveCount(2)
    await expect(page.getByTestId('backups-remote-note')).toContainText(/checking|abgefragt/i)
  })

  /**
   * Three-valued, and the third value is the point: "the store says it is gone"
   * and "we could not ask" lead a club to different actions.
   */
  test('says which answer the off-site column is showing', async ({ page }) => {
    await page.route('**/api/admin/backups', (route) => route.fulfill({ json: INVENTORY }))
    await page.route('**/api/admin/backups/remote', (route) =>
      route.fulfill({ json: { source: 'snapshot', remote: 'msgraph://tenant/drive', taken_at: 1_786_950_000, names: [], error: null } })
    )

    await page.goto('/backups')

    await expect(page.getByTestId('backups-remote-note')).toContainText(/could not be reached|nicht erreichbar/i)
    // Not silently "no": the snapshot is what is being shown, and it is dated.
    await expect(page.getByTestId('backups-archive-offsite').first()).toBeVisible()
  })

  /**
   * An archive whose header will not parse is **listed and marked**, never
   * hidden — omitting it would remove the one file most worth investigating and
   * let a club count backups it does not have.
   */
  test('shows a damaged archive rather than dropping it', async ({ page }) => {
    await page.route('**/api/admin/backups', (route) => route.fulfill({ json: INVENTORY }))
    await page.route('**/api/admin/backups/remote', (route) =>
      route.fulfill({ json: { source: 'unavailable', remote: null, taken_at: null, names: [], error: null } })
    )

    await page.goto('/backups')

    await expect(page.getByTestId('backups-archive-unreadable')).toBeVisible()
  })

  test('a failed inventory load says so instead of rendering an empty club', async ({ page }) => {
    await page.route('**/api/admin/backups', (route) => route.fulfill({ status: 500, json: {} }))
    await page.route('**/api/admin/backups/remote', (route) =>
      route.fulfill({ json: { source: 'unavailable', remote: null, taken_at: null, names: [], error: null } })
    )

    await page.goto('/backups')

    await expect(page.getByTestId('backups-error')).toBeVisible()
  })
})
