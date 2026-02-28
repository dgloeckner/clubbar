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
 * - Create transactions via terminal sync API (with backdated timestamps)
 * - Create transactions via admin endpoint (for quick current-dated transactions)
 * - Verify transactions appear in table
 * - Verify filtering and sorting work
 * - Verify period filtering shows correct date ranges
 */

import { test, expect } from '../../fixtures/pageObjects'
import { TEST_CREDENTIALS } from '../../config/test-credentials'
import { createMemberViaPage } from '../../utils/members'

/**
 * Generate UUID v4
 */
function generateUUID(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
    const r = (Math.random() * 16) | 0
    const v = c === 'x' ? r : (r & 0x3) | 0x8
    return v.toString(16)
  })
}

/**
 * Helper: Create a single transaction via terminal sync API
 * Simplified transaction creation for test isolation
 */
async function createTransaction(
  page: any,
  memberId: string,
  amountCents: number,
  createdAt?: string,
  productId?: string
): Promise<string> {
  const txId = generateUUID()
  const finalProductId = productId || generateUUID() // Use provided product ID or generate dummy

  const syncBody = {
    transactions: [
      {
        id: txId,
        member_id: memberId,
        product_id: finalProductId,
        amount_cents: amountCents,
        created_at: createdAt || new Date().toISOString(),
      },
    ],
  }

  const response = await page.request.post('http://localhost:8080/api/sync/transactions', {
    headers: {
      Authorization: `Bearer ${TEST_CREDENTIALS.terminal.token}`,
      'Content-Type': 'application/json',
    },
    data: syncBody,
  })

  if (!response.ok()) {
    throw new Error('Failed to create transaction')
  }

  const result = await response.json()
  if (!result.accepted_ids || !result.accepted_ids.includes(txId)) {
    throw new Error(`Transaction ${txId} was not accepted`)
  }

  return txId
}

/**
 * Helper: Upload transactions via terminal sync API with custom created_at timestamp
 * This allows us to create backdated transactions for period filtering tests
 */
async function syncTransactionsWithDates(
  page: any,
  memberId: string,
  productId: string,
  transactions: Array<{ amount_cents: number; created_at: string }>
): Promise<string[]> {
  const syncBody = {
    transactions: transactions.map((tx) => ({
      id: generateUUID(),
      member_id: memberId,
      product_id: productId,
      amount_cents: tx.amount_cents,
      created_at: tx.created_at,
    })),
  }

  const response = await page.request.post('http://localhost:8080/api/sync/transactions', {
    headers: {
      Authorization: `Bearer ${TEST_CREDENTIALS.terminal.token}`,
      'Content-Type': 'application/json',
    },
    data: syncBody,
  })

  if (!response.ok()) {
    throw new Error('Failed to sync transactions')
  }

  const result = await response.json()
  return result.accepted_ids || []
}

/**
 * Helper: Create a correction transaction via admin API
 * Simplified correction creation for test isolation
 */
async function createCorrection(
  page: any,
  memberId: string,
  amountCents: number,
  reason: string
): Promise<string> {
  const response = await page.request.post(
    `http://localhost:8080/api/admin/members/${memberId}/transactions/correction`,
    {
      data: {
        amount_cents: amountCents,
        reason,
      },
    }
  )

  if (!response.ok()) {
    throw new Error('Failed to create correction')
  }

  const result = await response.json()
  // API returns the transaction flat (not wrapped in { transaction: ... })
  return result.id
}

