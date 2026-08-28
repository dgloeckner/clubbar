/**
 * The header nav never hides a section (#742).
 *
 * The row used to be a scroller with its scrollbar styled away, so an entry
 * past the right edge was gone rather than clipped: on a 1713px window in
 * German the audit log had no link, no scrollbar and no hint it existed. What
 * has to hold at every width is that the set of reachable sections does not
 * change, and that nothing rendered inline is cut off by the row's edge.
 *
 * Patterns: 006 (POM), 008 (Assertions)
 */

import { test, expect } from '../../fixtures/pageObjects'
import { MainLayoutPage } from '../../pages/MainLayoutPage'

/** Every section the seeded admin holds, in nav order (ADR-0044). */
const ADMIN_SECTIONS = [
  'nav-dashboard',
  'nav-members',
  'nav-products',
  'nav-journal',
  'nav-settlements',
  'nav-reports',
  'nav-settings',
  'nav-notifications',
  'nav-backups',
  'nav-audit-log',
]

/**
 * The narrowest window that is still the desktop row: 769–1500px is the
 * icon-only variant, which lays out differently.
 *
 * Overflow there is not a coincidence of one locale. Measured against the
 * ~864px the row gets at this width with the seeded club name: the ten
 * sections need ~1114px in German and ~923px in English, so the tail moves
 * into "More" either way — which is what lets the two tests below assert it
 * rather than check for it.
 */
const NARROW_DESKTOP = 1501

/**
 * Nothing inline may extend past the right edge of the row. This is the
 * regression itself: the old nav laid entries out past that edge and relied on
 * a scrollbar it had hidden.
 *
 * Polled rather than read once, because a viewport change reaches the nav
 * through a ResizeObserver — the frame right after the resize legitimately
 * still shows the old row.
 */
async function expectNoInlineEntryClipped(page: import('@playwright/test').Page, label: string) {
  await expect
    .poll(
      async () =>
        page.locator('[data-testid="desktop-nav"]').evaluate((nav) => {
          // Everything laid out in the row: the entries and, when there is
          // one, the More button's wrapper. The off-screen measurement copy is
          // `aria-hidden` and deliberately sits outside the row.
          const entries = (Array.from(nav.children) as HTMLElement[]).filter(
            (child) => child.getAttribute('aria-hidden') !== 'true'
          )
          if (entries.length === 0) {
            return Number.POSITIVE_INFINITY
          }
          const edge = nav.getBoundingClientRect().right
          return Math.max(...entries.map((entry) => entry.getBoundingClientRect().right - edge))
        }),
      { message: `inline entries overflow the nav at ${label}`, timeout: 5000 }
    )
    // Sub-pixel layout rounding, not a tolerance for a clipped entry.
    .toBeLessThanOrEqual(1)
}

test.describe('Header navigation overflow', () => {
  test('every section stays reachable as the window narrows', async ({ page }) => {
    await page.goto('/dashboard')
    const layout = new MainLayoutPage(page)
    await layout.expectHeaderVisible()

    // From a comfortable desktop down to the narrowest one. The set of
    // sections must not depend on any of it.
    for (const width of [1920, 1713, 1600, NARROW_DESKTOP]) {
      await page.setViewportSize({ width, height: 900 })
      await expect(page.locator('[data-testid="desktop-nav"]')).toBeVisible()

      await expectNoInlineEntryClipped(page, `${width}px`)
      expect(await layout.getVisibleNavTestIds(), `sections at ${width}px`).toEqual(ADMIN_SECTIONS)
    }
  })

  test('an entry pushed into More still opens its section', async ({ page }) => {
    await page.setViewportSize({ width: NARROW_DESKTOP, height: 900 })
    await page.goto('/members')
    const layout = new MainLayoutPage(page)
    await layout.expectHeaderVisible()

    await expect(page.locator('[data-testid="nav-more"]')).toBeVisible()
    expect(await layout.getInlineNavTestIds()).not.toContain('nav-audit-log')

    // The last section is the one that used to fall off the end.
    await layout.openNavItem('nav-audit-log')

    await expect(page).toHaveURL('/audit-log')
    await expect(page.getByTestId('audit-log-page')).toBeVisible()
    // Following an entry closes the menu behind it.
    await expect(page.locator('[data-testid="nav-more-menu"]')).toBeHidden()
  })

  test('More is marked active while an overflowed section is open', async ({ page }) => {
    await page.setViewportSize({ width: NARROW_DESKTOP, height: 900 })
    await page.goto('/audit-log')
    const layout = new MainLayoutPage(page)
    await layout.expectHeaderVisible()

    expect(await layout.getInlineNavTestIds()).not.toContain('nav-audit-log')

    // theme.activeTint.primaryStrong — the tint an inline entry gets while its
    // section is open. The point is that a section behind More is still
    // signposted; a transparent button would say the panel is nowhere.
    await expect(page.locator('[data-testid="nav-more"]')).toHaveCSS(
      'background-color',
      'rgba(59, 130, 246, 0.2)'
    )
  })
})
