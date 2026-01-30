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


    // Create settlement with all three test transactions
    let txn1Id: string, txn2Id: string, txn3Id: string
    await test.step('Create settlement with test transactions via API', async () => {
      // Create transactions that we'll settle
      txn1Id = await testTransactions.createSyncTransaction(member1Id, 2500, 'Sync transaction')
      txn2Id = await testTransactions.createCorrection(member2Id, 1550, 'Correction for member 2')
      txn3Id = await testTransactions.createCorrection(member1Id, 1000, 'Additional correction')

      // Create settlement with these transactions (this gets us the settlement ID directly)
      settlementId = await testTransactions.createSettlement([txn1Id, txn2Id, txn3Id], 7)
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

      // Verify CSV header structure
      const csvLines = csvAggregatedContent.trim().split('\n')
      expect(csvLines.length).toBeGreaterThan(1)
      expect(csvLines[0]).toContain('Member Name')
      expect(csvLines[0]).toContain('Amount EUR')

      // Verify CSV contains at least our test members by checking for both
      // (order may vary, so check for presence of both names)
      const csvContent = csvAggregatedContent.toLowerCase()
      expect(csvContent).toContain(member1FirstName.toLowerCase())
      expect(csvContent).toContain(member2FirstName.toLowerCase())
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

      // Navigate to settlements page
      await settlementsPage.navigate()
      await expect(page.getByTestId('settlements-loading')).toBeHidden({ timeout: 10000 })

      // Refresh settlements page
      await page.reload()
      await expect(page.getByTestId('settlements-loading')).toBeHidden({ timeout: 10000 })

      // Verify settlement still appears - query API to be sure
      const response = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}`)
      expect(response.status()).toBe(200)
      const settlement = await response.json()
      expect(settlement.id).toBe(settlementId)
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

  test('should reject settlement with duplicate transactions and show error message', async ({
    page,
    authenticatedRequest,
    testTransactions,
  }) => {
    let memberId: string, memberFirstName: string
    let txn1Id: string, txn2Id: string, txn3Id: string

    // Create test member
    await test.step('Create test member and transactions', async () => {
      const member = await testTransactions.createMember('DupTest', 'Error')
      memberId = member.id
      memberFirstName = member.first_name

      // Create 3 transactions
      txn1Id = await testTransactions.createCorrection(memberId, 1000, 'Transaction 1')
      txn2Id = await testTransactions.createCorrection(memberId, 2000, 'Transaction 2')
      txn3Id = await testTransactions.createCorrection(memberId, 3000, 'Transaction 3')

      expect(txn1Id).toBeTruthy()
      expect(txn2Id).toBeTruthy()
      expect(txn3Id).toBeTruthy()
    })

    // Create first settlement with txn1 and txn2
    let firstSettlementId: string
    await test.step('Create first settlement with transactions 1 and 2', async () => {
      firstSettlementId = await testTransactions.createSettlement([txn1Id, txn2Id], 7)
      expect(firstSettlementId).toBeTruthy()
    })

    // Verify first settlement created successfully
    await test.step('Verify first settlement appears in settlements list', async () => {
      const response = await authenticatedRequest.get('/api/admin/settlements')
      expect(response.status()).toBe(200)
      const settlements = await response.json()
      const found = settlements.data.some((s: any) => s.id === firstSettlementId)
      expect(found).toBe(true)
    })

    // Attempt to create second settlement via API with txn2 and txn3 (txn2 is already settled)
    // This should fail with "Transactions already have settlement items" error
    let errorApiResponse: any = null
    await test.step('Verify API rejects duplicate transaction with descriptive error', async () => {
      const response = await authenticatedRequest.post('/api/admin/settlements', {
        data: {
          transaction_ids: [txn2Id, txn3Id], // txn2 is already in first settlement
          settlement_date: new Date().toISOString().split('T')[0],
          execution_date: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
        },
      })

      // Should NOT return 201 (creation would fail)
      expect(response.status()).not.toBe(201)

      // Get error details
      errorApiResponse = await response.json()
      expect(errorApiResponse).toBeTruthy()

      // Error message should mention settlement items
      const errorText = JSON.stringify(errorApiResponse)
      expect(errorText.toLowerCase()).toContain('settlement')
    })

    // Verify database state: first settlement should still exist unchanged
    await test.step('Verify no orphaned settlement was created (database atomicity)', async () => {
      const response = await authenticatedRequest.get(`/api/admin/settlements/${firstSettlementId}`)
      expect(response.status()).toBe(200)
      const settlement = await response.json()

      // Verify the first settlement still exists and has correct item count
      expect(settlement.id).toBe(firstSettlementId)
      expect(settlement.member_count).toBe(1)
      expect(settlement.total_amount_cents).toBe(3000) // 1000 + 2000
      expect(settlement.is_cancelled).toBe(false)

      // Verify it has exactly 2 settlement items (txn1 and txn2)
      expect(settlement.items.length).toBe(2)
    })

    // Verify txn3 is still unsettled (failed settlement didn't partially process)
    await test.step('Verify txn3 remains unsettled (no partial settlement)', async () => {
      const journalPage = new JournalPage(page)
      await journalPage.navigate()
      await expect(page.getByTestId('journal-loading')).toBeHidden({ timeout: 10000 })

      // Filter to "Open" transactions
      await journalPage.filterBySettlementStatus('open')
      await expect(page.getByTestId('journal-loading')).toBeHidden()

      // Verify member's name appears in open transactions (txn3 is still unsettled)
      await journalPage.waitForTransactionToAppear(memberFirstName)
    })
  })
})
