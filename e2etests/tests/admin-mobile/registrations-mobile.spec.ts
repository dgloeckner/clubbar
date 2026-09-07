/**
 * The registrations inbox on a phone (page #782, UC-A17, ADR-0052).
 *
 * As a six-column table at 390px the queue was unusable in both its states.
 * With rows, the header ran off the right edge and the IBAN and bank columns
 * sat past it. Empty — which is the state this inbox is in most days — the
 * sentence explaining where registrations come from was laid out inside a
 * `colSpan` cell as wide as those six columns, so it was clipped mid-word, and
 * the send-link button under it was pushed off-screen. The pagination toolbar
 * rendered too, reading "showing 1-0 of 0" beside a next and a last button that
 * were enabled.
 *
 * These tests assert the card layout is what rendered, that a card still
 * carries everything its table row did, that the empty state fits, that
 * nothing pages an empty queue, and that a card opens the review panel.
 *
 * Test Data Isolation (E2E Pattern 001): the list endpoint is intercepted, so
 * these tests neither write to the stack nor depend on what other specs left
 * in the queue — which matters more here than elsewhere, because a real row
 * only exists after a public submission against the single-row
 * `self_registration_config` that four other spec files also write.
 * Playwright Assertions (E2E Pattern 008): `expect()` throughout, no try-catch.
 *
 * Project `admin-mobile` — iPhone 14, which Playwright drives with **WebKit**,
 * not Chromium.
 */

import { test, expect } from '@playwright/test'
import type { Page } from '@playwright/test'

const ID = '3f5b1c2e-0d44-4a1e-9d3a-0d0d5f6a7b8c'

/** A long name and a long address: the two strings that used to widen the row. */
const REGISTRATION = {
  id: ID,
  first_name: 'Annalena',
  last_name: 'Brandtstetter-Wieshaupt',
  email: 'annalena.brandtstetter-wieshaupt@ruderverein-frankfurt.example',
  date_of_birth: '1998-04-02',
  preferred_language: 'de',
  account_holder_name: null,
  mandate_reference: 'CLB0000000000000000000000000000001',
  iban_masked: 'DE89****3000',
  iban_last4: '3000',
  bank_name: 'Frankfurter Volksbank Rhein/Main eG',
  privacy_notice_url: 'https://example.org/datenschutz.pdf',
  privacy_notice_shown_at: '2026-09-06T20:00:00+00:00',
  submitted_at: '2026-09-06T20:01:12+00:00',
  expires_at: '2026-10-06T20:01:12+00:00',
  duplicate_email: true,
  duplicate_iban: false,
}

/**
 * Matched by path rather than by a glob: a glob with a `?` in it is matching a
 * literal question mark, not "anything after the path", and the queue's URL
 * always carries a query string.
 */
async function stub(page: Page, items: unknown[]) {
  await page.route(
    (url) => url.pathname === '/api/admin/registrations',
    (route) =>
      route.fulfill({
        json: {
          data: items,
          pagination: { page: 1, per_page: 10, total: items.length, total_pages: items.length ? 1 : 0 },
        },
      })
  )
}

async function openInbox(page: Page) {
  await page.goto('/registrations')
  await expect(page.getByTestId('registrations-page')).toBeVisible()
}

/** The regression assertion both layouts share: nothing wider than the phone. */
async function expectNoSidewaysScroll(page: Page) {
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth
  )
  expect(overflow, 'the body must not scroll horizontally at phone width').toBeLessThanOrEqual(1)
}

