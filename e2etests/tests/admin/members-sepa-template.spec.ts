import { test, expect } from '../../fixtures/auth.fixture'
import { MembersPage } from '../../pages/MembersPage'

const API_BASE = 'http://localhost:8080/api'

/**
 * SEPA Mandate Template Download E2E Test
 *
 * Verifies that the "Download SEPA Template" button on the Members page
 * triggers a PDF download when SEPA config is complete.
 *
 * Patterns: 001 (test data isolation), 005 (test IDs), 008 (expect assertions)
 */
test.describe('Members SEPA Template Download', () => {
  test('clicking "SEPA Template" button triggers PDF download', async ({
    page,
    authenticatedRequest,
  }) => {
    // Ensure SEPA config has all required fields for PDF generation
    const configResp = await authenticatedRequest.put(`${API_BASE}/admin/sepa-config`, {
      data: {
        creditor_id: 'DE98ZZZ09999999999',
        creditor_name: 'E2E Test Club',
        creditor_iban: 'DE89370400440532013000',
        creditor_address_street: 'Teststrasse 1',
        creditor_address_city: 'Berlin',
        creditor_address_country: 'DE',
      },
    })
    expect(configResp.status()).toBe(200)

    // Navigate to members page
    await page.goto('/members', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="members-page"]', { timeout: 5000 })

    const membersPage = new MembersPage(page)

    // Set up download listener before clicking
    const downloadPromise = page.waitForEvent('download')
    await membersPage.clickSepaTemplateDownloadButton()

    // Verify download triggered with correct filename
    const download = await downloadPromise
    expect(download.suggestedFilename()).toBe('sepa-mandate-template.pdf')

    // Verify file is a non-trivial PDF (guards against blank/empty PDF regressions)
    const path = await download.path()
    if (path) {
      const fs = await import('fs')
      const stats = fs.statSync(path)
      expect(stats.size).toBeGreaterThan(1500)
    }
  })
})