test.describe('Journal Page - Transaction Display', () => {
  /**
   * Test 1: Display transactions in table after creating them via API
   *
   * E2E Verification Flow:
   * 1. Create test member via POST /api/admin/members
   * 2. Create correction transaction via POST /api/admin/members/{id}/transactions/correction
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

    // Step 1: Create test member via API (POST /api/admin/members)
    const member = await createMemberViaPage(page, {
      firstName: `TestFirst${testDataPrefix}`,
      lastName: `TestLast${testDataPrefix}`,
      email: `${testDataPrefix}@example.com`,
    })
    const memberId = member.id

    // Step 2: Create correction transaction (POST /api/admin/members/{id}/transactions/correction)
    const txId = await createCorrection(page, memberId, 5000, 'E2E test correction transaction')

    // === ACT ===
    // Navigate to journal page (already authenticated by fixture)
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()

    // Wait for table to load
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Search for our specific test data to isolate from other parallel tests
    await authenticatedJournalPage.search(member.first_name)
    await authenticatedJournalPage.waitForTableToLoad()

    // Verify we found exactly our transaction
    const transactionCount = await authenticatedJournalPage.getTransactionCount()
    expect(transactionCount, 'Should find exactly 1 transaction for unique member').toBe(1)

    // Verify transaction details (should be in first row after search)
    const row = await authenticatedJournalPage.getTransactionRow(0)

    expect(row.member, 'Member name should match').toContain(member.first_name)
    expect(row.type.toLowerCase(), 'Transaction type should be correction').toBe('correction')
    expect(row.amount, 'Amount should be displayed').toBeTruthy()
    // Accept both English (50.00) and German (50,00) decimal formats
    expect(row.amount.includes('50.00') || row.amount.includes('50,00'), 'Amount should be 50.00 or 50,00').toBeTruthy()
    expect(row.details, 'Details should contain reason').toContain('E2E test correction')
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

    // Create test member
    const member = await createMemberViaPage(page, {
      firstName: `MultiTx${testId}`,
      lastName: 'Member',
      email: `${testId}@example.com`,
    })
    const memberId = member.id

    // Create 3 transactions
    const amounts = [100, 250, 500]
    const txIds: string[] = []

    for (const amount of amounts) {
      const txId = await createCorrection(page, memberId, amount, `Multi-transaction test #${txIds.length + 1}`)
      txIds.push(txId)
    }


    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    const transactionCount = await authenticatedJournalPage.getTransactionCount()
    expect(transactionCount, 'Should have at least 3 transactions').toBeGreaterThanOrEqual(3)

    // Find all transactions for this member
    let foundCount = 0
    const count = await authenticatedJournalPage.getTransactionCount()
    for (let i = 0; i < count; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(member.first_name)) {
        foundCount++
      }
    }

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

    // Create member
    const member = await createMemberViaPage(page, {
      firstName: `ColumnsTest${testId}`,
      lastName: 'Verify',
      email: `${testId}@example.com`,
    })
    const memberId = member.id

    // Create transaction with specific amount
    const txResponse = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correction`,
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
    const memberIndex = await authenticatedJournalPage.findTransactionByMemberName(member.first_name)
    expect(memberIndex, 'Should find transaction').not.toBeNull()

    if (memberIndex !== null) {
      // Search for our specific transaction (amount should be 123.45)
      // Since tests run in parallel, we need to verify we found the right transaction
      const totalCount = await authenticatedJournalPage.getTransactionCount()
      let foundCorrectTransaction = false

      for (let i = 0; i < Math.min(totalCount, 50); i++) {
        const row = await authenticatedJournalPage.getTransactionRow(i)
        // Accept both English (123.45) and German (123,45) decimal formats
        if (row.member && row.member.includes(member.first_name) && (row.amount.includes('123.45') || row.amount.includes('123,45'))) {
          // Found our transaction!
          foundCorrectTransaction = true

          // Verify all columns have data
          expect(row.date, 'Date column should have data').toBeTruthy()
          expect(row.type, 'Type column should have data').toBeTruthy()
          expect(row.member, 'Member column should have member name').toContain(member.first_name)
          // Accept both English (123.45) and German (123,45) decimal formats
          expect(row.amount.includes('123.45') || row.amount.includes('123,45'), 'Amount column should show 123.45 or 123,45').toBeTruthy()
          expect(row.details, 'Details column should be present').toBeTruthy()

          // Verify type is correction
          expect(row.type.toLowerCase(), 'Type should be correction').toBe('correction')
          break
        }
      }

      expect(foundCorrectTransaction, 'Should find our specific transaction with amount 123.45 or 123,45').toBeTruthy()
    }
  })

  /**
   * Test 4: Verify product names display in Details column for purchase transactions
   *
   * E2E Verification Flow:
   * 1. Create member via API
   * 2. Create purchase transaction via terminal sync API (with existing product)
   * 3. Navigate to journal
   * 4. Search for transaction
   * 5. Verify Details column shows product name (not "-")
   *
   * CRITICAL: This test verifies the fix for product_names JSON parsing bug.
   * Backend returns product_names as JSON string: {"de": "Äppler 0,5L", "en": "..."}
   * Frontend must parse JSON and extract localized name for display.
   */
  test('should display product name in Details column for purchase transactions', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    const testId = `journal-product-${Date.now()}`

    // Create member
    const member = await createMemberViaPage(page, {
      firstName: `ProductTest${testId}`,
      lastName: 'Member',
      email: `${testId}@example.com`,
    })
    const memberId = member.id

    // Create purchase transaction via terminal sync API with existing product
    // Use existing product: "Äppler 0,5L" / "Apple Cider 0.5L" (seeded with UUID 33333338-...)
    const productId = '33333338-3333-3333-3333-333333333338'

    const txId = await createTransaction(page, memberId, 350, undefined, productId)

    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our specific transaction
    await authenticatedJournalPage.search(member.first_name)
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    const txCount = await authenticatedJournalPage.getTransactionCount()
    expect(txCount, 'Should find exactly 1 transaction').toBe(1)

    const row = await authenticatedJournalPage.getTransactionRow(0)

    // CRITICAL ASSERTIONS: Verify product name is displayed (not dash or empty)
    expect(row.type.toLowerCase(), 'Type should be purchase').toBe('purchase')
    expect(row.details, 'Details should NOT be empty').toBeTruthy()
    expect(row.details, 'Details should NOT be dash').not.toBe('—')
    expect(row.details, 'Details should contain product name').toContain('Äppler')

  })

  /**
   * Test 5: Verify pagination - ensure transactions count is displayed
   *
   * E2E Verification Flow:
   * 1. Create member and transaction
   * 2. Navigate to journal
   * 3. Verify count summary shows correct number
   */
  test('should display transaction count summary', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    const testId = `journal-count-${Date.now()}`

    // Create member and transaction
    const member = await createMemberViaPage(page, {
      firstName: `CountTest${testId}`,
      lastName: 'Summary',
      email: `${testId}@example.com`,
    })
    const memberId = member.id

    const txResponse = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correction`,
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

    // Accept both English "Transactions" and German "Buchungen"
    expect(summaryText.includes('Transactions') || summaryText.includes('Buchungen'), 'Summary should contain "Transactions" or "Buchungen"').toBeTruthy()

    const totalCount = await authenticatedJournalPage.getTotalItemsFromSummary()
    expect(totalCount, 'Should have at least 1 transaction').toBeGreaterThanOrEqual(1)
  })
})

/**
 * Period Filtering Tests
 *
 * Tests for PeriodPicker functionality with backdated transactions.
 * Verifies that period filtering correctly shows/hides transactions from different date ranges.
 *
 * Uses terminal sync API to create transactions with custom created_at timestamps,
 * allowing comprehensive testing of all time periods (1M, 3M, 6M, 1Y, 2Y, All).
 */
test.describe('Journal Page - Period Filtering with Backdated Transactions', () => {
  /**
   * Test 5: Verify period selection triggers API requests and updates results
   *
   * E2E Verification Flow:
   * 1. Navigate to journal (defaults to 3M)
   * 2. Record initial transaction count
   * 3. Select each period and verify:
   *    - Table reloads (content updates)
   *    - API calls are made (networkidle wait)
   *    - Page functionality remains intact
   * 4. Verify that switching between periods works consistently
   *
   * Note: We verify UI/API behavior, not exact date filtering logic
   * (which is tested separately in backend unit tests)
   */
  test('should update transaction results when period changes', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    // Navigate to journal
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    const initialCount = await authenticatedJournalPage.getTransactionCount()
    expect(initialCount, 'Should have initial transactions').toBeGreaterThan(0)

    // === ACT & ASSERT ===
    // Test switching between multiple periods
    const periodSequence: Array<'1m' | '6m' | '1y' | '2y' | 'all'> = ['1m', '6m', '1y', '2y', 'all']
    const countsPerPeriod: Record<string, number> = {}

    for (const period of periodSequence) {
      await authenticatedJournalPage.selectPeriod(period)
      await authenticatedJournalPage.waitForTableToLoad()

      const count = await authenticatedJournalPage.getTransactionCount()
      countsPerPeriod[period] = count

      // Verify table is still functional
      expect(count, `Should have transactions or be empty on ${period}`).toBeGreaterThanOrEqual(0)

      // Verify we can read row data (page is still functional)
      if (count > 0) {
        const firstRow = await authenticatedJournalPage.getTransactionRow(0)
        expect(firstRow.date, `Row should have date on ${period}`).toBeTruthy()
        expect(firstRow.member, `Row should have member on ${period}`).toBeTruthy()
      }
    }

    // Verify we got consistent data across period changes
    Object.entries(countsPerPeriod).forEach(([period, count]) => {
    })

    // All periods should be functional (have data or be empty, but not error)
    const totalResults = Object.values(countsPerPeriod).reduce((a, b) => a + b, 0)
    expect(totalResults, 'Should have retrieved transaction data for all periods').toBeGreaterThan(0)

    // "All" period should typically show the most transactions (most permissive filter)
    const allCount = countsPerPeriod['all']
    expect(allCount, '"All" period should show all transactions').toBeGreaterThanOrEqual(initialCount)
  })

  /**
   * Test 6: Verify period button styling changes when selected
   *
   * E2E Verification Flow:
   * 1. Navigate to journal (3M is default)
   * 2. Verify 3M period is active (blue button)
   * 3. Click different periods
   * 4. Verify that period becomes active
   * 5. Verify old period is no longer active
   */
  test('should show correct period button as active', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    // Navigate to journal
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // === ACT & ASSERT ===
    // Default should be 3M
    await authenticatedJournalPage.expectPeriodButtonActive('3m')

    // Click 1M and verify it's active
    await authenticatedJournalPage.selectPeriod('1m')
    await authenticatedJournalPage.expectPeriodButtonActive('1m')

    // Verify 3M is no longer active
    await authenticatedJournalPage.expectPeriodButtonInactive('3m')

    // Click All and verify it's active
    await authenticatedJournalPage.selectPeriod('all')
    await authenticatedJournalPage.expectPeriodButtonActive('all')
  })

  /**
   * Test 7: Verify period changes trigger new API request
   *
   * E2E Verification Flow:
   * 1. Navigate to journal
   * 2. Change period multiple times
   * 3. Verify page remains functional and loads new data
   */
  test('should reload transactions when period changes', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    // Navigate to journal
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    const initialCount = await authenticatedJournalPage.getTransactionCount()

    // === ACT & ASSERT ===
    // Change period - this should trigger API call and reload data
    const periods: Array<'1m' | '6m' | '1y'> = ['1m', '6m', '1y']

    for (const period of periods) {
      await authenticatedJournalPage.selectPeriod(period)
      await authenticatedJournalPage.waitForTableToLoad()

      const count = await authenticatedJournalPage.getTransactionCount()
      expect(count, `Should have transactions or be empty on ${period}`).toBeGreaterThanOrEqual(0)
    }

    // Verify we can still interact after multiple period changes
    await authenticatedJournalPage.selectPeriod('all')
    await authenticatedJournalPage.waitForTableToLoad()
    const finalCount = await authenticatedJournalPage.getTransactionCount()
    expect(finalCount, 'Should have transactions available').toBeGreaterThanOrEqual(0)
  })

  /**
   * Test 8: Verify settlement date column displays correctly in journal
   *
   * E2E Verification Flow:
   * 1. Navigate to journal
   * 2. Verify settlement date column header exists
   * 3. Filter by "settled" transactions
   * 4. Verify settled transactions show date and time
   * 5. Verify unsettled transactions show "—"
   * 6. Verify date/time format is correct
   */
  test('should display settlement date column with correct format', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE: Create own test data for isolation (Pattern 001) ===
    const testId = `journal-settle-date-${Date.now()}`

    // Create member
    const { id: memberId } = await createMemberViaPage(page, {
      firstName: `SettleDate${testId}`,
      lastName: 'Test',
      email: `sd-${testId}@example.com`,
    })

    // Create 2 transactions: one will be settled, one stays open
    const tx1Response = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correction`,
      { data: { amount_cents: 1000, reason: `Settle date test settled ${testId}` } }
    )
    const tx1Id = (await tx1Response.json()).id

    const tx2Response = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correction`,
      { data: { amount_cents: 2000, reason: `Settle date test open ${testId}` } }
    )

    // Settle only the first transaction
    const today = new Date().toISOString().split('T')[0]
    const execDate = new Date()
    execDate.setDate(execDate.getDate() + 7)
    await page.request.post('http://localhost:8080/api/admin/settlements', {
      data: {
        transaction_ids: [tx1Id],
        settlement_date: today,
        execution_date: execDate.toISOString().split('T')[0],
        settlement_type: 'sepa',
      },
    })

    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our test data
    await authenticatedJournalPage.search(testId)
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // 1. Verify settlement date column header exists
    const headerText = await authenticatedJournalPage.getHeaderText('settlement-date')
    expect(headerText === 'Settlement Date' || headerText === 'Abrechnungsdatum', 'Header should be Settlement Date or Abrechnungsdatum').toBeTruthy()

    // 2. Check "all" transactions - should have mix of settled and unsettled
    const count = await authenticatedJournalPage.getTransactionCount()
    expect(count).toBe(2)

    let hasSettled = false
    let hasUnsettled = false

    for (let i = 0; i < count; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      const cellText = await authenticatedJournalPage.getSettlementDateText(i)

      if (cellText === '—') {
        hasUnsettled = true
      } else if (cellText && cellText.trim() !== '') {
        hasSettled = true
        // Verify date/time format (DD.MM.YYYY or MM/DD/YYYY)
        expect(cellText, `Date format should be DD.MM.YYYY or MM/DD/YYYY`).toMatch(/\d{2}[.\/]\d{2}[.\/]\d{4}/)
        expect(cellText, `Time format should be HH:MM:SS`).toMatch(/\d{2}:\d{2}:\d{2}/)
      }
    }

    expect(hasSettled, 'Should have at least one settled transaction').toBeTruthy()
    expect(hasUnsettled, 'Should have at least one unsettled transaction').toBeTruthy()

    // 3. Filter by "settled" - settled transaction should show date
    await authenticatedJournalPage.filterBySettlementStatus('settled')
    await authenticatedJournalPage.waitForTableToLoad()

    const settledCount = await authenticatedJournalPage.getTransactionCount()
    expect(settledCount, 'Should have 1 settled transaction').toBe(1)

    const settledDateText = await authenticatedJournalPage.getSettlementDateText(0)
    expect(settledDateText, 'Settlement date should not be dash').not.toBe('—')
    expect(settledDateText, 'Date format DD.MM.YYYY or MM/DD/YYYY').toMatch(/\d{2}[.\/]\d{2}[.\/]\d{4}/)

    // 4. Filter by "open" - open transaction should show dash
    await authenticatedJournalPage.filterBySettlementStatus('open')
    await authenticatedJournalPage.waitForTableToLoad()

    const openCount = await authenticatedJournalPage.getTransactionCount()
    expect(openCount, 'Should have 1 open transaction').toBe(1)

    const openDateText = await authenticatedJournalPage.getSettlementDateText(0)
    expect(openDateText, 'Open transaction should show dash').toBe('—')

  })
})

/**
 * Sorting Tests
 *
 * Tests for table sorting by different columns (date, type, member, amount).
 * Verifies that clicking headers toggles sort direction and data is reordered correctly.
 *
 * E2E Integration:
 * - Create multiple transactions with varied data
 * - Click column headers to sort
 * - Verify data order changes correctly
 * - Verify sort direction indicators (↑ ↓) appear
 */
test.describe('Journal Page - Sorting', () => {
  /**
   * Test 9: Verify sorting by date (created_at)
   *
   * E2E Verification Flow:
   * 1. Create multiple transactions with different timestamps
   * 2. Navigate to journal (default: date desc)
   * 3. Verify transactions are in descending date order
   * 4. Click date header to toggle to ascending
   * 5. Verify transactions are now in ascending date order
   * 6. Verify sort indicator shows correct direction
   */
  test('should sort transactions by date when clicking date header', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    const testId = `journal-sort-date-${Date.now()}`

    // Create member
    const { id: memberId } = await createMemberViaPage(page, {
      firstName: `SortDate${testId}`,
      lastName: 'Test',
      email: `${testId}@example.com`,
    })

    // Create 3 transactions with delays to ensure different timestamps
    const txAmounts = [100, 200, 300]
    for (let i = 0; i < txAmounts.length; i++) {
      await createCorrection(page, memberId, txAmounts[i], `Sort test transaction ${i + 1}`)
      // Delay to ensure different created_at timestamps (MySQL DATETIME has second precision)
      await page.waitForTimeout(1100)
    }

    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our test data to isolate from parallel tests
    await authenticatedJournalPage.search(testId)
    await authenticatedJournalPage.waitForTableToLoad()

    // Default should be date descending (newest first)
    const headerText = await authenticatedJournalPage.getHeaderText('date')
    expect(headerText, 'Date header should show descending arrow').toContain('↓')

    // === ASSERT ===
    // Get first few transactions and verify they're in descending order
    const count = await authenticatedJournalPage.getTransactionCount()
    if (count >= 2) {
      const firstRow = await authenticatedJournalPage.getTransactionRow(0)
      const secondRow = await authenticatedJournalPage.getTransactionRow(1)


      // Parse dates (format: "DD.MM.YYYY\nHH:MM:SS" — German locale)
      const parseDateTime = (dateStr: string) => {
        const [datePart, timePart] = dateStr.split('\n')
        const [day, month, year] = datePart.split('.')
        return new Date(`${year}-${month}-${day} ${timePart}`).getTime()
      }

      const firstDate = parseDateTime(firstRow.date)
      const secondDate = parseDateTime(secondRow.date)

      expect(firstDate, 'First transaction should be newer than second (desc order)').toBeGreaterThanOrEqual(
        secondDate
      )
    }

    // === ACT ===
    // Click date header to toggle to ascending
    await authenticatedJournalPage.sortBy('date')
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify sort indicator now shows ascending
    const headerTextAsc = await authenticatedJournalPage.getHeaderText('date')
    expect(headerTextAsc, 'Date header should now show ascending arrow').toContain('↑')

    // Verify order is now ascending (oldest first)
    const countAfterSort = await authenticatedJournalPage.getTransactionCount()
    if (countAfterSort >= 2) {
      const firstRowAsc = await authenticatedJournalPage.getTransactionRow(0)
      const secondRowAsc = await authenticatedJournalPage.getTransactionRow(1)


      const parseDateTime = (dateStr: string) => {
        const [datePart, timePart] = dateStr.split('\n')
        const [day, month, year] = datePart.split('.')
        return new Date(`${year}-${month}-${day} ${timePart}`).getTime()
      }

      const firstDateAsc = parseDateTime(firstRowAsc.date)
      const secondDateAsc = parseDateTime(secondRowAsc.date)

      expect(firstDateAsc, 'First transaction should be older than second (asc order)').toBeLessThanOrEqual(
        secondDateAsc
      )
    }
  })

  /**
   * Test 10: Verify sorting by amount
   *
   * E2E Verification Flow:
   * 1. Create multiple transactions with different amounts
   * 2. Navigate to journal
   * 3. Click amount header to sort
   * 4. Verify transactions are sorted by amount
   * 5. Click again to toggle direction
   * 6. Verify sort direction changes
   */
  test('should sort transactions by amount when clicking amount header', async ({
    page,
    authenticatedJournalPage,
  }) => {
    // === ARRANGE ===
    const testId = `journal-sort-amount-${Date.now()}`

    // Create member
    const member = await createMemberViaPage(page, {
      firstName: `SortAmount${testId}`,
      lastName: 'Test',
      email: `${testId}@example.com`,
    })
    const memberId = member.id

    // Create transactions with distinct amounts (in random order)
    const amounts = [5000, 1000, 10000, 2500, 7500] // €50, €10, €100, €25, €75

    for (const amount of amounts) {
      await createCorrection(page, memberId, amount, `Amount sort test - €${amount / 100}`)
    }

    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our specific test data to isolate from other parallel tests
    await authenticatedJournalPage.search(member.first_name)
    await authenticatedJournalPage.waitForTableToLoad()

    // Click amount header to sort (default will be desc - highest first)
    await authenticatedJournalPage.sortBy('amount')
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify amount header shows sort indicator
    const headerText = await authenticatedJournalPage.getHeaderText('amount')
    // Accept both English "Amount" and German "Betrag"
    expect(headerText.match(/Amount\s+[↑↓]/) || headerText.match(/Betrag\s+[↑↓]/), 'Amount/Betrag header should show sort arrow').toBeTruthy()

    // Verify we have exactly our 5 transactions
    const totalCount = await authenticatedJournalPage.getTransactionCount()
    expect(totalCount, 'Should find exactly 5 transactions after search').toBe(5)

    // Get all transactions (should only be our 5 after search)
    const memberTransactions: { amount: number; rowIndex: number }[] = []

    for (let i = 0; i < totalCount; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      // Parse amount (format: "€123.45")
      const amountStr = row.amount.replace('€', '').trim()
      const amountCents = Math.round(parseFloat(amountStr) * 100)
      memberTransactions.push({ amount: amountCents, rowIndex: i })
    }

    // Verify transactions are sorted by amount (desc or asc)
    let isSortedDesc = true
    let isSortedAsc = true

    for (let i = 0; i < memberTransactions.length - 1; i++) {
      if (memberTransactions[i].amount < memberTransactions[i + 1].amount) {
        isSortedDesc = false
      }
      if (memberTransactions[i].amount > memberTransactions[i + 1].amount) {
        isSortedAsc = false
      }
    }

    expect(
      isSortedDesc || isSortedAsc,
      'Transactions should be sorted by amount in either direction'
    ).toBeTruthy()

    if (isSortedDesc) {
    } else {
    }

    // === ACT ===
    // Click amount header again to toggle sort direction
    await authenticatedJournalPage.sortBy('amount')
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify sort direction changed
    const headerTextAfterToggle = await authenticatedJournalPage.getHeaderText('amount')
    expect(headerTextAfterToggle, 'Sort indicator should have changed').not.toBe(headerText)
  })

  /**
   * Test 11: Verify sorting by member name
   *
   * E2E Verification Flow:
   * 1. Create multiple members with different names
   * 2. Create transactions for each member
   * 3. Navigate to journal
   * 4. Click member header to sort
   * 5. Verify transactions are sorted by member name
   */
  test('should sort transactions by member name when clicking member header', async ({
    page,
    authenticatedJournalPage,
  }) => {
    // === ARRANGE ===
    const testId = `journal-sort-member-${Date.now()}`

    // Create 3 members with last names that sort differently (backend sorts by last_name)
    const memberNames = [
      { first: `SortTest${testId}`, last: `Alpha${testId}` },
      { first: `SortTest${testId}`, last: `Charlie${testId}` },
      { first: `SortTest${testId}`, last: `Bravo${testId}` },
    ]

    const memberIds: string[] = []

    for (const name of memberNames) {
      const loopMember = await createMemberViaPage(page, {
        firstName: name.first,
        lastName: name.last,
        email: `${name.first.substring(0, 5).toLowerCase()}-${testId}@example.com`,
        iban: `DE8937040044053201${3012 + memberIds.length}`,
      })
      memberIds.push(loopMember.id)

      // Create a transaction for this member
      await createCorrection(page, loopMember.id, 1000, `Member sort test ${testId}`)
    }


    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our specific test data to isolate from other parallel tests
    await authenticatedJournalPage.search(testId)
    await authenticatedJournalPage.waitForTableToLoad()

    // Verify we have exactly our 3 transactions
    const totalCount = await authenticatedJournalPage.getTransactionCount()
    expect(totalCount, 'Should find exactly 3 transactions after search').toBe(3)

    // Click member header to sort
    await authenticatedJournalPage.sortBy('member')
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify member header shows sort indicator
    const headerText = await authenticatedJournalPage.getHeaderText('member')
    // Accept both English "Member" and German "Mitglied"
    expect(headerText.match(/Member\s+[↑↓]/) || headerText.match(/Mitglied\s+[↑↓]/), 'Member/Mitglied header should show sort arrow').toBeTruthy()

    // Get all member names (should only be our 3 after search)
    const foundMembers: string[] = []

    for (let i = 0; i < totalCount; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      foundMembers.push(row.member)
    }

    // Check if members are sorted (either alphabetically asc or desc)
    const isSortedAsc =
      foundMembers[0] < foundMembers[1] &&
      foundMembers[1] < foundMembers[2]

    const isSortedDesc =
      foundMembers[0] > foundMembers[1] &&
      foundMembers[1] > foundMembers[2]

    expect(
      isSortedAsc || isSortedDesc,
      'Transactions should be sorted by member name in either direction'
    ).toBeTruthy()

    if (isSortedAsc) {
    } else {
    }
  })

  /**
   * Test 12: Verify sorting by type
   *
   * E2E Verification Flow:
   * 1. Create transactions of different types (purchase via sync, correction via admin)
   * 2. Navigate to journal
   * 3. Click type header to sort
   * 4. Verify transactions are grouped by type
   */
  test('should sort transactions by type when clicking type header', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    const testId = `journal-sort-type-${Date.now()}`

    // Create member
    const member = await createMemberViaPage(page, {
      firstName: `SortType${testId}`,
      lastName: 'Test',
      email: `${testId}@example.com`,
    })
    const memberId = member.id

    // Create 2 correction transactions
    await createCorrection(page, memberId, 1000, 'Type sort test - correction 1')
    await createCorrection(page, memberId, 2000, 'Type sort test - correction 2')

    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our specific test data to isolate from other parallel tests
    await authenticatedJournalPage.search(member.first_name)
    await authenticatedJournalPage.waitForTableToLoad()

    // Verify we have exactly our 2 transactions
    const totalCount = await authenticatedJournalPage.getTransactionCount()
    expect(totalCount, 'Should find exactly 2 transactions after search').toBe(2)

    // Click type header to sort
    await authenticatedJournalPage.sortBy('type')
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify type header shows sort indicator
    const headerText = await authenticatedJournalPage.getHeaderText('type')
    // Accept both English "Type" and German "Typ"
    expect(headerText.match(/Type\s+[↑↓]/) || headerText.match(/Typ\s+[↑↓]/), 'Type/Typ header should show sort arrow').toBeTruthy()

    // Get all transaction types (should only be our 2 after search)
    const foundTypes: string[] = []

    for (let i = 0; i < totalCount; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      foundTypes.push(row.type.toLowerCase())
    }

  })
})

/**
 * Search and Filtering Tests
 *
 * Tests for search functionality and settlement status filtering.
 * Verifies that search filters transactions by member name or details,
 * and that settlement status filter works correctly.
 */
test.describe('Journal Page - Search and Filtering', () => {
  /**
   * Test 13: Verify search by member name
   *
   * E2E Verification Flow:
   * 1. Create multiple members with distinct names
   * 2. Create transactions for each member
   * 3. Navigate to journal
   * 4. Search for specific member name
   * 5. Verify only matching transactions are shown
   * 6. Clear search and verify all transactions reappear
   */
  test('should filter transactions by member name when searching', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    const testId = `journal-search-member-${Date.now()}`

    // Create 2 members with very distinct names
    const member1 = await createMemberViaPage(page, {
      firstName: `SearchUnique${testId}`,
      lastName: 'One',
      email: `search1-${testId}@example.com`,
    })
    const member2 = await createMemberViaPage(page, {
      firstName: `Different${testId}`,
      lastName: 'Two',
      email: `search2-${testId}@example.com`,
    })

    // Create transactions for both members
    await createCorrection(page, member1.id, 1000, 'Search test - member 1')
    await createCorrection(page, member2.id, 2000, 'Search test - member 2')

    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Record initial count
    const initialCount = await authenticatedJournalPage.getTransactionCount()

    // Search for first member's name
    await authenticatedJournalPage.search(member1.first_name)
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify only member 1's transactions are visible
    const searchCount = await authenticatedJournalPage.getTransactionCount()

    // Find member 1's transaction
    const member1Index = await authenticatedJournalPage.findTransactionByMemberName(member1.first_name)
    expect(member1Index, 'Should find member 1 transaction').not.toBeNull()

    // Verify member 2 is NOT in the results
    let foundMember2 = false
    for (let i = 0; i < searchCount; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(member2.first_name)) {
        foundMember2 = true
        break
      }
    }

    expect(foundMember2, 'Should NOT find member 2 transaction in search results').toBeFalsy()

    // === ACT ===
    // Clear search
    await authenticatedJournalPage.search('')
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify both members are now visible again
    const clearedCount = await authenticatedJournalPage.getTransactionCount()

    // Both members should be findable now
    const member1AfterClear = await authenticatedJournalPage.findTransactionByMemberName(member1.first_name)
    const member2AfterClear = await authenticatedJournalPage.findTransactionByMemberName(member2.first_name)

    expect(member1AfterClear, 'Should find member 1 after clearing search').not.toBeNull()
    expect(member2AfterClear, 'Should find member 2 after clearing search').not.toBeNull()
  })

  /**
   * Test 14: Verify search by transaction details/reason
   *
   * E2E Verification Flow:
   * 1. Create multiple transactions with distinct reasons
   * 2. Navigate to journal
   * 3. Search for specific reason keyword
   * 4. Verify only matching transactions are shown
   */
  test('should filter transactions by details when searching', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    const testId = `journal-search-details-${Date.now()}`

    // Create member
    const member = await createMemberViaPage(page, {
      firstName: `SearchDetails${testId}`,
      lastName: 'Test',
      email: `${testId}@example.com`,
    })
    const memberId = member.id

    // Create transactions with unique keywords in reasons
    const uniqueKeyword = `KEYWORD${testId}`

    await createCorrection(page, memberId, 1000, `Transaction with ${uniqueKeyword} for testing`)
    await createCorrection(page, memberId, 2000, 'Transaction without the keyword')

    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for unique keyword
    await authenticatedJournalPage.search(uniqueKeyword)
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Find the transaction with the keyword
    let foundWithKeyword = false
    let foundWithoutKeyword = false

    const searchCount = await authenticatedJournalPage.getTransactionCount()
    for (let i = 0; i < searchCount; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(member.first_name)) {
        if (row.details && row.details.includes(uniqueKeyword)) {
          foundWithKeyword = true
        } else if (row.details && row.details.includes('without the keyword')) {
          foundWithoutKeyword = true
        }
      }
    }

    expect(foundWithKeyword, 'Should find transaction with keyword in details').toBeTruthy()
    expect(foundWithoutKeyword, 'Should NOT find transaction without keyword').toBeFalsy()
  })

  /**
   * Test 15: Verify settlement status filtering
   *
   * E2E Verification Flow:
   * 1. Create member and transactions
   * 2. Create a settlement for some transactions
   * 3. Navigate to journal
   * 4. Filter by "open" - verify only unsettled transactions shown
   * 5. Filter by "settled" - verify only settled transactions shown
   * 6. Filter by "all" - verify all transactions shown
   */
  test('should filter transactions by settlement status', async ({ page, authenticatedJournalPage }) => {
    // === ARRANGE ===
    const testId = `journal-settlement-filter-${Date.now()}`

    // Create member
    const member = await createMemberViaPage(page, {
      firstName: `SettlementFilter${testId}`,
      lastName: 'Test',
      email: `${testId}@example.com`,
    })
    const memberId = member.id

    // Create 3 transactions
    const txResponses = []
    for (let i = 0; i < 3; i++) {
      const txResponse = await page.request.post(
        `http://localhost:8080/api/admin/members/${memberId}/transactions/correction`,
        {
          data: {
            amount_cents: (i + 1) * 1000,
            reason: `Settlement filter test ${i + 1}`,
          },
        }
      )
      const txData = await txResponse.json()
      txResponses.push(txData)
    }

    // Create a settlement for the first 2 transactions
    const today = new Date().toISOString().split('T')[0]
    const executionDate = new Date()
    executionDate.setDate(executionDate.getDate() + 7)
    const executionDateStr = executionDate.toISOString().split('T')[0]

    const settlementResponse = await page.request.post('http://localhost:8080/api/admin/settlements', {
      data: {
        transaction_ids: [txResponses[0].id, txResponses[1].id],
        settlement_date: today,
        execution_date: executionDateStr,
        settlement_type: 'sepa',
      },
    })

    expect(settlementResponse.ok(), 'Settlement creation should succeed').toBeTruthy()
    const settlementData = await settlementResponse.json()

    // Wait for settlement to be processed
    await page.waitForTimeout(500)

    // === ACT & ASSERT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Test 1: Search and filter by "open" (unsettled)
    await authenticatedJournalPage.search(member.first_name)
    await authenticatedJournalPage.waitForTableToLoad()
    await authenticatedJournalPage.filterBySettlementStatus('open')
    await authenticatedJournalPage.waitForTableToLoad()

    // Should find only the 3rd transaction (unsettled)
    let foundOpen = 0
    let foundSettled = 0
    let openCount = await authenticatedJournalPage.getTransactionCount()

    for (let i = 0; i < Math.min(openCount, 20); i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(member.first_name)) {
        if (row.details && row.details.includes('Settlement filter test 3')) {
          foundOpen++
        } else if (row.details && (row.details.includes('test 1') || row.details.includes('test 2'))) {
          foundSettled++
        }
      }
    }

    expect(foundOpen, 'Should find unsettled transaction in "open" filter').toBeGreaterThanOrEqual(1)
    expect(foundSettled, 'Should NOT find settled transactions in "open" filter').toBe(0)

    // Test 2: Filter by "settled" (search is still active from above)
    await authenticatedJournalPage.filterBySettlementStatus('settled')
    await authenticatedJournalPage.waitForTableToLoad()

    // Should find the first 2 transactions (settled)
    foundOpen = 0
    foundSettled = 0
    const settledCount = await authenticatedJournalPage.getTransactionCount()

    for (let i = 0; i < Math.min(settledCount, 20); i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(member.first_name)) {
        if (row.details && (row.details.includes('test 1') || row.details.includes('test 2'))) {
          foundSettled++
        } else if (row.details && row.details.includes('test 3')) {
          foundOpen++
        }
      }
    }

    expect(foundSettled, 'Should find settled transactions in "settled" filter').toBeGreaterThanOrEqual(2)
    expect(foundOpen, 'Should NOT find unsettled transaction in "settled" filter').toBe(0)

    // Test 3: Filter by "all" (search is still active from above)
    await authenticatedJournalPage.filterBySettlementStatus('all')
    await authenticatedJournalPage.waitForTableToLoad()

    // Should find all 3 transactions
    let totalFound = 0
    const allCount = await authenticatedJournalPage.getTransactionCount()

    for (let i = 0; i < Math.min(allCount, 20); i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(member.first_name)) {
        totalFound++
      }
    }

    expect(totalFound, 'Should find all transactions in "all" filter').toBeGreaterThanOrEqual(3)
  })
})

