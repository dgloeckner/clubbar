/**
 * Journal Page E2E Tests
 *
 * Tests for global transaction journal page with filtering, sorting, and pagination.
 * Implements UC-A20 derivative: View all transactions across all members
 *
 * Test Patterns:
 * - Pattern 001: Test Data Isolation (unique test data per test)
 * - Pattern 002: Authentication Isolation (use authenticatedJournalPage fixture)
 * - Pattern 003: Database-Agnostic Assertions (search by name, not position)
 * - Pattern 005: Using Test IDs for element selection
 * - Pattern 006: Page Object Model
 * - Pattern 008: Playwright Assertions (use expect() instead of try-catch)
 *
 * E2E Integration:
 * - Create members via API
 * - Create transactions via API (corrections via admin endpoint)
 * - Verify transactions appear in table
 * - Verify filtering and sorting work
 */

import { test, expect } from '../../fixtures/pageObjects'

test.describe('Journal Page - Transaction Display', () => {
  /**
   * Test 1: Display transactions in table after creating them via API
   *
   * E2E Verification Flow:
   * 1. Create test member via POST /api/admin/members
   * 2. Create correction transaction via POST /api/admin/members/{id}/transactions/correct
   * 3. Navigate to journal page
   * 4. Verify transaction appears in table
   * 5. Verify transaction details are correct
   */
  test('should display transactions in table after creating them via API', async ({
    page,
    authenticatedJournalPage,
  }) => {
    // === ARRANGE ===
    // Generate unique test data
    const testDataPrefix = `journal-test-${Date.now()}`
    const memberData = {
      first_name: `TestFirst${testDataPrefix}`,
      last_name: `TestLast${testDataPrefix}`,
      email: `${testDataPrefix}@example.com`,
      iban: 'DE89370400440532013000',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    // Step 1: Create test member via API (POST /api/admin/members)
    console.log('Creating test member...', memberData.first_name)
    const createMemberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })

    if (!createMemberResponse.ok()) {
      console.error('Failed to create member:', await createMemberResponse.text())
    }
    expect(createMemberResponse.ok(), 'Member creation should succeed').toBeTruthy()
    const memberJson = await createMemberResponse.json()
    const memberId = memberJson.id
    console.log('Created member with ID:', memberId)

    // Step 2: Create correction transaction (POST /api/admin/members/{id}/transactions/correct)
    console.log('Creating correction transaction...')
    const correctionData = {
      amount_cents: 5000, // €50.00
      reason: 'E2E test correction transaction',
    }

    const txResponse = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correct`,
      {
        data: correctionData,
      }
    )

    if (!txResponse.ok()) {
      console.error('Failed to create transaction:', await txResponse.text())
    }
    expect(txResponse.ok(), 'Transaction creation should succeed').toBeTruthy()
    const txJson = await txResponse.json()
    console.log('Created transaction with ID:', txJson.id)

    // === ACT ===
    // Navigate to journal page (already authenticated by fixture)
    console.log('Navigating to journal page...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()

    // Wait for table to load
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify transaction count increased
    console.log('Checking transaction count...')
    const transactionCount = await authenticatedJournalPage.getTransactionCount()
    console.log(`Found ${transactionCount} transactions in table`)
    expect(transactionCount, 'Should have at least 1 transaction').toBeGreaterThanOrEqual(1)

    // Verify we can find our member's transaction in the table
    console.log(`Searching for member: ${memberData.first_name}`)
    const memberNameIndex = await authenticatedJournalPage.findTransactionByMemberName(memberData.first_name)
    expect(memberNameIndex, 'Should find transaction for test member').not.toBeNull()

    // Verify transaction details
    if (memberNameIndex !== null) {
      console.log(`Found transaction at row ${memberNameIndex}`)
      const row = await authenticatedJournalPage.getTransactionRow(memberNameIndex)
      console.log('Transaction details:', row)

      expect(row.member, 'Member name should be in row').toContain(memberData.first_name)
      expect(row.type.toLowerCase(), 'Transaction type should be correction').toBe('correction')
      expect(row.amount, 'Amount should be displayed').toBeTruthy()
      expect(row.amount, 'Amount should be 50.00').toContain('50.00')
      expect(row.description, 'Description should contain reason').toContain('E2E test correction')
    }
  })

  /**
   * Test 2: Create multiple transactions and verify they all appear
   *
   * E2E Verification Flow:
   * 1. Create single test member
   * 2. Create 3 separate correction transactions
   * 3. Navigate to journal
   * 4. Verify all 3 transactions appear in table
   */
  test('should display multiple transactions for the same member', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    const testId = `journal-multi-${Date.now()}`
    const memberData = {
      first_name: `MultiTx${testId}`,
      last_name: 'Member',
      email: `${testId}@example.com`,
      iban: 'DE89370400440532013001',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    // Create test member
    console.log('Creating member for multi-transaction test...')
    const createMemberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })
    expect(createMemberResponse.ok()).toBeTruthy()
    const memberId = (await createMemberResponse.json()).id

    // Create 3 transactions
    const amounts = [100, 250, 500]
    const txIds: string[] = []

    for (const amount of amounts) {
      console.log(`Creating transaction with amount ${amount}...`)
      const txResponse = await page.request.post(
        `http://localhost:8080/api/admin/members/${memberId}/transactions/correct`,
        {
          data: {
            amount_cents: amount,
            reason: `Multi-transaction test #${txIds.length + 1}`,
          },
        }
      )
      expect(txResponse.ok()).toBeTruthy()
      const txData = await txResponse.json()
      txIds.push(txData.id)
    }

    console.log(`Created ${txIds.length} transactions`)

    // === ACT ===
    console.log('Navigating to journal...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    const transactionCount = await authenticatedJournalPage.getTransactionCount()
    expect(transactionCount, 'Should have at least 3 transactions').toBeGreaterThanOrEqual(3)

    // Find all transactions for this member
    console.log(`Searching for ${amounts.length} transactions...`)
    let foundCount = 0
    const count = await authenticatedJournalPage.getTransactionCount()
    for (let i = 0; i < count; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(memberData.first_name)) {
        foundCount++
      }
    }

    console.log(`Found ${foundCount} transactions for member`)
    expect(foundCount, 'Should find all 3 transactions for member').toBeGreaterThanOrEqual(3)
  })

  /**
   * Test 3: Verify table content structure is correct
   *
   * E2E Verification Flow:
   * 1. Create member and transaction
   * 2. Navigate to journal
   * 3. Verify table has correct columns and data
   */
  test('should display transaction with correct columns', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    const testId = `journal-columns-${Date.now()}`
    const memberData = {
      first_name: `ColumnsTest${testId}`,
      last_name: 'Verify',
      email: `${testId}@example.com`,
      iban: 'DE89370400440532013002',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    // Create member
    const createMemberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })
    const memberId = (await createMemberResponse.json()).id

    // Create transaction with specific amount
    const txResponse = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correct`,
      {
        data: {
          amount_cents: 12345, // €123.45
          reason: 'Column verification transaction',
        },
      }
    )
    expect(txResponse.ok()).toBeTruthy()

    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Find transaction
    const memberIndex = await authenticatedJournalPage.findTransactionByMemberName(memberData.first_name)
    expect(memberIndex, 'Should find transaction').not.toBeNull()

    if (memberIndex !== null) {
      const row = await authenticatedJournalPage.getTransactionRow(memberIndex)

      // Verify all columns have data
      expect(row.date, 'Date column should have data').toBeTruthy()
      expect(row.type, 'Type column should have data').toBeTruthy()
      expect(row.member, 'Member column should have member name').toContain(memberData.first_name)
      expect(row.amount, 'Amount column should show 123.45').toContain('123.45')
      expect(row.description, 'Description should be present').toBeTruthy()

      // Verify type is correction
      expect(row.type.toLowerCase(), 'Type should be correction').toBe('correction')
    }
  })

  /**
   * Test 4: Verify pagination - ensure transactions count is displayed
   *
   * E2E Verification Flow:
   * 1. Create member and transaction
   * 2. Navigate to journal
   * 3. Verify count summary shows correct number
   */
  test('should display transaction count summary', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    const testId = `journal-count-${Date.now()}`
    const memberData = {
      first_name: `CountTest${testId}`,
      last_name: 'Summary',
      email: `${testId}@example.com`,
      iban: 'DE89370400440532013003',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    // Create member and transaction
    const createMemberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })
    const memberId = (await createMemberResponse.json()).id

    const txResponse = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correct`,
      {
        data: {
          amount_cents: 1000,
          reason: 'Count test',
        },
      }
    )
    expect(txResponse.ok()).toBeTruthy()

    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Get count summary text
    const summaryText = await authenticatedJournalPage.getCountSummaryText()
    console.log('Count summary:', summaryText)

    // Should show "X Transactions gefunden"
    expect(summaryText, 'Summary should contain "Transactions"').toContain('Transactions')

    const totalCount = await authenticatedJournalPage.getTotalItemsFromSummary()
    expect(totalCount, 'Should have at least 1 transaction').toBeGreaterThanOrEqual(1)
  })
})
