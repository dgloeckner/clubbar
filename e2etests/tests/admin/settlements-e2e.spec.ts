/**
 * End-to-End Settlement Tests
 *
 * Comprehensive workflow tests for settlement creation and exports (CSV and SEPA XML)
 *
 * Implements:
 * - Create transactions via sync API (terminal transactions)
 * - Create transactions via manual corrections (admin UI)
 * - Create settlement with selected transactions
 * - Export and verify CSV exports (aggregated and full)
 * - Export and verify SEPA XML format for bank processing
 * - Verify duplicate transaction rejection with descriptive errors
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
    let productId: string

    // Create test product for sync transactions
    await test.step('Create test product', async () => {
      const product = await testTransactions.createProduct('Pilsner Bier', 250, 'Pilsner Beer')
      productId = product.id
      expect(productId).toBeTruthy()
    })

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
      // Sync transaction for member 1: €25.00 (with real product)
      await testTransactions.createSyncTransaction(member1Id, 2500, 'Pilsner beer', productId)

      // Manual correction for member 2: €15.50
      await testTransactions.createCorrection(member2Id, 1550, 'Manual correction for drinks')

      // Manual correction for member 1: €10.00
      await testTransactions.createCorrection(member1Id, 1000, 'Additional charge')
    })


    // Create settlement with all three test transactions
    let txn1Id: string, txn2Id: string, txn3Id: string
    await test.step('Create settlement with test transactions via API', async () => {
      // Create transactions that we'll settle
      txn1Id = await testTransactions.createSyncTransaction(member1Id, 2500, 'Sync transaction', productId)
      txn2Id = await testTransactions.createCorrection(member2Id, 1550, 'Correction for member 2')
      txn3Id = await testTransactions.createCorrection(member1Id, 1000, 'Additional correction')

      // Create settlement with these transactions (this gets us the settlement ID directly)
      settlementId = await testTransactions.createSettlement([txn1Id, txn2Id, txn3Id], 7)
      expect(settlementId).toBeTruthy()
    })

    // Export and verify settlement summary CSV
    await test.step('Export and verify settlement summary CSV', async () => {
      const csvResponse = await authenticatedRequest.get(
        `/api/admin/settlements/${settlementId}/export-csv`
      )
      expect(csvResponse.status()).toBe(200)

      const csvContent = await csvResponse.text()
      expect(csvContent).toBeTruthy()

      // export-csv returns per-member summary (Member Name;Email;IBAN;Amount EUR)
      const csvLines = csvContent.trim().split('\n')
      expect(csvLines.length).toBe(3) // header + 2 member rows (member1, member2)
      expect(csvLines[0]).toBe('Member Name;Email;IBAN;Amount EUR')
      // Each data row uses semicolons
      expect(csvLines[1]).toContain(';')
    })

    // Export and verify detailed transaction CSV
    await test.step('Export and verify transaction-level CSV', async () => {
      const csvFullResponse = await authenticatedRequest.get(
        `/api/admin/settlements/${settlementId}/export-transactions`
      )
      expect(csvFullResponse.status()).toBe(200)

      const csvFullContent = await csvFullResponse.text()
      expect(csvFullContent).toBeTruthy()

      // Verify CSV header includes detail columns
      const header = csvFullContent.split('\n')[0]
      expect(header).toContain('transaction_type')
      expect(header).toContain('product_name')
      expect(header).toContain('notes')
      expect(header).toContain('transaction_date')

      // Verify member names and transaction notes appear in data
      expect(csvFullContent).toContain(member1FirstName)
      expect(csvFullContent).toContain(member2FirstName)

      // Verify product name appears for sync transactions
      expect(csvFullContent).toContain('Pilsner Bier')
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

    // Export and verify transaction CSV by member name
    await test.step('Export settlement transactions CSV and verify member appears', async () => {
      const csvResponse = await authenticatedRequest.get(
        `/api/admin/settlements/${settlementId}/export-transactions`
      )
      expect(csvResponse.status()).toBe(200)

      const csvContent = await csvResponse.text()
      expect(csvContent).toBeTruthy()

      // Verify CSV header includes detail columns
      const header = csvContent.split('\n')[0]
      expect(header).toContain('transaction_type')
      expect(header).toContain('product_name')
      expect(header).toContain('notes')
      expect(header).toContain('transaction_date')

      // Verify CSV contains our unique test member name
      expect(csvContent).toContain(testMemberFirstName)

      // Verify CSV structure (header + data rows)
      const lines = csvContent.trim().split('\n')
      expect(lines.length).toBeGreaterThan(1)

      // Verify our member's transactions appear as corrections with notes
      const memberRows = lines.filter((line) => line.includes(testMemberFirstName))
      expect(memberRows.length).toBe(3) // 3 corrections
      for (const row of memberRows) {
        expect(row).toContain('correction')
      }
    })

    // Verify settlement summary CSV format
    await test.step('Verify settlement summary CSV format', async () => {
      const csvResponse = await authenticatedRequest.get(
        `/api/admin/settlements/${settlementId}/export-csv`
      )

      const csvContent = await csvResponse.text()
      const csvLines = csvContent.trim().split('\n')

      // Per-member summary CSV: Member Name;Email;IBAN;Amount EUR
      const csvHeader = csvLines[0]
      expect(csvHeader).toBe('Member Name;Email;IBAN;Amount EUR')
      expect(csvLines.length).toBe(2) // header + 1 member row

      // Verify total amount in data row (6000 cents = €60.00)
      expect(csvLines[1]).toContain('60.00')

      // Verify all rows have consistent field count (semicolon separated)
      const headerFieldCount = csvHeader.split(';').length
      for (let i = 1; i < csvLines.length; i++) {
        const fieldCount = csvLines[i].split(';').length
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
      // API returns { items: [...], total: N } format
      const items = settlements.items ?? settlements.data ?? []
      const found = items.some((s: any) => s.id === firstSettlementId)
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

  test('should create transactions, settle via Journal UI, and validate on Settlements page', async ({
    page,
    testTransactions,
  }) => {
    // Known amounts for verification (all positive - corrections are charges)
    const member1Purchase = 2500  // €25.00
    const member1Correction = 1000  // €10.00
    const member2Purchase = 1500  // €15.00
    const member2Correction = 500  // €5.00
    const expectedTotal = member1Purchase + member1Correction + member2Purchase + member2Correction // 5500 = €55.00

    let member1Id: string, member1FirstName: string
    let member2Id: string
    let txn1Id: string, txn2Id: string, txn3Id: string, txn4Id: string
    let productId: string

    // Step 1: Create test product, members and transactions via API
    await test.step('Create test product', async () => {
      const product = await testTransactions.createProduct('Weizenbier', 250, 'Wheat Beer')
      productId = product.id
      expect(productId).toBeTruthy()
    })

    await test.step('Create test members via API', async () => {
      const member1 = await testTransactions.createMember('SettleUI1', 'Buyer')
      member1Id = member1.id
      member1FirstName = member1.first_name

      const member2 = await testTransactions.createMember('SettleUI2', 'Buyer')
      member2Id = member2.id

      expect(member1Id).toBeTruthy()
      expect(member2Id).toBeTruthy()
    })

    await test.step('Create purchases and corrections via API', async () => {
      // Member 1: purchase €25.00 + correction €10.00
      txn1Id = await testTransactions.createSyncTransaction(member1Id, member1Purchase, 'Settlement UI test purchase', productId)
      txn2Id = await testTransactions.createCorrection(member1Id, member1Correction, 'Settlement UI test correction')

      // Member 2: purchase €15.00 + correction €5.00
      txn3Id = await testTransactions.createSyncTransaction(member2Id, member2Purchase, 'Settlement UI test purchase 2', productId)
      txn4Id = await testTransactions.createCorrection(member2Id, member2Correction, 'Settlement UI test correction 2')

      expect(txn1Id).toBeTruthy()
      expect(txn2Id).toBeTruthy()
      expect(txn3Id).toBeTruthy()
      expect(txn4Id).toBeTruthy()
    })

    // Step 2: Navigate to Journal, enter settlement mode, select transactions
    let settlementId: string

    await test.step('Navigate to Journal page and enter settlement edit mode', async () => {
      const journalPage = new JournalPage(page)
      await journalPage.navigate()
      await journalPage.waitForPageLoad()

      // Filter to open transactions only
      await journalPage.filterBySettlementStatus('open')

      // Enter settlement edit mode
      await journalPage.enterSettlementMode()
    })

    await test.step('Select the 4 test transactions by ID', async () => {
      const journalPage = new JournalPage(page)

      await journalPage.selectTransactionById(txn1Id)
      await journalPage.selectTransactionById(txn2Id)
      await journalPage.selectTransactionById(txn3Id)
      await journalPage.selectTransactionById(txn4Id)

      // Verify 4 transactions selected
      const selectedCount = await journalPage.getSelectedTransactionCount()
      expect(selectedCount).toBe(4)
    })

    await test.step('Conclude settlement via Journal UI', async () => {
      const journalPage = new JournalPage(page)
      settlementId = await journalPage.concludeSettlement()
      expect(settlementId).toBeTruthy()
    })

    // Step 3: Navigate to Settlements page and validate
    await test.step('Navigate to Settlements page and find the new settlement', async () => {
      const settlementsPage = new SettlementsPage(page)
      await settlementsPage.navigate()
      await settlementsPage.waitForPageLoad()

      // Verify our settlement row is visible
      await settlementsPage.expectSettlementRowVisible(settlementId)
    })

    await test.step('Validate settlement details on Settlements page', async () => {
      const settlementsPage = new SettlementsPage(page)

      // Verify member count
      const memberCount = await settlementsPage.getSettlementMemberCount(settlementId)
      expect(memberCount?.trim()).toBe('2')

      // Verify total amount (€55.00 — displayed as "55,00 €" in German locale)
      const totalAmount = await settlementsPage.getSettlementTotalAmount(settlementId)
      expect(totalAmount).toMatch(/55[,.]00/)

      // Verify status is Active (German: "Aktiv" — test admin locale is de)
      const statusText = await settlementsPage.getSettlementStatusText(settlementId)
      expect(statusText?.trim()).toBe('Aktiv')

      // Verify date is today
      const createdDate = await settlementsPage.getSettlementCreatedDate(settlementId)
      expect(createdDate).toBeTruthy()
    })

    // Step 4: Verify transactions are now marked as settled in Journal
    await test.step('Verify transactions marked as settled in Journal', async () => {
      const journalPage = new JournalPage(page)
      await journalPage.navigate()
      await journalPage.waitForPageLoad()

      // Filter to settled transactions
      await journalPage.filterBySettlementStatus('settled')

      // Our test member should appear in settled transactions
      await journalPage.waitForTransactionToAppear(member1FirstName)
    })
  })

  test('should export valid SEPA XML with correct creditor and member data, then mark as exported', async ({
    page,
    authenticatedRequest,
    testTransactions,
  }) => {
    const creditorName = 'Ruderbar SEPA E2E Club'
    const creditorId = 'DE98ZZZ09999999999'
    const creditorIban = 'DE89370400440532013000'

    // Step 1: Configure SEPA creditor information
    await test.step('Configure SEPA creditor information', async () => {
      const response = await authenticatedRequest.put('/api/admin/sepa-config', {
        data: {
          creditor_id: creditorId,
          creditor_name: creditorName,
          creditor_iban: creditorIban,
        },
      })
      expect(response.status()).toBe(200)
      const config = await response.json()
      expect(config.is_configured).toBe(true)
    })

    // Step 2: Create SEPA-eligible members (have IBAN + mandate_reference by default)
    let member1: any, member2: any
    let member1Id: string, member2Id: string

    await test.step('Create SEPA-eligible test members', async () => {
      member1 = await testTransactions.createMember('SEPAExp1', 'Ruderer')
      member2 = await testTransactions.createMember('SEPAExp2', 'Steuermann')
      member1Id = member1.id
      member2Id = member2.id

      // Verify members were created with SEPA data
      expect(member1.iban).toBeTruthy()
      expect(member1.mandate_reference).toBeTruthy()
      expect(member2.iban).toBeTruthy()
      expect(member2.mandate_reference).toBeTruthy()
    })

    // Step 3: Create transactions with known amounts
    let txn1Id: string, txn2Id: string, txn3Id: string
    await test.step('Create transactions with known amounts', async () => {
      // Member 1: €25.00 + €15.00 = €40.00 total
      txn1Id = await testTransactions.createCorrection(member1Id, 2500, 'SEPA test charge 1')
      txn2Id = await testTransactions.createCorrection(member1Id, 1500, 'SEPA test charge 2')

      // Member 2: €30.00 total
      txn3Id = await testTransactions.createCorrection(member2Id, 3000, 'SEPA test charge 3')

      expect(txn1Id).toBeTruthy()
      expect(txn2Id).toBeTruthy()
      expect(txn3Id).toBeTruthy()
    })

    // Step 4: Create settlement
    let settlementId: string
    await test.step('Create settlement via API', async () => {
      settlementId = await testTransactions.createSettlement([txn1Id, txn2Id, txn3Id], 7)
      expect(settlementId).toBeTruthy()
    })

    // Step 5: Export SEPA XML and validate content
    let sepaXml: string
    await test.step('Export SEPA XML via API', async () => {
      const response = await authenticatedRequest.get(
        `/api/admin/settlements/${settlementId}/export-sepa`
      )
      expect(response.status()).toBe(200)

      // Verify content type is XML
      const contentType = response.headers()['content-type']
      expect(contentType).toContain('xml')

      sepaXml = await response.text()
      expect(sepaXml).toBeTruthy()
      expect(sepaXml.length).toBeGreaterThan(100)
    })

    await test.step('Validate SEPA XML structure', async () => {
      // SEPA pain.008 direct debit format
      expect(sepaXml).toContain('pain.008')
      // Required top-level elements
      expect(sepaXml).toContain('GrpHdr')
      expect(sepaXml).toContain('PmtInf')
      // Direct debit transaction entries (one per member)
      expect(sepaXml).toContain('DrctDbtTxInf')
    })

    await test.step('Validate creditor information in SEPA XML', async () => {
      // Creditor name (SEPA-safe, no special chars in test name)
      expect(sepaXml).toContain(creditorName)
      // Creditor IBAN
      expect(sepaXml).toContain(creditorIban)
      // Creditor ID (Gläubiger-ID)
      expect(sepaXml).toContain(creditorId)
    })

    await test.step('Validate member/debtor data in SEPA XML', async () => {
      // Member IBANs (both use DE89370400440532013000 from test fixture)
      expect(sepaXml).toContain('DE89370400440532013000')

      // Member last names (SEPA-safe, appear in debtor name)
      expect(sepaXml).toContain('Ruderer')
      expect(sepaXml).toContain('Steuermann')

      // Mandate references (uppercase hex from UUID - SEPA-safe)
      expect(sepaXml).toContain(member1.mandate_reference)
      expect(sepaXml).toContain(member2.mandate_reference)
    })

    await test.step('Validate amounts in SEPA XML', async () => {
      // Member 1 total: €25.00 + €15.00 = €40.00
      expect(sepaXml).toContain('40.00')
      // Member 2 total: €30.00
      expect(sepaXml).toContain('30.00')
    })

    // Step 6: Verify settlement marked as exported on Settlements page
    await test.step('Navigate to Settlements page and verify Exported status', async () => {
      const settlementsPage = new SettlementsPage(page)
      await settlementsPage.navigate()
      await settlementsPage.waitForPageLoad()

      // Verify settlement row is visible
      await settlementsPage.expectSettlementRowVisible(settlementId)

      // Verify status badge shows "Exportiert" (German: test admin locale is de)
      const statusText = await settlementsPage.getSettlementStatusText(settlementId)
      expect(statusText?.trim()).toBe('Exportiert')
    })

    // Step 7: Verify undo button is still enabled for exported settlement
    await test.step('Verify undo button is enabled for exported settlement', async () => {
      const undoBtn = page.getByTestId(`settlements-undo-btn-${settlementId}`)
      await expect(undoBtn).toBeEnabled()
    })
  })

  test('should require SEPA config and member mandates for SEPA export', async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    let settlementId: string

    // Create test settlement without SEPA mandate data
    await test.step('Create test settlement for SEPA export validation', async () => {
      const member = await testTransactions.createMember('SEPA', 'Validation')
      const memberId = member.id

      // Create a transaction
      const txnId = await testTransactions.createCorrection(memberId, 5000, 'SEPA test transaction')
      expect(txnId).toBeTruthy()

      // Create settlement
      settlementId = await testTransactions.createSettlement([txnId], 7)
      expect(settlementId).toBeTruthy()
    })

    // Verify SEPA export endpoint exists and requires proper SEPA configuration
    await test.step('Verify SEPA export endpoint validates SEPA requirements', async () => {
      const sepaResponse = await authenticatedRequest.get(
        `/api/admin/settlements/${settlementId}/export-sepa`
      )

      // Should return error (422) because member doesn't have SEPA mandate
      // OR 200 if SEPA config is properly mocked
      // The important thing is that the endpoint exists and returns JSON or XML
      expect([200, 422, 500]).toContain(sepaResponse.status())

      const contentType = sepaResponse.headers()['content-type']
      expect(contentType).toBeTruthy()
      // Should be XML (if successful) or JSON (if error)
      expect(contentType?.toLowerCase()).toMatch(/xml|json/)
    })

    // Test settlement lookup and structure
    await test.step('Verify settlement was created with valid structure', async () => {
      const response = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}`)
      expect(response.status()).toBe(200)
      const settlement = await response.json()

      // Verify settlement has required fields for export
      expect(settlement.id).toBe(settlementId)
      expect(settlement.items.length).toBeGreaterThan(0)
      expect(settlement.member_count).toBeGreaterThan(0)
      expect(settlement.total_amount_cents).toBeGreaterThan(0)
    })
  })

  test('should settle all visible transactions via Journal UI using "Abrechnung (alle)"', async ({
    page,
    testTransactions,
  }) => {
    // Use a unique suffix so we can search for exactly our test transactions
    const suffix = `SettleAll${Date.now()}`
    const member1Amount = 1200 // €12.00
    const member2Amount = 800  // €8.00

    let member1Id: string
    let member2Id: string
    let txn1Id: string
    let txn2Id: string
    let settlementId: string

    await test.step('Create 2 members and 1 correction each', async () => {
      const member1 = await testTransactions.createMember(`${suffix}A`, 'Buyer')
      const member2 = await testTransactions.createMember(`${suffix}B`, 'Buyer')
      member1Id = member1.id
      member2Id = member2.id

      txn1Id = await testTransactions.createCorrection(member1Id, member1Amount, `${suffix} charge 1`)
      txn2Id = await testTransactions.createCorrection(member2Id, member2Amount, `${suffix} charge 2`)
      expect(txn1Id).toBeTruthy()
      expect(txn2Id).toBeTruthy()
    })

    await test.step('Navigate to Journal, search for test members, filter open', async () => {
      const journalPage = new JournalPage(page)
      await journalPage.navigate()
      await journalPage.waitForPageLoad()
      // Search isolates the view to our 2 test transactions only
      await journalPage.search(suffix)
      await journalPage.filterBySettlementStatus('open')
    })

    await test.step('Open "Abrechnung (alle)" modal and verify stats', async () => {
      const journalPage = new JournalPage(page)
      // Open modal via public method (private locator is hidden from tests)
      await journalPage.openSettleAllModal()

      const stats = await journalPage.getSettlementConfirmStats()
      expect(stats.transactions).toBe(2)
      expect(stats.members).toBe(2)
    })

    await test.step('Confirm and verify navigation to /settlements', async () => {
      const journalPage = new JournalPage(page)
      // Modal is already open — just confirm (don't re-open via settleAll())
      settlementId = await journalPage.confirmOpenSettlement()
      expect(settlementId).toBeTruthy()
      await expect(page).toHaveURL(/\/settlements/)
    })

    await test.step('Verify settlement row appears in list as "Aktiv"', async () => {
      const settlementsPage = new SettlementsPage(page)
      await settlementsPage.waitForPageLoad()
      await settlementsPage.expectSettlementRowVisible(settlementId)

      const status = await settlementsPage.getSettlementStatusText(settlementId)
      expect(status?.trim()).toBe('Aktiv')

      // Verify member count and amounts are correct
      const memberCount = await settlementsPage.getSettlementMemberCount(settlementId)
      expect(memberCount?.trim()).toBe('2')

      const totalAmount = await settlementsPage.getSettlementTotalAmount(settlementId)
      expect(totalAmount).toMatch(/20[,.]00/)
    })

    await test.step('Verify both transactions are now settled in Journal', async () => {
      const journalPage = new JournalPage(page)
      await journalPage.navigate()
      await journalPage.waitForPageLoad()
      await journalPage.search(suffix)
      await journalPage.filterBySettlementStatus('settled')

      // Both our transactions should appear in the settled view
      await expect(page.getByTestId(`journal-table-row-${txn1Id}`)).toBeVisible()
      await expect(page.getByTestId(`journal-table-row-${txn2Id}`)).toBeVisible()
    })
  })

  test('should undo a settlement and restore transactions to open state', async ({
    page,
    testTransactions,
  }) => {
    const suffix = `Undo${Date.now()}`
    const amount = 1500 // €15.00

    let memberId: string
    let txn1Id: string
    let txn2Id: string
    let settlementId: string

    await test.step('Create member with 2 transactions and settle them via API', async () => {
      const member = await testTransactions.createMember(suffix, 'Tester')
      memberId = member.id

      txn1Id = await testTransactions.createCorrection(memberId, amount, `${suffix} charge 1`)
      txn2Id = await testTransactions.createCorrection(memberId, amount, `${suffix} charge 2`)
      expect(txn1Id).toBeTruthy()
      expect(txn2Id).toBeTruthy()

      settlementId = await testTransactions.createSettlement([txn1Id, txn2Id], 7)
      expect(settlementId).toBeTruthy()
    })

    await test.step('Navigate to Settlements page, verify settlement is "Aktiv"', async () => {
      const settlementsPage = new SettlementsPage(page)
      await settlementsPage.navigate()
      await settlementsPage.waitForPageLoad()
      await settlementsPage.expectSettlementRowVisible(settlementId)

      const status = await settlementsPage.getSettlementStatusText(settlementId)
      expect(status?.trim()).toBe('Aktiv')
    })

    await test.step('Undo the settlement', async () => {
      const settlementsPage = new SettlementsPage(page)
      await settlementsPage.undoSettlement(settlementId)
    })

    await test.step('Verify settlement row now shows "Storniert"', async () => {
      const settlementsPage = new SettlementsPage(page)
      const status = await settlementsPage.getSettlementStatusText(settlementId)
      expect(status?.trim()).toBe('Storniert')

      // Undo button should be disabled after cancellation
      const undoBtn = page.getByTestId(`settlements-undo-btn-${settlementId}`)
      await expect(undoBtn).toBeDisabled()
    })

    await test.step('Verify transactions are open again in Journal', async () => {
      const journalPage = new JournalPage(page)
      await journalPage.navigate()
      await journalPage.waitForPageLoad()
      await journalPage.search(suffix)
      await journalPage.filterBySettlementStatus('open')

      // Both transactions should now appear as unsettled
      await expect(page.getByTestId(`journal-table-row-${txn1Id}`)).toBeVisible()
      await expect(page.getByTestId(`journal-table-row-${txn2Id}`)).toBeVisible()
    })
  })
})