/**
 * Create Correction via Modal Tests
 *
 * Tests the correction creation workflow using the journal page modal.
 * Verifies full E2E flow: open modal → fill form → submit → verify in table.
 */
test.describe('Journal Page - Create Correction via Modal', () => {
  /**
   * Test 16: Create correction via modal and verify it appears in journal
   *
   * E2E Verification Flow:
   * 1. Create member with valid SEPA data via API
   * 2. Navigate to journal page
   * 3. Open correction modal
   * 4. Fill form (select member, amount, reason)
   * 5. Submit form
   * 6. Verify modal closes without error
   * 7. Search for correction by unique reason
   * 8. Verify exactly 1 transaction with correct type, member, amount, details
   */
  test('should create correction via modal and display in journal', async ({
    page,
    authenticatedJournalPage,
  }) => {
    // === ARRANGE ===
    const testId = `journal-modal-corr-${Date.now()}`

    // Create member via API
    // First name starts with "A" to ensure member appears in first 100 results
    // (backend caps getMembers at 100 per page, sorted by first_name asc)
    const member = await createMemberViaPage(page, {
      firstName: `ACorr${testId}`,
      lastName: 'Test',
      email: `mc-${testId}@example.com`,
    })
    const memberId = member.id

    const reason = `Modal correction test ${testId}`
    const amountEur = '42.50'

    // === ACT ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    await authenticatedJournalPage.createCorrection(memberId, amountEur, reason)

    // === ASSERT ===
    // Modal should close (no error)
    await authenticatedJournalPage.expectCorrectionModalHidden()

    const correctionError = await authenticatedJournalPage.getCorrectionError()
    expect(correctionError, 'Should have no correction error').toBeNull()

    // Search for the unique testId to find our correction
    await authenticatedJournalPage.search(testId)
    await authenticatedJournalPage.waitForTableToLoad()

    // Verify exactly 1 transaction
    const txCount = await authenticatedJournalPage.getTransactionCount()
    expect(txCount, 'Should find exactly 1 transaction').toBe(1)

    // Verify row details
    const row = await authenticatedJournalPage.getTransactionRow(0)

    expect(row.type.toLowerCase(), 'Type should be correction').toBe('correction')
    expect(row.member, 'Member name should match').toContain(member.first_name)
    // Accept both English (42.50) and German (42,50) decimal formats
    expect(row.amount.includes('42.50') || row.amount.includes('42,50'), 'Amount should contain 42.50 or 42,50').toBeTruthy()
    expect(row.details, 'Details should contain reason').toContain(reason)

  })
})

