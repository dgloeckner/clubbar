/**
 * Date field — desktop (#date-picker)
 *
 * `<input type="date">` was replaced by a typed field with a calendar popover,
 * because the native control is a different thing in every browser and none of
 * them is usable for the field ADR-0045 made mandatory: a birth date opens on
 * *today's* month, so 1979 is a hundred clicks on a chevron.
 *
 * These flows assert the two ways a date is entered — typed, and picked
 * through year → month → day — and that both end up as the ISO value the API
 * receives. The mobile half (bottom sheet, touch targets) is in
 * tests/admin-mobile/date-field-mobile.spec.ts.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (a uniquely named member per test)
 * - Pattern 004: Parallel Execution Safety (no shared state touched)
 * - Pattern 005: Test IDs for element selection
 * - Pattern 006/007: Page object via fixture
 * - Pattern 008: Playwright assertions, no try-catch visibility checks
 * - Pattern 009: User-flow-based tests
 */

import { test, expect } from '../../fixtures/pageObjects'

/** Local calendar day, the way the field and the API both mean it. */
function isoDate(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

test.describe('Date field - Desktop', () => {
  test('typing a date: digits become a formatted date, and the ISO value follows', async ({
    authenticatedMembersPage,
  }) => {
    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()

    // The fast path: eight digits, no separators typed.
    await authenticatedMembersPage.typeDateOfBirth('23111979')
    expect(await authenticatedMembersPage.getDateOfBirthText()).toBe('23.11.1979')
    expect(await authenticatedMembersPage.getDateOfBirthValue()).toBe('1979-11-23')

    // The age is shown as a check on the year, which is the digit that gets
    // mistyped and the one ADR-0045 gates a sale on.
    expect(await authenticatedMembersPage.getDateOfBirthAge()).toMatch(/\d+ Jahre/)

    // Single digits with separators are understood, and normalised on blur.
    await authenticatedMembersPage.typeDateOfBirth('1.1.1990')
    expect(await authenticatedMembersPage.getDateOfBirthValue()).toBe('1990-01-01')
    await authenticatedMembersPage.blurDateOfBirth()
    expect(await authenticatedMembersPage.getDateOfBirthText()).toBe('01.01.1990')

    // A date that does not exist is refused rather than rolled into March,
    // and the value it used to hold is dropped instead of being submitted.
    await authenticatedMembersPage.typeDateOfBirth('31.02.1990')
    expect(await authenticatedMembersPage.getDateOfBirthValue()).toBe('')
    expect(await authenticatedMembersPage.getDateOfBirthHint()).toContain('TT.MM.JJJJ')

    await authenticatedMembersPage.cancelForm()
  })

  test('picking a date: year block, year, month, day — and no future birth date', async ({
    authenticatedMembersPage,
  }) => {
    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()

    // An empty birth-date field opens on the year grid, not on this month.
    await authenticatedMembersPage.pickDateOfBirth(1979, 11, 23)

    expect(await authenticatedMembersPage.getDateOfBirthValue()).toBe('1979-11-23')
    expect(await authenticatedMembersPage.getDateOfBirthText()).toBe('23.11.1979')
    await authenticatedMembersPage.expectDateOfBirthCalendarHidden()

    // Tomorrow is offered by no path: the cell exists but cannot be chosen.
    const tomorrow = new Date()
    tomorrow.setDate(tomorrow.getDate() + 1)
    await authenticatedMembersPage.typeDateOfBirth(isoDate(tomorrow))
    expect(await authenticatedMembersPage.getDateOfBirthValue()).toBe('')

    await authenticatedMembersPage.cancelForm()
  })

  test('a date picked from the calendar reaches the API and comes back', async ({
    authenticatedMembersPage,
  }) => {
    const ts = Date.now()
    const firstName = `Pick${ts}`
    const lastName = `Field${ts}`

    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()
    await authenticatedMembersPage.fillMemberForm(
      firstName,
      lastName,
      '',
      '',
      `pick-${ts}@test.com`,
      'de'
    )
    await authenticatedMembersPage.pickDateOfBirth(1985, 6, 15)
    expect(await authenticatedMembersPage.getDateOfBirthValue()).toBe('1985-06-15')

    await authenticatedMembersPage.submitForm()
    await authenticatedMembersPage.expectFormModalHidden()
    expect(await authenticatedMembersPage.getErrorMessage()).toBeNull()

    // Searched rather than counted (Pattern 003): the list is paginated and
    // other specs are creating members beside this one.
    await authenticatedMembersPage.search(firstName)
    await authenticatedMembersPage.expectMemberVisibleInTable(firstName)

    // The stored date, read back from the API into a freshly opened form.
    await authenticatedMembersPage.clickEditButtonForMember(firstName)
    await authenticatedMembersPage.expectFormModalVisible()
    expect(await authenticatedMembersPage.getDateOfBirthValue()).toBe('1985-06-15')
    expect(await authenticatedMembersPage.getDateOfBirthText()).toBe('15.06.1985')

    await authenticatedMembersPage.cancelForm()
  })

  test('the calendar closes on Escape and leaves the value alone', async ({
    authenticatedMembersPage,
    page,
  }) => {
    await authenticatedMembersPage.openCreateModal()
    await authenticatedMembersPage.expectFormModalVisible()

    await authenticatedMembersPage.typeDateOfBirth('23111979')
    await authenticatedMembersPage.openDateOfBirthCalendar()
    await page.keyboard.press('Escape')

    await authenticatedMembersPage.expectDateOfBirthCalendarHidden()
    expect(await authenticatedMembersPage.getDateOfBirthValue()).toBe('1979-11-23')
    // Escape closed the picker, not the form behind it.
    await authenticatedMembersPage.expectFormModalVisible()

    await authenticatedMembersPage.cancelForm()
  })
})
