/**
 * Date field — mobile (iPhone 14), #date-picker
 *
 * On a phone the calendar is a bottom sheet rather than a popover: a 320px
 * panel anchored to a field halfway up a scrolling modal is either off-screen
 * or under the keyboard, and its 32px cells are below every touch-target
 * guideline. The sheet is full width, sits on a backdrop, and its day cells are
 * at least 44px tall.
 *
 * Typing still works here — the field asks for the numeric keypad — so the
 * flow below covers both: pick through the sheet, then type.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (nothing shared is created or mutated)
 * - Pattern 004: Parallel Execution Safety
 * - Pattern 005: Test IDs for element selection
 * - Pattern 008: Playwright assertions & auto-waiting
 * - Pattern 009: User-flow-based tests
 *
 * Project config uses `devices['iPhone 14']` (390x844); the admin frontend
 * renders its mobile layout below 768px via `useBreakpoint()`.
 */

import { test, expect, type Page } from '@playwright/test'

const DOB = 'members-form-dob-input'

async function openMemberForm(page: Page) {
  await page.goto('/members', { waitUntil: 'domcontentloaded' })
  await expect(page.getByTestId('members-page')).toBeVisible({ timeout: 15000 })
  await page.getByTestId('members-create-button').click()
  await expect(page.getByTestId('members-form-modal')).toBeVisible()
}

test.describe('Date field - Mobile', () => {
  test('the calendar opens as a full-width sheet with touch-sized targets', async ({ page }) => {
    await openMemberForm(page)

    await page.getByTestId(`${DOB}-open-calendar`).click()
    const sheet = page.getByTestId(`${DOB}-sheet`)
    await expect(sheet).toBeVisible()

    const viewport = page.viewportSize()!
    const panel = page.getByTestId(`${DOB}-calendar`)
    const panelBox = (await panel.boundingBox())!
    expect(panelBox.width).toBeGreaterThan(viewport.width * 0.9)
    // Anchored to the bottom of the screen, where a thumb reaches.
    expect(panelBox.y + panelBox.height).toBeGreaterThan(viewport.height - 4)

    // An empty birth-date field opens on years, so a member born in the 1980s
    // is three taps away rather than four hundred.
    await expect(page.getByTestId(`${DOB}-calendar-year-range`)).toBeVisible()

    // The year grid opens on the block around "an adult member" — 1980–1999
    // as of today — so a member born in 1985 needs no paging at all.
    await page.getByTestId(`${DOB}-calendar-year-1985`).click()
    await page.getByTestId(`${DOB}-calendar-month-6`).click()

    const dayCell = page.getByTestId(`${DOB}-calendar-day-1985-06-15`)
    const dayBox = (await dayCell.boundingBox())!
    expect(dayBox.height).toBeGreaterThanOrEqual(44)

    await dayCell.click()

    await expect(sheet).toBeHidden()
    await expect(page.getByTestId(`${DOB}-value`)).toHaveValue('1985-06-15')
    await expect(page.getByTestId(DOB)).toHaveValue('15.06.1985')
  })

  test('the field can still be typed into, and asks for the numeric keypad', async ({ page }) => {
    await openMemberForm(page)

    const input = page.getByTestId(DOB)
    await expect(input).toHaveAttribute('inputmode', 'numeric')

    await input.click()
    await input.pressSequentially('23111979')

    await expect(input).toHaveValue('23.11.1979')
    await expect(page.getByTestId(`${DOB}-value`)).toHaveValue('1979-11-23')
  })

  test('tapping the backdrop closes the sheet without choosing a date', async ({ page }) => {
    await openMemberForm(page)

    const input = page.getByTestId(DOB)
    await input.click()
    await input.pressSequentially('23111979')

    await page.getByTestId(`${DOB}-open-calendar`).click()
    const sheet = page.getByTestId(`${DOB}-sheet`)
    await expect(sheet).toBeVisible()

    // The backdrop is the sheet's own top edge area, above the panel.
    await sheet.click({ position: { x: 10, y: 10 } })

    await expect(sheet).toBeHidden()
    await expect(page.getByTestId(`${DOB}-value`)).toHaveValue('1979-11-23')
  })
})
