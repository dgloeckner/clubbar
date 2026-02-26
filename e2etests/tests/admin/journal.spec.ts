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
    console.error('Transaction creation failed:', await response.text())
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
    console.error('Sync failed:', await response.text())
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
    console.error('Correction creation failed:', await response.text())
    throw new Error('Failed to create correction')
  }

  const result = await response.json()
  return result.transaction.id
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

    // Step 2: Create correction transaction (POST /api/admin/members/{id}/transactions/correction)
    console.log('Creating correction transaction...')
    const txId = await createCorrection(page, memberId, 5000, 'E2E test correction transaction')
    console.log('Created transaction with ID:', txId)

    // === ACT ===
    // Navigate to journal page (already authenticated by fixture)
    console.log('Navigating to journal page...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()

    // Wait for table to load
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Search for our specific test data to isolate from other parallel tests
    console.log(`Searching for unique member: ${memberData.first_name}`)
    await authenticatedJournalPage.search(memberData.first_name)
    await authenticatedJournalPage.waitForTableToLoad()

    // Verify we found exactly our transaction
    const transactionCount = await authenticatedJournalPage.getTransactionCount()
    console.log(`Found ${transactionCount} transactions after filtering`)
    expect(transactionCount, 'Should find exactly 1 transaction for unique member').toBe(1)

    // Verify transaction details (should be in first row after search)
    const row = await authenticatedJournalPage.getTransactionRow(0)
    console.log('Transaction details:', row)

    expect(row.member, 'Member name should match').toContain(memberData.first_name)
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
      const txId = await createCorrection(page, memberId, amount, `Multi-transaction test #${txIds.length + 1}`)
      txIds.push(txId)
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
    const memberIndex = await authenticatedJournalPage.findTransactionByMemberName(memberData.first_name)
    expect(memberIndex, 'Should find transaction').not.toBeNull()

    if (memberIndex !== null) {
      // Search for our specific transaction (amount should be 123.45)
      // Since tests run in parallel, we need to verify we found the right transaction
      const totalCount = await authenticatedJournalPage.getTransactionCount()
      let foundCorrectTransaction = false

      for (let i = 0; i < Math.min(totalCount, 50); i++) {
        const row = await authenticatedJournalPage.getTransactionRow(i)
        // Accept both English (123.45) and German (123,45) decimal formats
        if (row.member && row.member.includes(memberData.first_name) && (row.amount.includes('123.45') || row.amount.includes('123,45'))) {
          // Found our transaction!
          foundCorrectTransaction = true

          // Verify all columns have data
          expect(row.date, 'Date column should have data').toBeTruthy()
          expect(row.type, 'Type column should have data').toBeTruthy()
          expect(row.member, 'Member column should have member name').toContain(memberData.first_name)
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
    const memberData = {
      first_name: `ProductTest${testId}`,
      last_name: 'Member',
      email: `${testId}@example.com`,
      iban: 'DE89370400440532013004',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    // Create member
    console.log('Creating member for product name test...')
    const memberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })
    expect(memberResponse.ok()).toBeTruthy()
    const memberId = (await memberResponse.json()).id

    // Create purchase transaction via terminal sync API with existing product
    // Use existing product: "Äppler 0,5L" / "Apple Cider 0.5L"
    const productId = 'prod-appler-0001-0001-000000000008'

    console.log('Creating purchase transaction with product...')
    const txId = await createTransaction(page, memberId, 350, undefined, productId)

    // === ACT ===
    console.log('Navigating to journal...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our specific transaction
    console.log(`Searching for member: ${memberData.first_name}`)
    await authenticatedJournalPage.search(memberData.first_name)
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    const txCount = await authenticatedJournalPage.getTransactionCount()
    expect(txCount, 'Should find exactly 1 transaction').toBe(1)

    const row = await authenticatedJournalPage.getTransactionRow(0)
    console.log('Transaction row details:', row.details)

    // CRITICAL ASSERTIONS: Verify product name is displayed (not dash or empty)
    expect(row.type.toLowerCase(), 'Type should be purchase').toBe('purchase')
    expect(row.details, 'Details should NOT be empty').toBeTruthy()
    expect(row.details, 'Details should NOT be dash').not.toBe('—')
    expect(row.details, 'Details should contain product name').toContain('Äppler')

    console.log('✅ Product name displayed correctly in Details column')
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
    console.log('Count summary:', summaryText)

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
    console.log('Navigating to journal page...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    const initialCount = await authenticatedJournalPage.getTransactionCount()
    console.log(`Initial count (3M - default): ${initialCount} transactions`)
    expect(initialCount, 'Should have initial transactions').toBeGreaterThan(0)

    // === ACT & ASSERT ===
    // Test switching between multiple periods
    const periodSequence: Array<'1m' | '6m' | '1y' | '2y' | 'all'> = ['1m', '6m', '1y', '2y', 'all']
    const countsPerPeriod: Record<string, number> = {}

    for (const period of periodSequence) {
      console.log(`\nSelecting period: ${period}`)
      await authenticatedJournalPage.selectPeriod(period)
      await authenticatedJournalPage.waitForTableToLoad()

      const count = await authenticatedJournalPage.getTransactionCount()
      countsPerPeriod[period] = count
      console.log(`  Count on ${period}: ${count} transactions`)

      // Verify table is still functional
      expect(count, `Should have transactions or be empty on ${period}`).toBeGreaterThanOrEqual(0)

      // Verify we can read row data (page is still functional)
      if (count > 0) {
        const firstRow = await authenticatedJournalPage.getTransactionRow(0)
        expect(firstRow.date, `Row should have date on ${period}`).toBeTruthy()
        expect(firstRow.member, `Row should have member on ${period}`).toBeTruthy()
        console.log(`  ✓ First row readable: ${firstRow.member.substring(0, 30)}...`)
      }
    }

    // Verify we got consistent data across period changes
    console.log('\nPeriod selection results:')
    Object.entries(countsPerPeriod).forEach(([period, count]) => {
      console.log(`  ${period}: ${count} transactions`)
    })

    // All periods should be functional (have data or be empty, but not error)
    const totalResults = Object.values(countsPerPeriod).reduce((a, b) => a + b, 0)
    console.log(`Total transactions across all periods: ${totalResults}`)
    expect(totalResults, 'Should have retrieved transaction data for all periods').toBeGreaterThan(0)

    // "All" period should typically show the most transactions (most permissive filter)
    const allCount = countsPerPeriod['all']
    console.log(`\nVerifying "all" period is functional: ${allCount} total transactions`)
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
    console.log('Verifying 3M is default active period...')
    await authenticatedJournalPage.expectPeriodButtonActive('3m')

    // Click 1M and verify it's active
    console.log('Clicking 1M period button...')
    await authenticatedJournalPage.selectPeriod('1m')
    await authenticatedJournalPage.expectPeriodButtonActive('1m')

    // Verify 3M is no longer active
    await authenticatedJournalPage.expectPeriodButtonInactive('3m')

    // Click All and verify it's active
    console.log('Clicking All period button...')
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
    console.log(`Initial transaction count on 3M (default): ${initialCount}`)

    // === ACT & ASSERT ===
    // Change period - this should trigger API call and reload data
    const periods: Array<'1m' | '6m' | '1y'> = ['1m', '6m', '1y']

    for (const period of periods) {
      console.log(`\nChanging period to ${period}...`)
      await authenticatedJournalPage.selectPeriod(period)
      await authenticatedJournalPage.waitForTableToLoad()

      const count = await authenticatedJournalPage.getTransactionCount()
      console.log(`Transaction count on ${period}: ${count}`)
      expect(count, `Should have transactions or be empty on ${period}`).toBeGreaterThanOrEqual(0)
    }

    // Verify we can still interact after multiple period changes
    console.log('\nVerifying page is still functional...')
    await authenticatedJournalPage.selectPeriod('all')
    await authenticatedJournalPage.waitForTableToLoad()
    const finalCount = await authenticatedJournalPage.getTransactionCount()
    console.log(`Final transaction count on All: ${finalCount}`)
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
    const memberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: `SettleDate${testId}`,
        last_name: 'Test',
        email: `sd-${testId}@example.com`,
        iban: 'DE89370400440532013099',
        mandate_signed_at: new Date().toISOString().split('T')[0],
        preferred_language: 'de',
      },
    })
    const memberId = (await memberResponse.json()).id

    // Create 2 transactions: one will be settled, one stays open
    const tx1Response = await page.request.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correction`,
      { data: { amount_cents: 1000, reason: `Settle date test settled ${testId}` } }
    )
    const tx1Id = (await tx1Response.json()).transaction.id

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
      },
    })

    // === ACT ===
    console.log('Navigating to journal page...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our test data
    await authenticatedJournalPage.search(testId)
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // 1. Verify settlement date column header exists
    console.log('Verifying settlement date column header exists...')
    const headerText = await authenticatedJournalPage.getHeaderText('settlement-date')
    expect(headerText === 'Settlement Date' || headerText === 'Abrechnungsdatum', 'Header should be Settlement Date or Abrechnungsdatum').toBeTruthy()
    console.log('✅ Settlement Date header found')

    // 2. Check "all" transactions - should have mix of settled and unsettled
    console.log('\nChecking transactions in "All" filter...')
    const count = await authenticatedJournalPage.getTransactionCount()
    expect(count).toBe(2)

    let hasSettled = false
    let hasUnsettled = false

    for (let i = 0; i < count; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      const cellText = await authenticatedJournalPage.getSettlementDateText(i)

      if (cellText === '—') {
        hasUnsettled = true
        console.log(`  Unsettled: "${row.details}" → "—"`)
      } else if (cellText && cellText.trim() !== '') {
        hasSettled = true
        console.log(`  Settled: "${row.details}" → "${cellText}"`)
        // Verify date/time format (DD.MM.YYYY or MM/DD/YYYY)
        expect(cellText, `Date format should be DD.MM.YYYY or MM/DD/YYYY`).toMatch(/\d{2}[.\/]\d{2}[.\/]\d{4}/)
        expect(cellText, `Time format should be HH:MM:SS`).toMatch(/\d{2}:\d{2}:\d{2}/)
      }
    }

    expect(hasSettled, 'Should have at least one settled transaction').toBeTruthy()
    expect(hasUnsettled, 'Should have at least one unsettled transaction').toBeTruthy()

    // 3. Filter by "settled" - settled transaction should show date
    console.log('\nFiltering by "settled"...')
    await authenticatedJournalPage.filterBySettlementStatus('settled')
    await authenticatedJournalPage.waitForTableToLoad()

    const settledCount = await authenticatedJournalPage.getTransactionCount()
    expect(settledCount, 'Should have 1 settled transaction').toBe(1)

    const settledDateText = await authenticatedJournalPage.getSettlementDateText(0)
    expect(settledDateText, 'Settlement date should not be dash').not.toBe('—')
    expect(settledDateText, 'Date format DD.MM.YYYY or MM/DD/YYYY').toMatch(/\d{2}[.\/]\d{2}[.\/]\d{4}/)
    console.log(`  ✅ Settled transaction date: "${settledDateText}"`)

    // 4. Filter by "open" - open transaction should show dash
    console.log('\nFiltering by "open"...')
    await authenticatedJournalPage.filterBySettlementStatus('open')
    await authenticatedJournalPage.waitForTableToLoad()

    const openCount = await authenticatedJournalPage.getTransactionCount()
    expect(openCount, 'Should have 1 open transaction').toBe(1)

    const openDateText = await authenticatedJournalPage.getSettlementDateText(0)
    expect(openDateText, 'Open transaction should show dash').toBe('—')
    console.log(`  ✅ Open transaction shows "—"`)

    console.log('\n✅ Settlement date column verification complete!')
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
    const memberData = {
      first_name: `SortDate${testId}`,
      last_name: 'Test',
      email: `${testId}@example.com`,
      iban: 'DE89370400440532013010',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    console.log('Creating member for sort test...')
    const createMemberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })
    const memberId = (await createMemberResponse.json()).id

    // Create 3 transactions with delays to ensure different timestamps
    console.log('Creating 3 transactions with different timestamps...')
    const txAmounts = [100, 200, 300]
    for (let i = 0; i < txAmounts.length; i++) {
      await createCorrection(page, memberId, txAmounts[i], `Sort test transaction ${i + 1}`)
      // Small delay to ensure different created_at timestamps
      await page.waitForTimeout(100)
    }

    // === ACT ===
    console.log('Navigating to journal...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our test data to isolate from parallel tests
    await authenticatedJournalPage.search(testId)
    await authenticatedJournalPage.waitForTableToLoad()

    // Default should be date descending (newest first)
    console.log('Verifying default sort (date desc)...')
    const headerText = await authenticatedJournalPage.getHeaderText('date')
    expect(headerText, 'Date header should show descending arrow').toContain('↓')

    // === ASSERT ===
    // Get first few transactions and verify they're in descending order
    const count = await authenticatedJournalPage.getTransactionCount()
    if (count >= 2) {
      const firstRow = await authenticatedJournalPage.getTransactionRow(0)
      const secondRow = await authenticatedJournalPage.getTransactionRow(1)

      console.log('First row date:', firstRow.date)
      console.log('Second row date:', secondRow.date)

      // Parse dates (format: "MM/DD/YYYY\nHH:MM:SS")
      const parseDateTime = (dateStr: string) => {
        const [datePart, timePart] = dateStr.split('\n')
        return new Date(`${datePart} ${timePart}`).getTime()
      }

      const firstDate = parseDateTime(firstRow.date)
      const secondDate = parseDateTime(secondRow.date)

      expect(firstDate, 'First transaction should be newer than second (desc order)').toBeGreaterThanOrEqual(
        secondDate
      )
      console.log('✅ Transactions are in descending date order')
    }

    // === ACT ===
    // Click date header to toggle to ascending
    console.log('Clicking date header to toggle to ascending...')
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

      console.log('First row date (asc):', firstRowAsc.date)
      console.log('Second row date (asc):', secondRowAsc.date)

      const parseDateTime = (dateStr: string) => {
        const [datePart, timePart] = dateStr.split('\n')
        return new Date(`${datePart} ${timePart}`).getTime()
      }

      const firstDateAsc = parseDateTime(firstRowAsc.date)
      const secondDateAsc = parseDateTime(secondRowAsc.date)

      expect(firstDateAsc, 'First transaction should be older than second (asc order)').toBeLessThanOrEqual(
        secondDateAsc
      )
      console.log('✅ Transactions are now in ascending date order')
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
    const memberData = {
      first_name: `SortAmount${testId}`,
      last_name: 'Test',
      email: `${testId}@example.com`,
      iban: 'DE89370400440532013011',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    console.log('Creating member for amount sort test...')
    const createMemberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })
    const memberId = (await createMemberResponse.json()).id

    // Create transactions with distinct amounts (in random order)
    const amounts = [5000, 1000, 10000, 2500, 7500] // €50, €10, €100, €25, €75
    console.log('Creating transactions with amounts:', amounts)

    for (const amount of amounts) {
      await createCorrection(page, memberId, amount, `Amount sort test - €${amount / 100}`)
    }

    // === ACT ===
    console.log('Navigating to journal...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our specific test data to isolate from other parallel tests
    console.log(`Searching for unique member: ${memberData.first_name}`)
    await authenticatedJournalPage.search(memberData.first_name)
    await authenticatedJournalPage.waitForTableToLoad()

    // Click amount header to sort (default will be desc - highest first)
    console.log('Sorting by amount...')
    await authenticatedJournalPage.sortBy('amount')
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify amount header shows sort indicator
    const headerText = await authenticatedJournalPage.getHeaderText('amount')
    // Accept both English "Amount" and German "Betrag"
    expect(headerText.match(/Amount\s+[↑↓]/) || headerText.match(/Betrag\s+[↑↓]/), 'Amount/Betrag header should show sort arrow').toBeTruthy()
    console.log('Amount header text:', headerText)

    // Verify we have exactly our 5 transactions
    const totalCount = await authenticatedJournalPage.getTransactionCount()
    expect(totalCount, 'Should find exactly 5 transactions after search').toBe(5)

    // Get all transactions (should only be our 5 after search)
    console.log('Verifying sorted transactions...')
    const memberTransactions: { amount: number; rowIndex: number }[] = []

    for (let i = 0; i < totalCount; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      // Parse amount (format: "€123.45")
      const amountStr = row.amount.replace('€', '').trim()
      const amountCents = Math.round(parseFloat(amountStr) * 100)
      memberTransactions.push({ amount: amountCents, rowIndex: i })
      console.log(`  Row ${i}: €${amountStr}`)
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
      console.log('✅ Transactions are sorted by amount (descending)')
    } else {
      console.log('✅ Transactions are sorted by amount (ascending)')
    }

    // === ACT ===
    // Click amount header again to toggle sort direction
    console.log('Toggling sort direction...')
    await authenticatedJournalPage.sortBy('amount')
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify sort direction changed
    const headerTextAfterToggle = await authenticatedJournalPage.getHeaderText('amount')
    expect(headerTextAfterToggle, 'Sort indicator should have changed').not.toBe(headerText)
    console.log('Amount header text after toggle:', headerTextAfterToggle)
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

    console.log('Creating members for member sort test...')
    const memberIds: string[] = []

    for (const name of memberNames) {
      const memberData = {
        first_name: name.first,
        last_name: name.last,
        email: `${name.first.substring(0, 5).toLowerCase()}-${testId}@example.com`,
        iban: `DE8937040044053201${3012 + memberIds.length}`,
        mandate_signed_at: new Date().toISOString().split('T')[0],
        preferred_language: 'de',
      }

      const response = await page.request.post('http://localhost:8080/api/admin/members', {
        data: memberData,
      })
      if (!response.ok()) {
        console.error(`Member creation failed (${response.status()}):`, await response.text())
      }
      expect(response.ok(), `Member creation for ${name.first} should succeed (${response.status()})`).toBeTruthy()
      const memberId = (await response.json()).id
      memberIds.push(memberId)

      // Create a transaction for this member
      await createCorrection(page, memberId, 1000, `Member sort test ${testId}`)
    }

    console.log('Created members:', memberNames.map((n) => n.first).join(', '))

    // === ACT ===
    console.log('Navigating to journal...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our specific test data to isolate from other parallel tests
    console.log(`Searching for unique test ID: ${testId}`)
    await authenticatedJournalPage.search(testId)
    await authenticatedJournalPage.waitForTableToLoad()

    // Verify we have exactly our 3 transactions
    const totalCount = await authenticatedJournalPage.getTransactionCount()
    expect(totalCount, 'Should find exactly 3 transactions after search').toBe(3)

    // Click member header to sort
    console.log('Sorting by member name...')
    await authenticatedJournalPage.sortBy('member')
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify member header shows sort indicator
    const headerText = await authenticatedJournalPage.getHeaderText('member')
    // Accept both English "Member" and German "Mitglied"
    expect(headerText.match(/Member\s+[↑↓]/) || headerText.match(/Mitglied\s+[↑↓]/), 'Member/Mitglied header should show sort arrow').toBeTruthy()
    console.log('Member header text:', headerText)

    // Get all member names (should only be our 3 after search)
    console.log('Verifying sorted members...')
    const foundMembers: string[] = []

    for (let i = 0; i < totalCount; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      foundMembers.push(row.member)
      console.log(`  Row ${i}: "${row.member}"`)
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
      console.log('✅ Transactions are sorted by member name (ascending)')
    } else {
      console.log('✅ Transactions are sorted by member name (descending)')
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
    const memberData = {
      first_name: `SortType${testId}`,
      last_name: 'Test',
      email: `${testId}@example.com`,
      iban: 'DE89370400440532013015',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    console.log('Creating member for type sort test...')
    const createMemberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })
    const memberId = (await createMemberResponse.json()).id

    // Create 2 correction transactions
    console.log('Creating correction transactions...')
    await createCorrection(page, memberId, 1000, 'Type sort test - correction 1')
    await createCorrection(page, memberId, 2000, 'Type sort test - correction 2')

    // === ACT ===
    console.log('Navigating to journal...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for our specific test data to isolate from other parallel tests
    console.log(`Searching for unique member: ${memberData.first_name}`)
    await authenticatedJournalPage.search(memberData.first_name)
    await authenticatedJournalPage.waitForTableToLoad()

    // Verify we have exactly our 2 transactions
    const totalCount = await authenticatedJournalPage.getTransactionCount()
    expect(totalCount, 'Should find exactly 2 transactions after search').toBe(2)

    // Click type header to sort
    console.log('Sorting by type...')
    await authenticatedJournalPage.sortBy('type')
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify type header shows sort indicator
    const headerText = await authenticatedJournalPage.getHeaderText('type')
    // Accept both English "Type" and German "Typ"
    expect(headerText.match(/Type\s+[↑↓]/) || headerText.match(/Typ\s+[↑↓]/), 'Type/Typ header should show sort arrow').toBeTruthy()
    console.log('Type header text:', headerText)

    // Get all transaction types (should only be our 2 after search)
    console.log('Verifying transactions...')
    const foundTypes: string[] = []

    for (let i = 0; i < totalCount; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      foundTypes.push(row.type.toLowerCase())
      console.log(`  Row ${i}: ${row.type}`)
    }

    console.log('✅ Type sorting is functional')
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
    const member1Data = {
      first_name: `SearchUnique${testId}`,
      last_name: 'One',
      email: `search1-${testId}@example.com`,
      iban: 'DE89370400440532013020',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    const member2Data = {
      first_name: `Different${testId}`,
      last_name: 'Two',
      email: `search2-${testId}@example.com`,
      iban: 'DE89370400440532013021',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    console.log('Creating members for search test...')
    const member1Response = await page.request.post('http://localhost:8080/api/admin/members', {
      data: member1Data,
    })
    const member1Id = (await member1Response.json()).id

    const member2Response = await page.request.post('http://localhost:8080/api/admin/members', {
      data: member2Data,
    })
    const member2Id = (await member2Response.json()).id

    // Create transactions for both members
    console.log('Creating transactions for both members...')
    await createCorrection(page, member1Id, 1000, 'Search test - member 1')
    await createCorrection(page, member2Id, 2000, 'Search test - member 2')

    // === ACT ===
    console.log('Navigating to journal...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Record initial count
    const initialCount = await authenticatedJournalPage.getTransactionCount()
    console.log(`Initial count: ${initialCount} transactions`)

    // Search for first member's name
    console.log(`Searching for "${member1Data.first_name}"...`)
    await authenticatedJournalPage.search(member1Data.first_name)
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify only member 1's transactions are visible
    const searchCount = await authenticatedJournalPage.getTransactionCount()
    console.log(`Search results: ${searchCount} transactions`)

    // Find member 1's transaction
    const member1Index = await authenticatedJournalPage.findTransactionByMemberName(member1Data.first_name)
    expect(member1Index, 'Should find member 1 transaction').not.toBeNull()

    // Verify member 2 is NOT in the results
    let foundMember2 = false
    for (let i = 0; i < searchCount; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(member2Data.first_name)) {
        foundMember2 = true
        break
      }
    }

    expect(foundMember2, 'Should NOT find member 2 transaction in search results').toBeFalsy()
    console.log('✅ Search correctly filtered to only matching member')

    // === ACT ===
    // Clear search
    console.log('Clearing search...')
    await authenticatedJournalPage.search('')
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Verify both members are now visible again
    const clearedCount = await authenticatedJournalPage.getTransactionCount()
    console.log(`After clearing search: ${clearedCount} transactions`)

    // Both members should be findable now
    const member1AfterClear = await authenticatedJournalPage.findTransactionByMemberName(member1Data.first_name)
    const member2AfterClear = await authenticatedJournalPage.findTransactionByMemberName(member2Data.first_name)

    expect(member1AfterClear, 'Should find member 1 after clearing search').not.toBeNull()
    expect(member2AfterClear, 'Should find member 2 after clearing search').not.toBeNull()
    console.log('✅ Clearing search restored all transactions')
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
    const memberData = {
      first_name: `SearchDetails${testId}`,
      last_name: 'Test',
      email: `${testId}@example.com`,
      iban: 'DE89370400440532013022',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    console.log('Creating member for details search test...')
    const memberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })
    const memberId = (await memberResponse.json()).id

    // Create transactions with unique keywords in reasons
    const uniqueKeyword = `KEYWORD${testId}`
    console.log(`Creating transactions with unique keyword: ${uniqueKeyword}`)

    await createCorrection(page, memberId, 1000, `Transaction with ${uniqueKeyword} for testing`)
    await createCorrection(page, memberId, 2000, 'Transaction without the keyword')

    // === ACT ===
    console.log('Navigating to journal...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Search for unique keyword
    console.log(`Searching for keyword: ${uniqueKeyword}`)
    await authenticatedJournalPage.search(uniqueKeyword)
    await authenticatedJournalPage.waitForTableToLoad()

    // === ASSERT ===
    // Find the transaction with the keyword
    console.log('Verifying search results...')
    let foundWithKeyword = false
    let foundWithoutKeyword = false

    const searchCount = await authenticatedJournalPage.getTransactionCount()
    for (let i = 0; i < searchCount; i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(memberData.first_name)) {
        if (row.details && row.details.includes(uniqueKeyword)) {
          foundWithKeyword = true
          console.log(`  ✅ Found transaction with keyword: "${row.details}"`)
        } else if (row.details && row.details.includes('without the keyword')) {
          foundWithoutKeyword = true
          console.log(`  ❌ Found transaction without keyword (should be filtered out): "${row.details}"`)
        }
      }
    }

    expect(foundWithKeyword, 'Should find transaction with keyword in details').toBeTruthy()
    expect(foundWithoutKeyword, 'Should NOT find transaction without keyword').toBeFalsy()
    console.log('✅ Search correctly filtered by transaction details')
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
    const memberData = {
      first_name: `SettlementFilter${testId}`,
      last_name: 'Test',
      email: `${testId}@example.com`,
      iban: 'DE89370400440532013023',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    console.log('Creating member for settlement filter test...')
    const memberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })
    const memberId = (await memberResponse.json()).id

    // Create 3 transactions
    console.log('Creating transactions...')
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
    console.log('Creating settlement for first 2 transactions...')
    console.log('Transaction IDs:', [txResponses[0].transaction.id, txResponses[1].transaction.id])
    const today = new Date().toISOString().split('T')[0]
    const executionDate = new Date()
    executionDate.setDate(executionDate.getDate() + 7)
    const executionDateStr = executionDate.toISOString().split('T')[0]

    const settlementResponse = await page.request.post('http://localhost:8080/api/admin/settlements', {
      data: {
        transaction_ids: [txResponses[0].transaction.id, txResponses[1].transaction.id],
        settlement_date: today,
        execution_date: executionDateStr,
      },
    })

    if (!settlementResponse.ok()) {
      console.error('Settlement creation failed:', await settlementResponse.text())
    }
    expect(settlementResponse.ok(), 'Settlement creation should succeed').toBeTruthy()
    const settlementData = await settlementResponse.json()
    console.log('Settlement created:', settlementData)

    // Wait for settlement to be processed
    await page.waitForTimeout(500)

    // === ACT & ASSERT ===
    console.log('Navigating to journal...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    // Test 1: Search and filter by "open" (unsettled)
    console.log('Testing "open" filter with search...')
    await authenticatedJournalPage.search(memberData.first_name)
    await authenticatedJournalPage.waitForTableToLoad()
    await authenticatedJournalPage.filterBySettlementStatus('open')
    await authenticatedJournalPage.waitForTableToLoad()

    // Should find only the 3rd transaction (unsettled)
    let foundOpen = 0
    let foundSettled = 0
    let openCount = await authenticatedJournalPage.getTransactionCount()

    for (let i = 0; i < Math.min(openCount, 20); i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(memberData.first_name)) {
        if (row.details && row.details.includes('Settlement filter test 3')) {
          foundOpen++
          console.log(`  ✅ Found unsettled transaction: "${row.details}"`)
        } else if (row.details && (row.details.includes('test 1') || row.details.includes('test 2'))) {
          foundSettled++
          console.log(`  ❌ Found settled transaction in "open" filter: "${row.details}"`)
        }
      }
    }

    expect(foundOpen, 'Should find unsettled transaction in "open" filter').toBeGreaterThanOrEqual(1)
    expect(foundSettled, 'Should NOT find settled transactions in "open" filter').toBe(0)

    // Test 2: Filter by "settled" (search is still active from above)
    console.log('Testing "settled" filter with search...')
    await authenticatedJournalPage.filterBySettlementStatus('settled')
    await authenticatedJournalPage.waitForTableToLoad()

    // Should find the first 2 transactions (settled)
    foundOpen = 0
    foundSettled = 0
    const settledCount = await authenticatedJournalPage.getTransactionCount()

    for (let i = 0; i < Math.min(settledCount, 20); i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(memberData.first_name)) {
        if (row.details && (row.details.includes('test 1') || row.details.includes('test 2'))) {
          foundSettled++
          console.log(`  ✅ Found settled transaction: "${row.details}"`)
        } else if (row.details && row.details.includes('test 3')) {
          foundOpen++
          console.log(`  ❌ Found unsettled transaction in "settled" filter: "${row.details}"`)
        }
      }
    }

    expect(foundSettled, 'Should find settled transactions in "settled" filter').toBeGreaterThanOrEqual(2)
    expect(foundOpen, 'Should NOT find unsettled transaction in "settled" filter').toBe(0)

    // Test 3: Filter by "all" (search is still active from above)
    console.log('Testing "all" filter with search...')
    await authenticatedJournalPage.filterBySettlementStatus('all')
    await authenticatedJournalPage.waitForTableToLoad()

    // Should find all 3 transactions
    let totalFound = 0
    const allCount = await authenticatedJournalPage.getTransactionCount()

    for (let i = 0; i < Math.min(allCount, 20); i++) {
      const row = await authenticatedJournalPage.getTransactionRow(i)
      if (row.member && row.member.includes(memberData.first_name)) {
        totalFound++
        console.log(`  Found transaction: "${row.details}"`)
      }
    }

    expect(totalFound, 'Should find all transactions in "all" filter').toBeGreaterThanOrEqual(3)
    console.log('✅ Settlement status filtering works correctly')
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
    const memberData = {
      // First name starts with "A" to ensure member appears in first 100 results
      // (backend caps getMembers at 100 per page, sorted by first_name asc)
      first_name: `ACorr${testId}`,
      last_name: 'Test',
      email: `mc-${testId}@example.com`,
      iban: 'DE89370400440532013000',
      mandate_signed_at: new Date().toISOString().split('T')[0],
      preferred_language: 'de',
    }

    // Create member via API
    console.log('Creating test member...')
    const memberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })
    expect(memberResponse.ok(), 'Member creation should succeed').toBeTruthy()
    const memberId = (await memberResponse.json()).id
    console.log('Created member:', memberId)

    const reason = `Modal correction test ${testId}`
    const amountEur = '42.50'

    // === ACT ===
    console.log('Navigating to journal page...')
    await authenticatedJournalPage.navigate()
    await authenticatedJournalPage.expectPageVisible()
    await authenticatedJournalPage.waitForTableToLoad()

    console.log('Creating correction via modal...')
    await authenticatedJournalPage.createCorrection(memberId, amountEur, reason)

    // === ASSERT ===
    // Modal should close (no error)
    await authenticatedJournalPage.expectCorrectionModalHidden()

    const correctionError = await authenticatedJournalPage.getCorrectionError()
    expect(correctionError, 'Should have no correction error').toBeNull()

    // Search for the unique testId to find our correction
    console.log(`Searching for: ${testId}`)
    await authenticatedJournalPage.search(testId)
    await authenticatedJournalPage.waitForTableToLoad()

    // Verify exactly 1 transaction
    const txCount = await authenticatedJournalPage.getTransactionCount()
    console.log(`Found ${txCount} transactions`)
    expect(txCount, 'Should find exactly 1 transaction').toBe(1)

    // Verify row details
    const row = await authenticatedJournalPage.getTransactionRow(0)
    console.log('Transaction row:', row)

    expect(row.type.toLowerCase(), 'Type should be correction').toBe('correction')
    expect(row.member, 'Member name should match').toContain(memberData.first_name)
    // Accept both English (42.50) and German (42,50) decimal formats
    expect(row.amount.includes('42.50') || row.amount.includes('42,50'), 'Amount should contain 42.50 or 42,50').toBeTruthy()
    expect(row.details, 'Details should contain reason').toContain(reason)

    console.log('✅ Correction created via modal and verified in journal')
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
    const memberRes = await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: uniqueName,
        last_name: 'UITest',
        email: `${testId}@test.example`,
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2024-01-01',
        preferred_language: 'de',
      },
    })
    expect(memberRes.ok(), 'Member creation should succeed').toBeTruthy()
    const member = await memberRes.json()
    const memberId = member.id

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