test.describe('Registrations inbox — mobile', () => {
  test('the queue renders as cards, not as an overflowing table', async ({ page }) => {
    await stub(page, [REGISTRATION])
    await openInbox(page)

    await expect(page.getByTestId('registrations-cards')).toBeVisible()
    await expect(page.getByTestId(`registration-row-${ID}`)).toBeVisible()

    // ...and the table is not on the page at this width.
    await expect(page.getByTestId('registrations-table')).toHaveCount(0)

    await expectNoSidewaysScroll(page)
  })

  /**
   * A narrower layout must not be a shorter one. Each of these is something the
   * treasurer checks before approving: who applied, when, at which address, and
   * the masked number they compare against the paper in their hand — plus the
   * duplicate warning, the one thing that must not be approved on autopilot.
   */
  test('a card keeps everything its table row carried', async ({ page }) => {
    await stub(page, [REGISTRATION])
    await openInbox(page)

    const card = page.getByTestId(`registration-row-${ID}`)
    await expect(card).toContainText(REGISTRATION.last_name)
    await expect(card).toContainText(REGISTRATION.email)
    await expect(card).toContainText('DE89****3000')
    await expect(card).toContainText('Frankfurter Volksbank')
    await expect(card.getByTestId(`duplicate-email-${ID}`)).toBeVisible()

    // The claim the module rests on holds on a phone too: the readable number
    // is nowhere on the page, because the server never sent one.
    expect(await page.content()).not.toContain('DE89370400440532013000')
  })

  /**
   * The failure that motivated the layout. The empty state is what this screen
   * shows on most days, and it was the worst-laid-out thing on it: the body
   * text clipped mid-word and the button under it past the right edge — in the
   * DOM, visible to Playwright, unpressable in the hand. So assert on geometry.
   */
  test('the empty state fits the screen, button and all', async ({ page }) => {
    await stub(page, [])
    await openInbox(page)

    const empty = page.getByTestId('registrations-empty')
    await expect(empty).toBeVisible()

    const width = page.viewportSize()?.width ?? 0
    const box = await empty.boundingBox()
    expect(box, 'the empty state must have a box').not.toBeNull()
    expect(box!.x).toBeGreaterThanOrEqual(0)
    expect(box!.x + box!.width).toBeLessThanOrEqual(width)

    const button = page.getByTestId('registrations-empty-send-link-button')
    await expect(button).toBeEnabled()
    const buttonBox = await button.boundingBox()
    expect(buttonBox, 'the send-link button must have a box to press').not.toBeNull()
    expect(buttonBox!.x).toBeGreaterThanOrEqual(0)
    expect(buttonBox!.x + buttonBox!.width).toBeLessThanOrEqual(width)

    await expectNoSidewaysScroll(page)

    // Nothing to page through is not a page: the toolbar that used to read
    // "1-0 of 0" beside two enabled buttons is gone.
    await expect(page.getByTestId('registrations-pagination-toolbar')).toHaveCount(0)

    // The dialog behind that button still opens from the empty queue.
    await button.click()
    await expect(page.getByTestId('send-registration-link-modal')).toBeVisible()
  })

  /**
   * The sort controls live in the table's header cells, which the card layout
   * drops — so on a phone they move into the toolbar, and choosing one has to
   * reach the request rather than merely close the dropdown.
   */
  test('sorting is reachable from the toolbar and reaches the request', async ({ page }) => {
    await stub(page, [REGISTRATION])
    await openInbox(page)

    await expect(page.getByTestId('registrations-search')).toBeVisible()
    await page.getByTestId('registrations-mobile-sort').click()

    const sorted = page.waitForRequest(
      (r) => r.url().includes('/api/admin/registrations?') && r.url().includes('sort=last_name')
    )
    await page.getByTestId('registrations-mobile-toolbar-sort-option-last_name_asc').click()
    await sorted
  })

  /**
   * The card is one control, not a link buried in its first line: the whole
   * thing is the button, and pressing it anywhere opens the review panel.
   */
  test('a card opens the review panel', async ({ page }) => {
    await stub(page, [REGISTRATION])
    await openInbox(page)

    await page.getByTestId(`registration-open-${ID}`).click()

    const panel = page.getByTestId('registration-panel')
    await expect(panel).toBeVisible()
    await expect(panel).toContainText(REGISTRATION.last_name)
    await expect(panel.getByTestId('panel-approve')).toBeVisible()
  })
})
