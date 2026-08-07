import { test, expect } from '../../fixtures/auth.fixture'
import { JournalPage } from '../../pages/JournalPage'
import { SettlementsPage } from '../../pages/SettlementsPage'
import { generateUUID, createTestMember, createSepaInvalidMember } from '../../utils/transactions'
import { minimumExecutionDate, today } from '../../utils/dates'

/**
 * Journal & Settlements E2E Tests (Consolidated)
 *
 * Five flow-based tests replacing journal.spec.ts, settlements.spec.ts,
 * settlements-e2e.spec.ts, and transactions-sepa-validation.spec.ts (~33 tests → 5 tests):
 *
 * 1. Journal fundamentals: display, search, sort, period, filter, storno
 * 2. Settlement lifecycle: Journal UI settle → Settlements page → CSV + SEPA export
 * 3. Settlement integrity: duplicate transaction rejection, atomicity
 * 4. Settle-all + undo: batch settlement, cancel, verify restoration
 * 5. SEPA validation: stornos and sync reject SEPA-invalid members, accept valid members
 *
 * Patterns: 001 (test data isolation), 003 (database-agnostic assertions),
 *           004 (parallel safety), 005 (test IDs), 006 (page object), 008 (expect)
 */

test.describe('Journal & Settlements', () => {

  test('journal: display transactions, search, sort, period picker, settlement filter, create storno', async ({
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

    // Member A: 3 purchase+storno pairs with delays for date sort (MySQL DATETIME = second
    // precision). Each storno must name a distinct purchase it reverses (UNIQUE constraint
    // on related_transaction_id), so member A ends up with 6 transactions total.
    const purchaseA1 = await testTransactions.createSyncTransaction(memberA.id, 500, `${prefix} purchase-for-corr1`)
    await testTransactions.createStorno(memberA.id, 500, `${prefix} corr1`, 'adjustment', purchaseA1)
    await page.waitForTimeout(1100)
    const purchaseA2 = await testTransactions.createSyncTransaction(memberA.id, 2500, `${prefix} purchase-for-corr2`)
    await testTransactions.createStorno(memberA.id, 2500, `${prefix} corr2`, 'adjustment', purchaseA2)
    await page.waitForTimeout(1100)
    const purchaseA3 = await testTransactions.createSyncTransaction(memberA.id, 1000, `${prefix} purchase-for-corr3`)
    // The assertion below reads row 0 as "the newest transaction", so the last
    // storno must be strictly newer than the purchase it reverses — without
    // this wait the two share a second and the default date-desc sort may put
    // either first.
    await page.waitForTimeout(1100)
    const txToSettle = await testTransactions.createStorno(memberA.id, 1000, `${prefix} corr3`, 'adjustment', purchaseA3)

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
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(6)

    // Rows are sorted by date desc by default, so row 0 is the most recently
    // created transaction: the storno reversing purchaseA3.
    const row0 = await journalPage.getTransactionRow(0)
    expect(row0.date).toMatch(/\d{2}[./]\d{2}[./]\d{4}/)
    expect(row0.type.toLowerCase()).toBe('storno')
    expect(row0.member).toContain(`${prefix}A`)
    expect(row0.amount).toMatch(/[\d.,]+/)

    // ── Product name in Details column for purchase transaction ───────
    await journalPage.search(`${prefix}B`)
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(1)
    const purchaseRow = await journalPage.getTransactionRow(0)
    expect(['purchase', 'kauf']).toContain(purchaseRow.type.toLowerCase())
    expect(purchaseRow.details).toContain('JTestBier')

    // ── Search: member name isolation ─────────────────────────────────
    await journalPage.search(`${prefix}A`)
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(6)
    for (let i = 0; i < 6; i++) {
      const r = await journalPage.getTransactionRow(i)
      expect(r.member).not.toContain(`${prefix}B`)
    }

    // ── Search: case-insensitive (member names + product names) ───────
    // Member name search is case-insensitive (utf8mb4_unicode_ci)
    await journalPage.search(`${prefix}a`)
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(6)

    // Uppercase variant also works
    await journalPage.search(`${prefix}A`.toUpperCase())
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(6)

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
    // A settlement now sweeps the member's WHOLE unsettled position (#161 §1),
    // so posting txToSettle above settled all 6 of member A's transactions,
    // not just the one named. To still exercise the "open" side of the
    // filter with a genuinely open row, add one more transaction for member A
    // *after* that settlement — it has nothing to be swept into.
    const purchaseA4 = await testTransactions.createSyncTransaction(memberA.id, 1500, `${prefix} purchase-after-settlement`)

    await journalPage.search(`${prefix}A`)
    await journalPage.waitForTableToLoad()

    await journalPage.filterBySettlementStatus('settled')
    await journalPage.waitForTableToLoad()
    // All 6 pre-settlement transactions were swept in; purchaseA4 was created
    // afterwards and is not settled.
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(6)
    const settledDate = await journalPage.getSettlementDateText(0)
    expect(settledDate).toMatch(/\d{2}[.\/]\d{2}[.\/]\d{4}/)
    expect(settledDate).not.toBe('—')

    await journalPage.filterBySettlementStatus('open')
    await journalPage.waitForTableToLoad()
    // Only purchaseA4 (created after the settlement) remains open.
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(1)
    await journalPage.expectTransactionRowVisible(purchaseA4)
    expect(await journalPage.getSettlementDateText(0)).toBe('—')

    await journalPage.filterBySettlementStatus('all')
    await journalPage.waitForTableToLoad()

    // ── Create storno via API and verify in journal ────────────────
    const corrPrefix = `${prefix}Corr`
    const memberCorr = await testTransactions.createMember(corrPrefix, 'Modal')
    // A storno must name the transaction it reverses — create that purchase first.
    const purchaseForCorr = await testTransactions.createSyncTransaction(memberCorr.id, 4250, `${prefix} purchase-for-modal-corr`)
    // Same second-precision tie as above: row 0 is only reliably the storno if
    // it is written a full second after the purchase it reverses.
    await page.waitForTimeout(1100)
    const corrResp = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/members/${memberCorr.id}/transactions`,
      { data: { reason: 'adjustment', amount_cents: 4250, notes: `corr ${corrPrefix}`, related_transaction_id: purchaseForCorr } },
    )
    expect(corrResp.status()).toBe(201)

    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    // Search by member name prefix (backend search matches member name, not notes)
    await journalPage.search(corrPrefix)
    await journalPage.waitForTableToLoad()
    // The member now has 2 transactions: the purchase + the storno reversing it.
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(2)
    // Row 0 is the most recently created: the storno.
    const corrRow = await journalPage.getTransactionRow(0)
    expect(corrRow.type.toLowerCase()).toBe('storno')
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

    // Member 1: purchase €25.00 + storno €10.00 = €35.00. createStorno() with
    // no related_transaction_id auto-creates the purchase it reverses, so
    // member1 actually ends up with 3 open transactions (purch1 + the auto
    // anchor purchase + the storno) = €45.00, all of which the settlement
    // below sweeps in (#161 §1).
    const txn1Id = await testTransactions.createSyncTransaction(member1.id, 2500, `${prefix} purch1`, product.id)
    const txn2Id = await testTransactions.createStorno(member1.id, 1000, `${prefix} corr1`)
    // Member 2: purchase €15.00 + storno €5.00 = €20.00, plus the auto anchor
    // purchase (€5.00) = €25.00 swept.
    const txn3Id = await testTransactions.createSyncTransaction(member2.id, 1500, `${prefix} purch2`, product.id)
    const txn4Id = await testTransactions.createStorno(member2.id, 500, `${prefix} corr2`)

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
    expect(settlementId).toMatch(/^[0-9a-f-]{36}$/) // UUID format

    // ── Execution date persisted from the UI must be a business day ───
    // The UI derives it from GET /settlements/execution-date-info; this asserts
    // what actually reached the database, on whatever day the suite runs
    // (issue #11).
    const createdResp = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}`)
    expect(createdResp.status()).toBe(200)
    const created = await createdResp.json()

    expect(created.execution_date).toBe(await minimumExecutionDate(authenticatedRequest))
    const execWeekday = new Date(`${created.execution_date}T00:00:00Z`).getUTCDay()
    expect(execWeekday, `${created.execution_date} must not fall on a weekend`).toBeGreaterThan(0)
    expect(execWeekday).toBeLessThan(6)

    // ── Verify on Settlements page ────────────────────────────────────
    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expectSettlementRowVisible(settlementId)

    expect((await settlementsPage.getSettlementMemberCount(settlementId))?.trim()).toMatch(/^2\s/)
    // 45.00 (member1) + 25.00 (member2) = 70.00 — the settlement sweeps each
    // member's whole unsettled position (#161 §1), including the auto anchor
    // purchases createStorno() created for txn2Id/txn4Id above.
    expect(await settlementsPage.getSettlementTotalAmount(settlementId)).toMatch(/70[,.]00/)
    expect((await settlementsPage.getSettlementStatusText(settlementId))?.trim()).toBe('Aktiv')

    // ── Export summary CSV (via UI button) ───────────────────────────
    const csvSummary = await settlementsPage.clickExportCsv(settlementId)
    expect(csvSummary.headers()['content-type']).toContain('csv')
    const csvText = await csvSummary.text()
    const csvLines = csvText.trim().split('\n')
    expect(csvLines[0]).toBe('Member Name;Email;IBAN;Amount EUR')
    expect(csvLines.length).toBe(3) // header + 2 member rows
    expect(csvText).toContain('Ruderer')
    expect(csvText).toContain('Steuermann')

    // ── Export transactions CSV (via UI button) ───────────────────────
    const csvDetail = await settlementsPage.clickExportTransactionsCsv(settlementId)
    expect(csvDetail.headers()['content-type']).toContain('csv')
    const detailText = await csvDetail.text()
    expect(detailText.split('\n')[0]).toContain('transaction_type')
    expect(detailText.split('\n')[0]).toContain('product_name')
    expect(detailText).toContain(`${prefix}1`)
    expect(detailText).toContain(`${prefix}2`)
    expect(detailText).toContain(`${prefix}Bier`)

    // ── Export SEPA XML (via UI button) ──────────────────────────────
    // Re-apply SEPA config right before export to guard against parallel test contamination
    // (sepa_config is a singleton row shared across all tests)
    const reConfigResp = await authenticatedRequest.put('/api/admin/sepa-config', {
      data: { creditor_id: creditorId, creditor_name: creditorName, creditor_iban: creditorIban },
    })
    expect(reConfigResp.status()).toBe(200)

    const sepaResp = await settlementsPage.clickExportSepa(settlementId)
    expect(sepaResp.headers()['content-type']).toContain('xml')

    const xml = await sepaResp.text()
    expect(xml).toContain('urn:iso:std:iso:20022:tech:xsd:pain.008.001.08')
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
    // Member totals: member1 = €45.00 (incl. auto anchor purchase), member2 =
    // €25.00 (incl. auto anchor purchase) — see the sweep note above (#161 §1).
    expect(xml).toContain('45.00')
    expect(xml).toContain('25.00')
    // ReqdColltnDt must be the settlement's own execution date. It previously
    // came from the library's today + 5 fallback because the payment info used
    // an unrecognised key, so the admin's date never reached the file (#11).
    expect(xml).toContain(`<ReqdColltnDt>${created.execution_date}</ReqdColltnDt>`)

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

    // ── Setup: two members so the settlement sweep (#161 §1) can't merge
    // their positions — a settlement now settles the NAMED MEMBER'S whole
    // unsettled position, not just the posted transaction ids, so if txn3
    // belonged to the same member as txn1/txn2 it would already be settled
    // by the first settlement below and this test couldn't tell "duplicate
    // rejected" apart from "already swept".
    const memberA = await testTransactions.createMember(`${prefix}A`, 'Atomic')
    const memberB = await testTransactions.createMember(`${prefix}B`, 'Atomic')
    // createStorno() with no related_transaction_id auto-creates the purchase
    // it reverses, so each storno below actually adds 2 open transactions
    // (anchor purchase + storno) of equal amount.
    const txn1Id = await testTransactions.createStorno(memberA.id, 1000, `${prefix} txn1`)
    const txn2Id = await testTransactions.createStorno(memberA.id, 2000, `${prefix} txn2`)
    const txn3Id = await testTransactions.createStorno(memberB.id, 3000, `${prefix} txn3`)

    // First settlement: names memberA via txn1 + txn2, sweeping ALL of
    // memberA's open transactions — 4 items (2 anchors + 2 stornos), 6000 total.
    const settlement1Id = await testTransactions.createSettlement([txn1Id, txn2Id])

    // ── Attempt duplicate: txn2 (already settled) + txn3 → must reject ─
    const todayStr = today()
    const execDate = await minimumExecutionDate(authenticatedRequest)
    const dupResp = await authenticatedRequest.post('/api/admin/settlements', {
      data: {
        transaction_ids: [txn2Id, txn3Id],
        settlement_date: todayStr,
        execution_date: execDate,
        method: 'direct_debit',
      },
    })
    expect(dupResp.status()).not.toBe(201)
    const errBody = JSON.stringify(await dupResp.json())
    expect(errBody.toLowerCase()).toContain('settled')

    // ── Verify first settlement intact ────────────────────────────────
    const s1Resp = await authenticatedRequest.get(`/api/admin/settlements/${settlement1Id}`)
    expect(s1Resp.status()).toBe(200)
    const s1 = await s1Resp.json()
    expect(s1.id).toBe(settlement1Id)
    expect(s1.total_amount_cents).toBe(6000) // memberA's anchor+storno pairs: 1000+1000+2000+2000
    expect(s1.items.length).toBe(4)
    expect(s1.is_cancelled).toBe(false)

    // ── Verify memberB's txn3 (and its auto anchor purchase) still unsettled ──
    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    // Search by memberB's own prefix (NOT member.first_name — backend LIKE
    // doesn't escape underscore) so memberA's rows don't interfere.
    await journalPage.search(`${prefix}B`)
    await journalPage.waitForTableToLoad()
    await journalPage.filterBySettlementStatus('open')
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(2)
    await journalPage.expectTransactionRowVisible(txn3Id)
  })


  test('settle-all + undo: batch settlement, cancel, verify restoration', async ({
    page,
    testTransactions,
  }) => {
    const ts = Date.now()
    const prefix = `SaU${ts}`

    // ── Setup: 2 members + 1 storno each ──────────────────────────────
    const member1 = await testTransactions.createMember(`${prefix}A`, 'Buyer')
    const member2 = await testTransactions.createMember(`${prefix}B`, 'Buyer')
    // Each storno must name (and reverse) a purchase. Settle that purchase
    // immediately so only the storno itself remains open — this keeps the
    // "open" counts and totals below scoped to exactly the 2 stornos.
    const purchase1 = await testTransactions.createSyncTransaction(member1.id, 1200, `${prefix} purchase1`)
    await testTransactions.createSettlement([purchase1])
    const txn1Id = await testTransactions.createStorno(member1.id, 1200, `${prefix} charge1`, 'adjustment', purchase1)

    const purchase2 = await testTransactions.createSyncTransaction(member2.id, 800, `${prefix} purchase2`)
    await testTransactions.createSettlement([purchase2])
    const txn2Id = await testTransactions.createStorno(member2.id, 800, `${prefix} charge2`, 'adjustment', purchase2)

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
    expect(settlementId).toMatch(/^[0-9a-f-]{36}$/) // UUID format
    await expect(page).toHaveURL(/\/settlements/)

    // ── Verify settlement on Settlements page ─────────────────────────
    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expectSettlementRowVisible(settlementId)
    expect((await settlementsPage.getSettlementStatusText(settlementId))?.trim()).toBe('Aktiv')
    expect((await settlementsPage.getSettlementMemberCount(settlementId))?.trim()).toMatch(/^2\s/)
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


  test('SEPA validation: stornos and sync reject SEPA-invalid members, accept valid members', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const ts = Date.now()
    const prefix = `Sepa${ts}`

    // ── Setup: create SEPA-invalid and valid members ────────────────
    const noIbanData = createSepaInvalidMember(`${prefix}NoIban`, 'Test', 'iban')
    const noMandateData = createSepaInvalidMember(`${prefix}NoMndt`, 'Test', 'mandate')
    const noSepaData = createSepaInvalidMember(`${prefix}NoSepa`, 'Test', 'both')
    const validData = createTestMember(`${prefix}Valid`, 'Test', `sepavalid${ts}`)

    const noIbanResp = await authenticatedRequest.post('http://localhost:8080/api/admin/members', { data: noIbanData })
    expect(noIbanResp.status()).toBe(201)
    const noIbanMember = await noIbanResp.json()
    expect(noIbanMember.is_sepa_valid).toBeFalsy()

    const noMandateResp = await authenticatedRequest.post('http://localhost:8080/api/admin/members', { data: noMandateData })
    expect(noMandateResp.status()).toBe(201)
    const noMandateMember = await noMandateResp.json()
    expect(noMandateMember.is_sepa_valid).toBeFalsy()

    const noSepaResp = await authenticatedRequest.post('http://localhost:8080/api/admin/members', { data: noSepaData })
    expect(noSepaResp.status()).toBe(201)
    const noSepaMember = await noSepaResp.json()
    expect(noSepaMember.is_sepa_valid).toBeFalsy()

    const validResp = await authenticatedRequest.post('http://localhost:8080/api/admin/members', { data: validData })
    expect(validResp.status()).toBe(201)
    const validMember = await validResp.json()
    expect(validMember.is_sepa_valid).toBeTruthy()

    // ── Storno rejected: member without IBAN ─────────────────────────
    // The SEPA check happens before the related-transaction lookup, so a
    // syntactically-valid but non-existent UUID is enough to reach it.
    const corrNoIban = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/members/${noIbanMember.id}/transactions/correct`,
      { data: { amount_cents: 1000, reason: 'Test storno', related_transaction_id: generateUUID() } }
    )
    expect(corrNoIban.status()).toBe(422)
    const errNoIban = await corrNoIban.json()
    expect(errNoIban.error).toBe('sepa_invalid')
    expect(errNoIban.message).toContain('SEPA mandate')

    // ── Storno rejected: member without mandate reference ────────────
    const corrNoMandate = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/members/${noMandateMember.id}/transactions/correct`,
      { data: { amount_cents: 1500, reason: 'Test storno without mandate', related_transaction_id: generateUUID() } }
    )
    expect(corrNoMandate.status()).toBe(422)
    const errNoMandate = await corrNoMandate.json()
    expect(errNoMandate.error).toBe('sepa_invalid')
    expect(errNoMandate.message).toContain('SEPA mandate')

    // ── Storno accepted: valid SEPA member ────────────────────────────
    // Unlike the rejected cases above, this one reaches the related-transaction
    // lookup, so it must name a real purchase belonging to this member.
    const purchaseForValidResp = await authenticatedTerminalRequest.post('http://localhost:8080/api/sync/transactions', {
      data: {
        transactions: [{
          id: generateUUID(),
          member_id: validMember.id,
          type: 'product',
          product_id: generateUUID(),
          quantity: 1,
          unit_price_cents: 2000,
          amount_cents: 2000,
          notes: 'Purchase to be stornoed',
          created_at: new Date().toISOString(),
        }],
      },
    })
    expect(purchaseForValidResp.status()).toBe(201)
    const purchaseForValid = (await purchaseForValidResp.json()).accepted_ids[0]

    const corrValid = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/members/${validMember.id}/transactions/correct`,
      { data: { amount_cents: 2000, reason: 'Test storno for valid member', related_transaction_id: purchaseForValid } }
    )
    expect(corrValid.status()).toBe(201)
    const corrResult = await corrValid.json()
    expect(corrResult.id).toBeTruthy()
    expect(corrResult.member_id).toBe(validMember.id)
    expect(corrResult.amount_cents).toBe(2000)

    // ── Sync rejected: member without any SEPA data ─────────────────
    const rejectedTxnId = generateUUID()
    const syncReject = await authenticatedTerminalRequest.post('http://localhost:8080/api/sync/transactions', {
      data: {
        transactions: [{
          id: rejectedTxnId,
          member_id: noSepaMember.id,
          type: 'product',
          product_id: generateUUID(),
          quantity: 1,
          unit_price_cents: 2500,
          amount_cents: 2500,
          notes: 'Test transaction',
          created_at: new Date().toISOString(),
        }],
      },
    })
    expect(syncReject.status()).toBe(201)
    const rejectResult = await syncReject.json()
    expect(rejectResult.rejected.count).toBe(1)
    expect(rejectResult.accepted_ids).toHaveLength(0)
    expect(rejectResult.rejected.errors[0].error).toBe('sepa_invalid')
    expect(rejectResult.rejected.errors[0].transaction_id).toBe(rejectedTxnId)
    expect(rejectResult.rejected.errors[0].message).toContain('SEPA mandate')

    // ── Sync accepted: valid SEPA member ────────────────────────────
    const acceptedTxnId = generateUUID()
    const syncAccept = await authenticatedTerminalRequest.post('http://localhost:8080/api/sync/transactions', {
      data: {
        transactions: [{
          id: acceptedTxnId,
          member_id: validMember.id,
          type: 'product',
          product_id: generateUUID(),
          quantity: 1,
          unit_price_cents: 3500,
          amount_cents: 3500,
          notes: 'Test sync transaction',
          created_at: new Date().toISOString(),
        }],
      },
    })
    expect(syncAccept.status()).toBe(201)
    const acceptResult = await syncAccept.json()
    expect(acceptResult.accepted_ids).toContain(acceptedTxnId)
    expect(acceptResult.rejected.count).toBe(0)

    // ── Batch: mixed valid and invalid SEPA members ─────────────────
    const batchValidTxnId = generateUUID()
    const batchInvalidTxnId = generateUUID()
    const batchResp = await authenticatedTerminalRequest.post('http://localhost:8080/api/sync/transactions', {
      data: {
        transactions: [
          {
            id: batchValidTxnId,
            member_id: validMember.id,
            type: 'product',
            product_id: generateUUID(),
            quantity: 1,
            unit_price_cents: 1500,
            amount_cents: 1500,
            notes: 'Valid transaction',
            created_at: new Date().toISOString(),
          },
          {
            id: batchInvalidTxnId,
            member_id: noSepaMember.id,
            type: 'product',
            product_id: generateUUID(),
            quantity: 1,
            unit_price_cents: 2500,
            amount_cents: 2500,
            notes: 'Invalid transaction',
            created_at: new Date().toISOString(),
          },
        ],
      },
    })
    expect(batchResp.status()).toBe(201)
    const batchResult = await batchResp.json()
    expect(batchResult.accepted_ids).toContain(batchValidTxnId)
    expect(batchResult.accepted_ids).not.toContain(batchInvalidTxnId)
    expect(batchResult.rejected.count).toBe(1)
    expect(batchResult.rejected.errors[0].transaction_id).toBe(batchInvalidTxnId)
    expect(batchResult.rejected.errors[0].error).toBe('sepa_invalid')
  })

  test('journal: create storno via UI modal', async ({
    page,
    testTransactions,
  }) => {
    const ts = Date.now()
    const prefix = `CorrUI${ts}`

    // Create a member with SEPA data so they appear in the storno member picker
    const member = await testTransactions.createMember(`${prefix}First`, `${prefix}Last`)
    // The storno modal's "related transaction" dropdown lists the selected
    // member's existing transactions — a storno must name the one it reverses.
    const purchaseId = await testTransactions.createSyncTransaction(member.id, 750, `${prefix} purchase to storno`)

    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()

    // Get baseline count for this member before creating the storno
    await journalPage.search(`${prefix}Last`)
    await journalPage.waitForTableToLoad()
    const countBefore = await journalPage.getTransactionCount()

    // Open storno modal, fill form, submit
    await journalPage.openStornoModal()
    await journalPage.fillStornoForm({
      memberId: member.id,
      relatedTransactionId: purchaseId,
      amountEur: 7.50,   // input displays EUR; backend stores 750 cents
      reason: `${prefix} UI storno test`,
    })
    const response = await journalPage.submitStornoForm()

    // Verify API response
    const body = await response.json()
    expect(body.transaction_type).toBe('storno')
    expect(body.amount_cents).toBe(750)

    // Modal should close after successful submission
    await journalPage.expectStornoModalHidden()

    // New storno should appear in the journal for this member
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 })
      .toBe(countBefore + 1)
  })
})