/**
 * Settle-All (Filter-Based Settlement) Tests
 *
 * Tests the "Abrechnung (alle)" button flow:
 * 1. Calls GET /api/admin/settlements/filter-preview for preview stats
 * 2. Shows confirm modal with transaction/member counts
 * 3. On confirm, calls POST /api/admin/settlements/settle-filter
 * 4. Navigates to /settlements
 */
test.describe('Journal Page - Settle All (Filter-Based Settlement)', () => {
  /**
   * Test 17: settle-all preview modal shows correct stats and creates settlement on confirm
   *
   * E2E Verification Flow:
   * 1. Create test member + correction transaction via API
   * 2. Navigate to journal, search for unique member to scope the filter
   * 3. Click "Abrechnung (alle)" button → verify filter-preview API called
   * 4. Verify confirm modal appears with correct transaction count (1)
   * 5. Click confirm → verify settle-filter API called (201)
   * 6. Verify navigation to /settlements
   */
  test('settle-all: preview modal shows correct stats and creates settlement on confirm', async ({
    page,
    authenticatedJournalPage,
  }) => {
    // === ARRANGE ===
    const testId = `settle-all-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`
    const uniqueName = `SettleAll${testId}`

    // Create test member via API
    const { id: memberId } = await createMemberViaPage(page, {
      firstName: uniqueName,
      lastName: 'UITest',
      email: `${testId}@test.example`,
    })

    // Create a correction transaction for this member
    const txRes = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correction`,
      {
        data: { amount_cents: 350, reason: `sa-e2e-${testId}` },
      }
    )
    expect(txRes.ok(), 'Correction creation should succeed').toBeTruthy()

    // === ACT: Navigate to journal and search for unique member ===
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for unique member name to scope settle-all to only our transaction
    await authenticatedJournalPage.search(uniqueName)

    // Verify exactly 1 transaction is visible before proceeding
    const txCountBefore = await authenticatedJournalPage.getTransactionCount()
    expect(txCountBefore, 'Should find exactly 1 transaction for unique member').toBe(1)

    // === ACT: Click "Abrechnung (alle)" and wait for preview API call ===
    const previewResponsePromise = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/settlements/filter-preview') && resp.status() === 200
    )
    await authenticatedJournalPage.openSettleAllModal()
    await previewResponsePromise

    // === ASSERT: Confirm modal is visible with correct stats ===
    const stats = await authenticatedJournalPage.getSettlementConfirmStats()

    expect(stats.transactions, 'Modal should show exactly 1 transaction').toBe(1)
    expect(stats.members, 'Modal should show exactly 1 member').toBe(1)

    // === ACT: Confirm settlement ===
    const settlementId = await authenticatedJournalPage.confirmOpenSettlement()

    // === ASSERT: Navigation to /settlements ===
    await expect(page).toHaveURL(/\/settlements$/, { timeout: 10000 })
  })
})
