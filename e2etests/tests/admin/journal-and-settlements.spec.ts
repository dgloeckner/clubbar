import { test, expect } from '../../fixtures/auth.fixture'
import { JournalPage } from '../../pages/JournalPage'
import { SettlementsPage } from '../../pages/SettlementsPage'

/**
 * Journal & Settlements E2E Tests (Consolidated)
 *
 * Four flow-based tests replacing journal.spec.ts, settlements.spec.ts,
 * and settlements-e2e.spec.ts (~27 tests → 4 tests):
 *
 * 1. Journal fundamentals: display, search, sort, period, filter, correction
 * 2. Settlement lifecycle: Journal UI settle → Settlements page → CSV + SEPA export
 * 3. Settlement integrity: duplicate transaction rejection, atomicity
 * 4. Settle-all + undo: batch settlement, cancel, verify restoration
 *
 * Patterns: 001 (test data isolation), 003 (database-agnostic assertions),
 *           004 (parallel safety), 005 (test IDs), 006 (page object), 008 (expect)
 */

test.describe('Journal & Settlements', () => {

  test('journal: display transactions, search, sort, period picker, settlement filter, create correction', async ({
    page,
    testTransactions,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const prefix = `Jrn${ts}`

    // ── Setup: create test data via API ──────────────────────────────
    const memberA = await testTransactions.createMember(`${prefix}A`, 'Alpha')
    const memberB = await testTransactions.createMember(`${prefix}B`, 'Beta')
    const product = await testTransactions.createProduct('JTestBier', 350, 'JTest Beer')

    // Member A: 3 corrections with delays for date sort (MySQL DATETIME = second precision)
    await testTransactions.createCorrection(memberA.id, 500, `${prefix} corr1`)
    await page.waitForTimeout(1100)
    await testTransactions.createCorrection(memberA.id, 2500, `${prefix} corr2`)
    await page.waitForTimeout(1100)
    const txToSettle = await testTransactions.createCorrection(memberA.id, 1000, `${prefix} corr3`)

    // Member B: 1 purchase with real product (verify product name in Details column)
    await testTransactions.createSyncTransaction(memberB.id, 350, `${prefix} purchase`, product.id)

    // Settle one of member A's transactions (for settlement filter + date column)
    await testTransactions.createSettlement([txToSettle])

    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()

    // ── Display: verify transaction table and row data ────────────────
    // Search by prefix+A (NOT member.first_name which has _ wildcard that backend LIKE doesn't escape)
    await journalPage.search(`${prefix}A`)
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(3)

    const row0 = await journalPage.getTransactionRow(0)
    expect(row0.date).toBeTruthy()
    expect(row0.type.toLowerCase()).toBe('correction')
    expect(row0.member).toContain(`${prefix}A`)
    expect(row0.amount).toBeTruthy()

    // ── Product name in Details column for purchase transaction ───────
    await journalPage.search(`${prefix}B`)
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(1)
    const purchaseRow = await journalPage.getTransactionRow(0)
    expect(purchaseRow.type.toLowerCase()).toBe('purchase')
    expect(purchaseRow.details).toContain('JTestBier')

    // ── Search: member name isolation ─────────────────────────────────
    await journalPage.search(`${prefix}A`)
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(3)
    for (let i = 0; i < 3; i++) {
      const r = await journalPage.getTransactionRow(i)
      expect(r.member).not.toContain(`${prefix}B`)
    }

    // ── Sort by date: toggle asc/desc ─────────────────────────────────
    expect(await journalPage.getHeaderText('date')).toContain('↓')
    await journalPage.sortBy('date')
    expect(await journalPage.getHeaderText('date')).toContain('↑')

    // ── Sort by amount: verify indicator ──────────────────────────────
    await journalPage.sortBy('amount')
    expect(await journalPage.getHeaderText('amount')).toMatch(/[↑↓]/)

    // ── Period picker ─────────────────────────────────────────────────
    await journalPage.navigate() // reset all filters
    await journalPage.waitForPageLoad()
    await journalPage.expectPeriodButtonActive('3m')

    await journalPage.selectPeriod('1m')
    await journalPage.expectPeriodButtonActive('1m')
    await journalPage.expectPeriodButtonInactive('3m')

    await journalPage.selectPeriod('all')
    await journalPage.expectPeriodButtonActive('all')
    expect(await journalPage.getTransactionCount()).toBeGreaterThan(0)

    // ── Settlement status filter + settlement date column ─────────────
    await journalPage.search(`${prefix}A`)
    await journalPage.waitForTableToLoad()

    await journalPage.filterBySettlementStatus('settled')
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(1)
    const settledDate = await journalPage.getSettlementDateText(0)
    expect(settledDate).toMatch(/\d{2}[.\/]\d{2}[.\/]\d{4}/)
    expect(settledDate).not.toBe('—')

    await journalPage.filterBySettlementStatus('open')
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(2)
    expect(await journalPage.getSettlementDateText(0)).toBe('—')

    await journalPage.filterBySettlementStatus('all')
    await journalPage.waitForTableToLoad()

    // ── Create correction via API and verify in journal ────────────────
    const corrPrefix = `${prefix}Corr`
    const memberCorr = await testTransactions.createMember(corrPrefix, 'Modal')
    const corrResp = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/members/${memberCorr.id}/transactions/correction`,
      { data: { reason: 'adjustment', amount_cents: 4250, notes: `corr ${corrPrefix}` } },
    )
    expect(corrResp.status()).toBe(201)

    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    // Search by member name prefix (backend search matches member name, not notes)
    await journalPage.search(corrPrefix)
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(1)
    const corrRow = await journalPage.getTransactionRow(0)
    expect(corrRow.type.toLowerCase()).toBe('correction')
    expect(corrRow.amount).toMatch(/42[,.]50/)
  })


  test('settlement lifecycle: Journal UI settle → Settlements page → CSV + SEPA export', async ({
    page,
    authenticatedRequest,
    testTransactions,
  }) => {
    const ts = Date.now()
    const prefix = `Stl${ts}`

    // ── Configure SEPA creditor ──────────────────────────────────────
    const creditorName = 'E2E SEPA Test Club'
    const creditorId = 'DE98ZZZ09999999999'
    const creditorIban = 'DE89370400440532013000'
    const configResp = await authenticatedRequest.put('/api/admin/sepa-config', {
      data: { creditor_id: creditorId, creditor_name: creditorName, creditor_iban: creditorIban },
    })
    expect(configResp.status()).toBe(200)

    // ── Create SEPA-eligible members and transactions ─────────────────
    // Use SEPA-safe last names (no underscores — stripped by SepaSanitizer)
    const member1 = await testTransactions.createMember(`${prefix}1`, 'Ruderer')
    const member2 = await testTransactions.createMember(`${prefix}2`, 'Steuermann')
    const product = await testTransactions.createProduct(`${prefix}Bier`, 250, `${prefix}Beer`)

    // Member 1: purchase €25.00 + correction €10.00 = €35.00
    const txn1Id = await testTransactions.createSyncTransaction(member1.id, 2500, `${prefix} purch1`, product.id)
    const txn2Id = await testTransactions.createCorrection(member1.id, 1000, `${prefix} corr1`)
    // Member 2: purchase €15.00 + correction €5.00 = €20.00
    const txn3Id = await testTransactions.createSyncTransaction(member2.id, 1500, `${prefix} purch2`, product.id)
    const txn4Id = await testTransactions.createCorrection(member2.id, 500, `${prefix} corr2`)

    // ── Settle via Journal UI ─────────────────────────────────────────
    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    await journalPage.filterBySettlementStatus('open')
    await journalPage.waitForTableToLoad()
    await journalPage.enterSettlementMode()

    await journalPage.selectTransactionById(txn1Id)
    await journalPage.selectTransactionById(txn2Id)
    await journalPage.selectTransactionById(txn3Id)
    await journalPage.selectTransactionById(txn4Id)
    expect(await journalPage.getSelectedTransactionCount()).toBe(4)

    const settlementId = await journalPage.concludeSettlement()
    expect(settlementId).toBeTruthy()

    // ── Verify on Settlements page ────────────────────────────────────
    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expectSettlementRowVisible(settlementId)

    expect((await settlementsPage.getSettlementMemberCount(settlementId))?.trim()).toBe('2')
    expect(await settlementsPage.getSettlementTotalAmount(settlementId)).toMatch(/55[,.]00/)
    expect((await settlementsPage.getSettlementStatusText(settlementId))?.trim()).toBe('Aktiv')

    // ── Export summary CSV ────────────────────────────────────────────
    const csvSummary = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export-csv`)
    expect(csvSummary.status()).toBe(200)
    const csvText = await csvSummary.text()
    const csvLines = csvText.trim().split('\n')
    expect(csvLines[0]).toBe('Member Name;Email;IBAN;Amount EUR')
    expect(csvLines.length).toBe(3) // header + 2 member rows
    expect(csvText).toContain('Ruderer')
    expect(csvText).toContain('Steuermann')

    // ── Export detail CSV ─────────────────────────────────────────────
    const csvDetail = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export-transactions`)
    expect(csvDetail.status()).toBe(200)
    const detailText = await csvDetail.text()
    expect(detailText.split('\n')[0]).toContain('transaction_type')
    expect(detailText.split('\n')[0]).toContain('product_name')
    expect(detailText).toContain(`${prefix}1`)
    expect(detailText).toContain(`${prefix}2`)
    expect(detailText).toContain(`${prefix}Bier`)

    // ── Export SEPA XML ───────────────────────────────────────────────
    const sepaResp = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export-sepa`)
    expect(sepaResp.status()).toBe(200)
    expect(sepaResp.headers()['content-type']).toContain('xml')

    const xml = await sepaResp.text()
    expect(xml).toContain('pain.008')
    expect(xml).toContain('GrpHdr')
    expect(xml).toContain('DrctDbtTxInf')
    // Creditor data
    expect(xml).toContain(creditorName)
    expect(xml).toContain(creditorIban)
    expect(xml).toContain(creditorId)
    // Debtor data (last names are SEPA-safe)
    expect(xml).toContain('Ruderer')
    expect(xml).toContain('Steuermann')
    expect(xml).toContain(member1.mandate_reference)
    expect(xml).toContain(member2.mandate_reference)
    // Member totals: member1 = €35.00, member2 = €20.00
    expect(xml).toContain('35.00')
    expect(xml).toContain('20.00')

    // ── Verify status changed to Exportiert ───────────────────────────
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    expect((await settlementsPage.getSettlementStatusText(settlementId))?.trim()).toBe('Exportiert')
    await settlementsPage.expectUndoButtonEnabled(settlementId)

    // ── Verify transactions marked as settled in Journal ──────────────
    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    await journalPage.search(`${prefix}1`)
    await journalPage.waitForTableToLoad()
    await journalPage.filterBySettlementStatus('settled')
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBeGreaterThanOrEqual(1)
  })


  test('settlement integrity: duplicate transaction rejection preserves atomicity', async ({
    page,
    authenticatedRequest,
    testTransactions,
  }) => {
    const ts = Date.now()
    const prefix = `Dup${ts}`

    // ── Setup: member + 3 transactions ────────────────────────────────
    const member = await testTransactions.createMember(prefix, 'Atomic')
    const txn1Id = await testTransactions.createCorrection(member.id, 1000, `${prefix} txn1`)
    const txn2Id = await testTransactions.createCorrection(member.id, 2000, `${prefix} txn2`)
    const txn3Id = await testTransactions.createCorrection(member.id, 3000, `${prefix} txn3`)

    // First settlement: txn1 + txn2
    const settlement1Id = await testTransactions.createSettlement([txn1Id, txn2Id])

    // ── Attempt duplicate: txn2 + txn3 → must reject ──────────────────
    const today = new Date().toISOString().split('T')[0]
    const execDate = new Date(Date.now() + 7 * 86400000).toISOString().split('T')[0]
    const dupResp = await authenticatedRequest.post('/api/admin/settlements', {
      data: {
        transaction_ids: [txn2Id, txn3Id],
        settlement_date: today,
        execution_date: execDate,
        settlement_type: 'sepa',
      },
    })
    expect(dupResp.status()).not.toBe(201)
    const errBody = JSON.stringify(await dupResp.json())
    expect(errBody.toLowerCase()).toContain('settlement')

    // ── Verify first settlement intact ────────────────────────────────
    const s1Resp = await authenticatedRequest.get(`/api/admin/settlements/${settlement1Id}`)
    expect(s1Resp.status()).toBe(200)
    const s1 = await s1Resp.json()
    expect(s1.id).toBe(settlement1Id)
    expect(s1.total_amount_cents).toBe(3000) // 1000 + 2000
    expect(s1.items.length).toBe(2)
    expect(s1.is_cancelled).toBe(false)

    // ── Verify txn3 still unsettled ───────────────────────────────────
    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    // Search by prefix (NOT member.first_name — backend LIKE doesn't escape underscore)
    await journalPage.search(prefix)
    await journalPage.waitForTableToLoad()
    await journalPage.filterBySettlementStatus('open')
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBeGreaterThanOrEqual(1)
  })


  test('settle-all + undo: batch settlement, cancel, verify restoration', async ({
    page,
    testTransactions,
  }) => {
    const ts = Date.now()
    const prefix = `SaU${ts}`

    // ── Setup: 2 members + 1 correction each ──────────────────────────
    const member1 = await testTransactions.createMember(`${prefix}A`, 'Buyer')
    const member2 = await testTransactions.createMember(`${prefix}B`, 'Buyer')
    const txn1Id = await testTransactions.createCorrection(member1.id, 1200, `${prefix} charge1`)
    const txn2Id = await testTransactions.createCorrection(member2.id, 800, `${prefix} charge2`)

    // ── Settle-all: search → preview → confirm ───────────────────────
    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    await journalPage.search(prefix)
    await journalPage.waitForTableToLoad()
    await journalPage.filterBySettlementStatus('open')
    await journalPage.waitForTableToLoad()

    await journalPage.openSettleAllModal()
    const stats = await journalPage.getSettlementConfirmStats()
    expect(stats.transactions).toBe(2)
    expect(stats.members).toBe(2)

    const settlementId = await journalPage.confirmOpenSettlement()
    expect(settlementId).toBeTruthy()
    await expect(page).toHaveURL(/\/settlements/)

    // ── Verify settlement on Settlements page ─────────────────────────
    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expectSettlementRowVisible(settlementId)
    expect((await settlementsPage.getSettlementStatusText(settlementId))?.trim()).toBe('Aktiv')
    expect((await settlementsPage.getSettlementMemberCount(settlementId))?.trim()).toBe('2')
    expect(await settlementsPage.getSettlementTotalAmount(settlementId)).toMatch(/20[,.]00/)

    // ── Undo settlement ───────────────────────────────────────────────
    await settlementsPage.undoSettlement(settlementId)
    expect((await settlementsPage.getSettlementStatusText(settlementId))?.trim()).toBe('Storniert')
    await settlementsPage.expectUndoButtonDisabled(settlementId)

    // ── Verify transactions restored to open in Journal ───────────────
    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    await journalPage.search(prefix)
    await journalPage.waitForTableToLoad()
    await journalPage.filterBySettlementStatus('open')
    await journalPage.waitForTableToLoad()
    await journalPage.expectTransactionRowVisible(txn1Id)
    await journalPage.expectTransactionRowVisible(txn2Id)
  })
})
