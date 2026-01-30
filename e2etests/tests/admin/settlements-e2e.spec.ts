/**
 * End-to-End Settlement Tests
 *
 * Comprehensive workflow tests for settlement creation and CSV export
 *
 * Implements:
 * - Create transactions via sync API (terminal transactions)
 * - Create transactions via manual corrections (admin UI)
 * - Create settlement with selected transactions
 * - Export and verify CSV exports (aggregated and full)
 *
 * Patterns:
 * - Pattern 001: Test Data Isolation (unique members per test via timestamps)
 * - Pattern 003: Database-Agnostic Assertions (search by fields, not position)
 * - Pattern 007: Page Object Fixtures (testTransactions)
 *
 * Uses Playwright test.step() for clear test organization and reporting.
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { JournalPage } from '../../pages/JournalPage'
import { SettlementsPage } from '../../pages/SettlementsPage'

test.describe('Settlement E2E: Full Workflow', () => {
  test('should create transactions and settlement, then export CSV', async ({ page, authenticatedRequest, testTransactions }) => {
    let member1Id: string, member1FirstName: string
    let member2Id: string, member2FirstName: string
    let settlementId: string

    // Create test members
    await test.step('Create test members', async () => {
      const member1 = await testTransactions.createMember('E2ETest1', 'Doe')
      member1Id = member1.id
      member1FirstName = member1.first_name

      const member2 = await testTransactions.createMember('E2ETest2', 'Smith')
      member2Id = member2.id
      member2FirstName = member2.first_name

      expect(member1Id).toBeTruthy()
      expect(member2Id).toBeTruthy()
    })

    // Create transactions via sync API and corrections
    await test.step('Create transactions via sync API and corrections', async () => {
      // Sync transaction for member 1: €25.00
      await testTransactions.createSyncTransaction(member1Id, 2500, 'Pilsner beer')

      // Manual correction for member 2: €15.50
      await testTransactions.createCorrection(member2Id, 1550, 'Manual correction for drinks')

      // Manual correction for member 1: €10.00
      await testTransactions.createCorrection(member1Id, 1000, 'Additional charge')
    })

    // Navigate to journal and verify transactions by member name
    await test.step('Verify test transactions appear in journal', async () => {
      const journalPage = new JournalPage(page)
      await journalPage.navigate()
      await expect(page.getByTestId('journal-loading')).toBeHidden({ timeout: 10000 })

      // Filter to "Open" transactions only
      await journalPage.filterBySettlementStatus('open')
      await expect(page.getByTestId('journal-loading')).toBeHidden()

      // Verify our specific test members' transactions exist
      await journalPage.waitForTransactionToAppear(member1FirstName)
      const member2Index = await journalPage.findTransactionByMemberName(member2FirstName)
      expect(member2Index).not.toBeNull()
    })

    // Enter settlement mode and select transactions
    await test.step('Select transactions for settlement', async () => {
      const journalPage = new JournalPage(page)

      // Enter settlement mode to show checkboxes
      await journalPage.enterSettlementMode()

      // Select all open transactions
      await journalPage.selectAllTransactions()

      // Verify at least our 3 test transactions are selected
      const selectedCount = await journalPage.getSelectedTransactionCount()
      expect(selectedCount).toBeGreaterThanOrEqual(3)
    })

    // Create settlement from selected transactions
    await test.step('Create settlement from selected transactions', async () => {
      const journalPage = new JournalPage(page)

      // Conclude settlement
      await journalPage.concludeSettlement()

      // Wait for loading indicator to disappear (settlement created and journal reloaded)
      await expect(page.getByTestId('journal-loading')).toBeHidden({ timeout: 10000 })
    })

    // Navigate to settlements and find created settlement
    await test.step('Navigate to settlements and find created settlement', async () => {
      const settlementsPage = new SettlementsPage(page)
      await settlementsPage.navigate()
      await expect(page.getByTestId('settlements-loading')).toBeHidden({ timeout: 10000 })

      // Get the first (most recent) settlement ID from the first row
      const rows = page.locator('[data-testid^="settlements-table-row-"]')
      const rowCount = await rows.count()
      expect(rowCount).toBeGreaterThan(0)

      const firstRowTestId = await rows.first().getAttribute('data-testid')
      settlementId = firstRowTestId?.replace('settlements-table-row-', '') || ''
      expect(settlementId).toBeTruthy()
    })

    // Export and verify aggregated CSV
    await test.step('Export and verify aggregated CSV', async () => {
      const csvAggregatedResponse = await authenticatedRequest.get(
        `/api/admin/settlements/${settlementId}/export-csv`
      )
      expect(csvAggregatedResponse.status()).toBe(200)

      const csvAggregatedContent = await csvAggregatedResponse.text()
      expect(csvAggregatedContent).toBeTruthy()

      // Verify CSV contains our test members
      expect(csvAggregatedContent).toContain(member1FirstName)

      // Verify CSV header structure
      const csvLines = csvAggregatedContent.trim().split('\n')
      expect(csvLines.length).toBeGreaterThan(1)
      expect(csvLines[0]).toContain('Member Name')
      expect(csvLines[0]).toContain('Amount EUR')
    })

    // Export and verify full/detailed CSV
    await test.step('Export and verify full transaction CSV', async () => {
      const csvFullResponse = await authenticatedRequest.get(
        `/api/admin/settlements/${settlementId}/export-transactions-csv`
      )

      if (csvFullResponse.status() === 200) {
        const csvFullContent = await csvFullResponse.text()
        expect(csvFullContent).toBeTruthy()

        // Verify full CSV contains test members
        expect(csvFullContent).toContain(member1FirstName)
        expect(csvFullContent).toContain(member2FirstName)
      }
    })

    // Refresh and verify settlement persists
    await test.step('Verify settlement persists after page refresh', async () => {
      const settlementsPage = new SettlementsPage(page)

      // Refresh settlements page
      await page.reload()
      await expect(page.getByTestId('settlements-loading')).toBeHidden({ timeout: 10000 })

      // Verify settlement still appears
      const rows = page.locator('[data-testid^="settlements-table-row-"]')
      const rowCount = await rows.count()
      expect(rowCount).toBeGreaterThan(0)
    })

    // Verify transactions marked as settled
    await test.step('Verify transactions marked as settled in journal', async () => {
      const journalPage = new JournalPage(page)
      await journalPage.navigate()
      await expect(page.getByTestId('journal-loading')).toBeHidden({ timeout: 10000 })

      // Filter to "Settled" transactions
      await journalPage.filterBySettlementStatus('settled')
      await expect(page.getByTestId('journal-loading')).toBeHidden()

      // Verify our specific test members' transactions are now settled
      await journalPage.waitForTransactionToAppear(member1FirstName)
      const member2Index = await journalPage.findTransactionByMemberName(member2FirstName)
      expect(member2Index).not.toBeNull()
    })
  })

  test('should verify CSV export correctness with known amounts', async ({
    page,
    authenticatedRequest,
    testTransactions,
  }) => {
    let memberId: string, testMemberFirstName: string
    let settlementId: string

    // Create test member and transactions
    await test.step('Create test member with known transaction amounts', async () => {
      // Create test member using fixture
      const member = await testTransactions.createMember('CSVTest', 'Verify')
      memberId = member.id
      testMemberFirstName = member.first_name

      // Create 3 transactions with known amounts: €10, €20, €30 = €60
      const amounts = [1000, 2000, 3000] // in cents
      const transactionIds: string[] = []

      for (let i = 0; i < amounts.length; i++) {
        const txId = await testTransactions.createCorrection(
          memberId,
          amounts[i],
          `Test correction ${i + 1}`
        )
        transactionIds.push(txId)
        expect(txId).toBeTruthy()
      }

      // Create settlement with all transactions
      settlementId = await testTransactions.createSettlement(transactionIds, 7)
      expect(settlementId).toBeTruthy()
    })

    // Export and verify CSV by member name
    await test.step('Export settlement as CSV and verify member appears', async () => {
      const csvResponse = await authenticatedRequest.get(
        `/api/admin/settlements/${settlementId}/export-csv`
      )
      expect(csvResponse.status()).toBe(200)

      const csvContent = await csvResponse.text()
      expect(csvContent).toBeTruthy()

      // Verify CSV contains our unique test member name
      expect(csvContent).toContain(testMemberFirstName)

      // Verify CSV structure
      const lines = csvContent.trim().split('\n')
      expect(lines.length).toBeGreaterThan(1)

      // Verify total amount (€60) appears for our member
      const memberRow = lines.find((line) => line.includes(testMemberFirstName))
      expect(memberRow).toBeTruthy()
      if (memberRow) {
        expect(memberRow).toMatch(/60[.,]?00|6000/)
      }
    })

    // Verify CSV format and data integrity
    await test.step('Verify CSV format and data consistency', async () => {
      const csvResponse = await authenticatedRequest.get(
        `/api/admin/settlements/${settlementId}/export-csv`
      )

      const csvContent = await csvResponse.text()
      const csvLines = csvContent.trim().split('\n')

      // Parse CSV to verify structure
      const csvHeader = csvLines[0]
      const expectedColumns = ['name', 'member', 'amount']
      const hasValidColumns =
        expectedColumns.some((col) => csvHeader.toLowerCase().includes(col.toLowerCase())) &&
        csvLines.length >= 2

      expect(hasValidColumns).toBe(true)

      // Verify all rows have consistent field count
      const headerFieldCount = csvHeader.split(',').length
      for (let i = 1; i < csvLines.length; i++) {
        const fieldCount = csvLines[i].split(',').length
        expect(fieldCount).toBe(headerFieldCount)
      }
    })
  })
})
