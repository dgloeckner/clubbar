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

/**
 * Narrow the window — staying inside the desktop breakpoint, since 769–1500px
 * is the icon-only row — until the header has to open a "More" menu, and
 * report the width where that happened.
 *
 * Which width that is depends on the language: `Benachrichtigungen` and
 * `Datensicherungen` need roughly twice what their English labels do. Sweeping
 * keeps the test about the behaviour rather than about a number that only
 * holds in one locale, and `null` says the row never had to overflow at all —
 * in which case there is nothing here to assert.
 */
async function narrowUntilOverflow(
  page: import('@playwright/test').Page,
  layout: MainLayoutPage
): Promise<number | null> {
  for (const width of [1920, 1700, 1600, 1520]) {
    await page.setViewportSize({ width, height: 900 })
    await expectNoInlineEntryClipped(page, `${width}px`)
    if ((await layout.getInlineNavTestIds()).length < ADMIN_SECTIONS.length) {
      return width
    }
  }
  return null
}

test.describe('Header navigation overflow', () => {
  test('every section stays reachable as the window narrows', async ({ page }) => {
    await page.goto('/dashboard')
    const layout = new MainLayoutPage(page)
    await layout.expectHeaderVisible()

    // Widths from a comfortable desktop down to just above the tablet
    // breakpoint (769–1500px is icon-only and has its own row).
    for (const width of [1920, 1713, 1600, 1520]) {
      await page.setViewportSize({ width, height: 900 })
      await expect(page.locator('[data-testid="desktop-nav"]')).toBeVisible()

      await expectNoInlineEntryClipped(page, `${width}px`)
      expect(await layout.getVisibleNavTestIds(), `sections at ${width}px`).toEqual(ADMIN_SECTIONS)
    }
  })

  test('an entry pushed into More still opens its section', async ({ page }) => {
    await page.goto('/members')
    const layout = new MainLayoutPage(page)
    await layout.expectHeaderVisible()

    const width = await narrowUntilOverflow(page, layout)
    test.skip(width === null, 'the row fits every entry at every desktop width in this language')

    const inline = await layout.getInlineNavTestIds()
    expect(inline.length, `inline entries at ${width}px`).toBeLessThan(ADMIN_SECTIONS.length)

    // The last section is the one that used to fall off the end.
    await layout.openNavItem('nav-audit-log')

    await expect(page).toHaveURL('/audit-log')
    await expect(page.getByTestId('audit-log-page')).toBeVisible()
    // Following an entry closes the menu behind it.
    await expect(page.locator('[data-testid="nav-more-menu"]')).toBeHidden()
  })

  test('More is marked active while an overflowed section is open', async ({ page }) => {
    await page.goto('/audit-log')
    const layout = new MainLayoutPage(page)
    await layout.expectHeaderVisible()

    const width = await narrowUntilOverflow(page, layout)
    test.skip(width === null, 'the row fits every entry at every desktop width in this language')
    test.skip(
      (await layout.getInlineNavTestIds()).includes('nav-audit-log'),
      'the open section is still inline at that width'
    )

    // theme.activeTint.primaryStrong — the tint an inline entry gets while its
    // section is open. The point is that a section behind More is still
    // signposted; a transparent button would say the panel is nowhere.
    await expect(page.locator('[data-testid="nav-more"]')).toHaveCSS(
      'background-color',
      'rgba(59, 130, 246, 0.2)'
    )
  })
})
