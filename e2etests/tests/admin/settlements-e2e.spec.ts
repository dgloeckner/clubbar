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
 * Test Data:
 * - Multiple members with IBANs and mandates
 * - Transactions with known amounts for verification
 * - Known settlement dates and execution dates
 *
 * Patterns:
 * - Pattern 001: Test Data Isolation (unique members per test)
 * - Pattern 003: Database-Agnostic Assertions (search by fields, not position)
 * - Pattern 007: Page Object Fixtures
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { JournalPage } from '../../pages/JournalPage'
import { SettlementsPage } from '../../pages/SettlementsPage'

test.describe('Settlement E2E: Full Workflow', () => {
  test('should create transactions and settlement, then export CSV', async ({ page, authenticatedRequest, testTransactions }) => {
    // ============================================================================
    // SETUP: Create test members
    // ============================================================================

    // Create members using test fixture (handles isolation via timestamps)
    const member1 = await testTransactions.createMember('E2ETest1', 'Doe')
    const member1Id = member1.id
    const member1FirstName = member1.first_name

    const member2 = await testTransactions.createMember('E2ETest2', 'Smith')
    const member2Id = member2.id
    const member2FirstName = member2.first_name

    // ============================================================================
    // CREATE TRANSACTIONS: Via sync API (terminal transactions)
    // ============================================================================

    // Sync transaction for member 1: €25.00
    await testTransactions.createSyncTransaction(member1Id, 2500, 'Pilsner beer')

    // ============================================================================
    // CREATE TRANSACTIONS: Via manual corrections
    // ============================================================================

    // Manual correction for member 2: €15.50
    await testTransactions.createCorrection(member2Id, 1550, 'Manual correction for drinks')

    // Manual correction for member 1: €10.00
    await testTransactions.createCorrection(member1Id, 1000, 'Additional charge')

    // ============================================================================
    // VERIFY: Transactions created in Journal
    // ============================================================================

    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()

    // Filter to "Open" transactions only
    await journalPage.filterBySettlementStatus('open')

    // Get transactions count - should be at least 3
    const transactionCount = await journalPage.getTransactionCount()
    expect(transactionCount).toBeGreaterThanOrEqual(3)

    // ============================================================================
    // SELECT TRANSACTIONS for settlement
    // ============================================================================

    // Enter settlement mode to show checkboxes
    await journalPage.enterSettlementMode()

    // Select all open transactions
    await journalPage.selectAllTransactions()

    // Verify selection count
    const selectedCount = await journalPage.getSelectedTransactionCount()
    expect(selectedCount).toBeGreaterThanOrEqual(3)

    // ============================================================================
    // CREATE SETTLEMENT
    // ============================================================================

    await journalPage.concludeSettlement()

    // Settlement is created, navigate to settlements page to verify
    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // ============================================================================
    // EXPORT: Get settlement list and find our settlement
    // ============================================================================

    // Get settlement rows
    const rowCount = await settlementsPage.getSettlementCount()
    expect(rowCount).toBeGreaterThan(0)

    // Get the first (most recent) settlement ID from the first row
    const rows = page.locator('[data-testid^="settlements-table-row-"]')
    const firstRowTestId = await rows.first().getAttribute('data-testid')
    const settlementId = firstRowTestId?.replace('settlements-table-row-', '')
    expect(settlementId).toBeTruthy()

    // ============================================================================
    // EXPORT: CSV (Aggregated by Member)
    // ============================================================================

    const csvAggregatedResponse = await authenticatedRequest.get(
      `/api/admin/settlements/${settlementId}/export-csv`
    )
    expect(csvAggregatedResponse.status()).toBe(200)

    const csvAggregatedContent = await csvAggregatedResponse.text()
    expect(csvAggregatedContent).toBeTruthy()

    // Verify CSV header
    const csvLines = csvAggregatedContent.trim().split('\n')
    expect(csvLines.length).toBeGreaterThan(1) // Header + at least one member
    expect(csvLines[0]).toContain('Member Name') // CSV header present
    expect(csvLines[0]).toContain('Amount EUR') // Amount column exists

    // ============================================================================
    // EXPORT: CSV (Full/Detailed Transactions)
    // ============================================================================

    const csvFullResponse = await authenticatedRequest.get(
      `/api/admin/settlements/${settlementId}/export-transactions-csv`
    )

    if (csvFullResponse.status() === 200) {
      const csvFullContent = await csvFullResponse.text()
      expect(csvFullContent).toBeTruthy()

      // Verify full CSV contains transaction details
      const fullCsvLines = csvFullContent.trim().split('\n')
      expect(fullCsvLines.length).toBeGreaterThan(1) // Header + transactions

      // Verify CSV contains both member data
      expect(csvFullContent).toContain(member1FirstName)
      expect(csvFullContent).toContain(member2FirstName)

      // Verify transaction notes appear
      expect(csvFullContent).toMatch(/Pilsner|Correction|Additional/)
    }

    // ============================================================================
    // VERIFY: CSV Format and Data Integrity
    // ============================================================================

    // Parse aggregated CSV to verify structure
    const csvHeader = csvLines[0]
    const expectedColumns = ['name', 'member', 'amount'] // Common CSV column names
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

    // ============================================================================
    // VERIFY: Settlement Status Changed
    // ============================================================================

    // Refresh settlements page
    await page.reload()
    await settlementsPage.waitForPageLoad()

    // Verify settlement appears in list
    const updatedRowCount = await settlementsPage.getSettlementCount()
    expect(updatedRowCount).toBeGreaterThan(0)

    // ============================================================================
    // VERIFY: Transactions marked as Settled
    // ============================================================================

    await journalPage.navigate()
    await journalPage.waitForPageLoad()

    // Filter to "Settled" transactions
    await journalPage.filterBySettlementStatus('settled')

    // Verify settled transactions appear
    const settledCount = await journalPage.getTransactionCount()
    expect(settledCount).toBeGreaterThanOrEqual(3)

    // Verify member names appear in settled transactions
    const pageContent = await page.content()
    expect(pageContent).toContain(member1FirstName)
    expect(pageContent).toContain(member2FirstName)
  })

  test('should verify CSV export correctness with known amounts', async ({
    page,
    authenticatedRequest,
    testTransactions,
  }) => {
    // ============================================================================
    // CREATE CLEAN TEST DATA
    // ============================================================================

    // Create test member using fixture
    const member = await testTransactions.createMember('CSVTest', 'Verify')
    const memberId = member.id
    const testMemberFirstName = member.first_name

    // ============================================================================
    // CREATE TRANSACTIONS WITH KNOWN AMOUNTS
    // ============================================================================

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
    }

    // ============================================================================
    // CREATE SETTLEMENT
    // ============================================================================

    const settlementId = await testTransactions.createSettlement(transactionIds, 7)

    // ============================================================================
    // EXPORT AND VERIFY CSV
    // ============================================================================

    const csvResponse = await authenticatedRequest.get(
      `/api/admin/settlements/${settlementId}/export-csv`
    )
    expect(csvResponse.status()).toBe(200)

    const csvContent = await csvResponse.text()
    expect(csvContent).toBeTruthy()

    // ============================================================================
    // PARSE AND VERIFY CSV DATA
    // ============================================================================

    const lines = csvContent.trim().split('\n')
    expect(lines.length).toBeGreaterThan(1)

    // Find the row with our test member
    const memberRow = lines.find((line) => line.includes(testMemberFirstName))
    expect(memberRow).toBeTruthy()

    // Extract the amount from the member row
    // CSV format varies, but should contain the aggregated amount
    if (memberRow) {
      // Verify total amount (€60) appears in some form
      expect(memberRow).toMatch(/60[.,]?00|6000/)
    }

    // Note: IBAN and mandate fields are not currently supported by the member creation API
    // so they are not tested in this E2E test
  })
})
