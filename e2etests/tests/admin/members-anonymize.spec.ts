import { test, expect } from '../../fixtures/pageObjects'
import { createMemberViaPage } from '../../utils/members'

/**
 * E2E Tests: Anonymize Button on Members Page (UC-DSGVO-02)
 *
 * Tests the anonymize button in the members table, confirmation dialog,
 * and end-to-end flow: frontend → API → backend → PII erased.
 *
 * Pre-deletion checks (balance, settlement) are covered in
 * tests/api/anonymize-member.spec.ts (API-level tests).
 */
test.describe('Members Anonymize Button', () => {

  test('anonymize button is visible in table row actions', async ({ authenticatedMembersPage }) => {
    const rowCount = await authenticatedMembersPage.getMemberRowCount()
    // eslint-disable-next-line clubbar/no-data-dependent-skip -- #146: ambient member data may be absent; fixture work tracked separately, not fixed here
    test.skip(rowCount === 0, 'No members in table to test')

    await authenticatedMembersPage.expectAnyAnonymizeButtonVisible()
  })

  test('clicking anonymize shows confirmation dialog and cancel works', async ({ page, authenticatedMembersPage }) => {
    const testId = `AnonDlg${Date.now().toString().slice(-6)}`

    // Create member via API (reliable, fast)
    const member = await createMemberViaPage(page, { firstName: testId, lastName: 'DialogTest' })

    // Search for the member in the table
    await authenticatedMembersPage.search(testId)
    await authenticatedMembersPage.expectMemberVisibleInTable(testId)

    // Click anonymize button
    await authenticatedMembersPage.clickAnonymizeButtonForMember(member.id)

    // Confirmation dialog should appear with member name
    await authenticatedMembersPage.expectAnonymizeConfirmVisible()
    const message = await authenticatedMembersPage.getAnonymizeConfirmMessage()
    expect(message).toContain(testId)
    expect(message).toContain('DialogTest')

    // Cancel should close dialog without anonymizing
    await authenticatedMembersPage.cancelAnonymize()
    await authenticatedMembersPage.expectAnonymizeConfirmHidden()

    // Member should still be visible in table
    await authenticatedMembersPage.expectMemberVisibleInTable(testId)
  })

  test('confirming anonymize removes member from list and erases PII', async ({ page, authenticatedMembersPage }) => {
    const testId = `AnonOk${Date.now().toString().slice(-6)}`

    // Create member via API
    const member = await createMemberViaPage(page, { firstName: testId, lastName: 'ConfirmTest' })

    // Search for the member
    await authenticatedMembersPage.search(testId)
    await authenticatedMembersPage.expectMemberVisibleInTable(testId)

    // Click anonymize and confirm
    await authenticatedMembersPage.clickAnonymizeButtonForMember(member.id)
    await authenticatedMembersPage.expectAnonymizeConfirmVisible()

    const anonymizePromise = page.waitForResponse(
      (resp) => resp.url().includes(`/admin/members/${member.id}/anonymize`) && resp.status() === 200
    )
    await authenticatedMembersPage.confirmAnonymize()
    await anonymizePromise

    // Dialog should close
    await authenticatedMembersPage.expectAnonymizeConfirmHidden()

    // Member should disappear from list
    await authenticatedMembersPage.expectMemberNotVisibleInTable(testId)

    // Verify PII erased via authenticated fetch
    const apiRes = await page.evaluate(async (id) => {
      const res = await fetch(`/api/admin/members/${id}`)
      return res.json()
    }, member.id)
    expect(apiRes.first_name).toBeNull()
    expect(apiRes.last_name).toBeNull()
    expect(apiRes.email).toBeNull()
    expect(apiRes.deleted_at).toBeTruthy()
  })

  test('delete button is replaced by anonymize button', async ({ authenticatedMembersPage }) => {
    const rowCount = await authenticatedMembersPage.getMemberRowCount()
    // eslint-disable-next-line clubbar/no-data-dependent-skip -- #146: ambient member data may be absent; fixture work tracked separately, not fixed here
    test.skip(rowCount === 0, 'No members in table to test')

    // Delete button should NOT exist
    expect(await authenticatedMembersPage.getDeleteButtonCount()).toBe(0)

    // Anonymize button SHOULD exist
    expect(await authenticatedMembersPage.getAnonymizeButtonCount()).toBeGreaterThan(0)
  })
})
