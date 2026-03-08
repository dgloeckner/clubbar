import { test, expect } from '@playwright/test'
import { MembersPage } from '../../pages/MembersPage'

test.describe('Members GDPR Export Button (UC-DSGVO-01)', () => {
  let membersPage: MembersPage

  test.beforeEach(async ({ page }) => {
    membersPage = new MembersPage(page)
    await membersPage.navigate()
    await membersPage.expectPageVisible()
  })

  test('export button is visible when editing an existing member', async ({ page }) => {
    // Click edit on first member in table
    await membersPage.clickEditButtonAtRowIndex(0)
    await membersPage.expectFormModalVisible()

    // Export button should be visible
    await membersPage.expectExportButtonVisible()
  })

  test('export button is NOT visible when creating a new member', async ({ page }) => {
    await membersPage.openCreateModal()
    await membersPage.expectFormModalVisible()

    // Export button should NOT be visible
    await membersPage.expectExportButtonHidden()
  })

  test('clicking export triggers file download', async ({ page }) => {
    // Click edit on first member
    await membersPage.clickEditButtonAtRowIndex(0)
    await membersPage.expectFormModalVisible()

    // Set up download listener before clicking
    const downloadPromise = page.waitForEvent('download')

    await membersPage.clickExportButton()

    // Verify download triggered
    const download = await downloadPromise
    expect(download.suggestedFilename()).toMatch(/^member-export-.*\.json$/)

    // Verify downloaded content is valid JSON with expected structure
    const path = await download.path()
    if (path) {
      const fs = await import('fs')
      const content = fs.readFileSync(path, 'utf-8')
      const data = JSON.parse(content)

      expect(data).toHaveProperty('member')
      expect(data).toHaveProperty('transactions')
      expect(data).toHaveProperty('export_timestamp')
      expect(data.member).toHaveProperty('id')
      expect(data.member).toHaveProperty('first_name')
      expect(data.member).toHaveProperty('last_name')
    }
  })
})
