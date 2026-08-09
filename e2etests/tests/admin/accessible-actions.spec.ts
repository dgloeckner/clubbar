/**
 * Accessibility and responsive gaps in the admin panel (#138)
 *
 * Three defects, all verifiable from the rendered page:
 *
 *  1. The destructive row actions are icon-only and carried a `title` and
 *     nothing else. `title` is a mouse affordance — a screen reader announces
 *     the accessible name, and these buttons had none. Anonymize erases a
 *     member under GDPR Art. 17 and undo reverses a settlement, so these are
 *     the two controls least able to afford being unnamed.
 *
 *  2. /profile was unreachable between 769px and 1500px. `useBreakpoint` calls
 *     everything ≤1500px "tablet", the header hid the profile badge on tablet,
 *     the nav has no profile entry and BottomTabBar renders on mobile only —
 *     so on a 1366px laptop, language preference and password change were
 *     URL-only features.
 *
 *  3. The settlement exports read SEPA / CSV / TXN with hardcoded English
 *     tooltips. "TXN" means nothing to a club volunteer.
 *
 * E2E Patterns applied:
 *  - Pattern 001: each test creates its own member / category / settlement
 *  - Pattern 004: no shared state, safe under 4 workers
 *  - Pattern 006/007: page object methods, no raw locators
 *  - Pattern 008: expect() assertions, never try-catch visibility checks
 *
 * Assertions are language-agnostic on purpose. The admin's UI language comes
 * from the seeded account, so pinning an English string here would make these
 * tests fail for a reason that has nothing to do with what they check.
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { createMemberViaPage } from '../../utils/members'
import { MembersPage } from '../../pages/MembersPage'
import { CategoriesPage } from '../../pages/CategoriesPage'
import { SettlementsPage } from '../../pages/SettlementsPage'

test.describe('#138: destructive icon buttons have accessible names', () => {
  test('members: edit and anonymize name the member they act on', async ({ page }) => {
    const marker = `A11yMbr${Date.now().toString().slice(-6)}`

    // Navigate first: createMemberViaPage reads the CSRF token out of the app's
    // localStorage, which is unreadable until the page is on the app's origin.
    const membersPage = new MembersPage(page)
    await membersPage.navigate()

    const member = await createMemberViaPage(page, { firstName: marker, lastName: 'RowAction' })

    await membersPage.search(marker)
    await membersPage.expectMemberVisibleInTable(marker)

    // Both buttons render an SVG and no text, so without an aria-label their
    // accessible name is the empty string and this assertion cannot pass.
    await membersPage.expectRowActionsNameTheMember(member.id, marker)
  })

  test('categories: edit and delete name the category they act on', async ({ page }) => {
    const marker = `A11yCat${Date.now().toString().slice(-6)}`

    const categoriesPage = new CategoriesPage(page)
    await categoriesPage.navigate()
    await categoriesPage.createCategory({ de: marker, en: marker })

    const categoryId = await categoriesPage.findCategoryByName(marker)
    expect(categoryId, `category "${marker}" should be in the list after creation`).not.toBeNull()

    await categoriesPage.expectRowActionsNameTheCategory(categoryId as string, marker)
  })

  test('settlements: undo announces what it undoes', async ({ page, settlementFactory }) => {
    const settlement = await settlementFactory.create({ amountCents: 1200, memberCount: 1 })

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // The desktop button's only content is a "↩" glyph, which a screen reader
    // either skips or reads as punctuation.
    const undoName = await settlementsPage.getUndoAccessibleName(settlement.id)
    expect(undoName).toBeTruthy()
    expect((undoName as string).length).toBeGreaterThan(3)
  })
})

test.describe('#138: settlement export buttons say what they export', () => {
  test('no button is labelled TXN, and each explains itself', async ({ page, settlementFactory }) => {
    const settlement = await settlementFactory.create({ amountCents: 900, memberCount: 1 })

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    const [, , transactionsLabel] = await settlementsPage.getExportButtonLabels(settlement.id)
    expect(transactionsLabel.trim()).not.toBe('TXN')
    expect(transactionsLabel.trim()).toMatch(/Transactions|Buchungen/)

    // The tooltips used to be hardcoded English and invisible to a keyboard or
    // screen-reader user; every export now carries the explanation as its
    // accessible name, in the admin's language.
    const names = await settlementsPage.getExportButtonAccessibleNames(settlement.id)
    for (const name of names) {
      expect(name).toBeTruthy()
      expect((name as string).length).toBeGreaterThan('SEPA'.length)
    }
  })
})

test.describe('#138: /profile is reachable on a laptop', () => {
  // 1366x768 is the width the issue names: comfortably above the 768px mobile
  // cutoff (so no BottomTabBar) and below the 1500px desktop cutoff (so the
  // header used to hide the badge). The admin project's own 1920px viewport
  // never entered this band, which is how the gap survived.
  test.use({ viewport: { width: 1366, height: 768 } })

  test('the header badge is present and navigates to the profile', async ({ page, mainLayoutPage }) => {
    await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
    await mainLayoutPage.waitForNavigation()

    await mainLayoutPage.expectUserBadgeVisible()

    // The name is dropped at this width to match the icon-only nav tabs, so the
    // badge needs an explicit accessible name to stay more than a bare icon.
    const badgeName = await mainLayoutPage.getUserBadgeAccessibleName()
    expect(badgeName).toBeTruthy()

    await mainLayoutPage.clickUserBadge()
    expect(page.url()).toContain('/profile')
  })
})
