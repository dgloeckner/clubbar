import { test, expect } from '../../fixtures/auth.fixture'
import { JournalPage } from '../../pages/JournalPage'
import { NewSettlementPage } from '../../pages/NewSettlementPage'
import { SettlementsPage } from '../../pages/SettlementsPage'
import { generateUUID, createTestMember, createSepaInvalidMember } from '../../utils/transactions'
import { minimumExecutionDate } from '../../utils/dates'

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
 * Plus targeted regression coverage added since: the storno row action (#169)
 * and pagination on both pages (#89).
 *
 * Patterns: 001 (test data isolation), 003 (database-agnostic assertions),
 *           004 (parallel safety), 005 (test IDs), 006 (page object), 008 (expect)
 */

/**
 * Push one purchase through the terminal sync endpoint and return its id.
 * A storno is scoped to a transaction, so every storno case needs a real
 * purchase to reverse.
 */
async function createPurchaseFor(
  authenticatedTerminalRequest: any,
  memberId: string,
  amountCents: number
): Promise<string> {
  const id = generateUUID()
  const response = await authenticatedTerminalRequest.post('http://localhost:8080/api/sync/transactions', {
    data: {
      transactions: [{
        id,
        member_id: memberId,
        type: 'product',
        product_id: generateUUID(),
        quantity: 1,
        unit_price_cents: amountCents,
        amount_cents: amountCents,
        notes: 'Purchase to be stornoed',
        created_at: new Date().toISOString(),
      }],
    },
  })
  if (response.status() !== 201) {
    throw new Error(`Failed to create purchase: ${response.status()} ${await response.text()}`)
  }
  return id
}

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
    await testTransactions.createStorno(memberA.id, 500, `${prefix} corr1`, purchaseA1)
    await page.waitForTimeout(1100)
    const purchaseA2 = await testTransactions.createSyncTransaction(memberA.id, 2500, `${prefix} purchase-for-corr2`)
    await testTransactions.createStorno(memberA.id, 2500, `${prefix} corr2`, purchaseA2)
    await page.waitForTimeout(1100)
    const purchaseA3 = await testTransactions.createSyncTransaction(memberA.id, 1000, `${prefix} purchase-for-corr3`)
    // The assertion below reads row 0 as "the newest transaction", so the last
    // storno must be strictly newer than the purchase it reverses — without
    // this wait the two share a second and the default date-desc sort may put
    // either first.
    await page.waitForTimeout(1100)
    const txToSettle = await testTransactions.createStorno(memberA.id, 1000, `${prefix} corr3`, purchaseA3)

    // Member B: 1 purchase with real product (verify product name in Details column)
    const purchaseB = await testTransactions.createSyncTransaction(memberB.id, 350, `${prefix} purchase`, product.id)

    // Settle one of member A's transactions (for settlement filter + date column).
    // Member B comes along because member A's pairs net to zero and a run that
    // collects nothing is refused (#372) — B's purchase is what the run
    // collects, while A's six rows are swept and closed out beside it.
    await testTransactions.createSettlement([txToSettle, purchaseB])

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
      `http://localhost:8080/api/admin/transactions/${purchaseForCorr}/storno`,
      { data: { reason: `corr ${corrPrefix}` } },
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
      data: {
        creditor_id: creditorId,
        creditor_name: creditorName,
        creditor_iban: creditorIban,
        // #360/#456: SepaExportService also requires this now.
        mandate_template_url: 'https://club.example/anmeldung',
      },
    })
    expect(configResp.status()).toBe(200)

    // ── Create SEPA-eligible members and transactions ─────────────────
    // Use SEPA-safe last names (no underscores — stripped by SepaSanitizer)
    const member1 = await testTransactions.createMember(`${prefix}1`, 'Ruderer')
    const member2 = await testTransactions.createMember(`${prefix}2`, 'Steuermann')
    const product = await testTransactions.createProduct(`${prefix}Bier`, 250, `${prefix}Beer`)

    // Member 1: purchase €25.00, plus createStorno() with no
    // related_transaction_id auto-creating a fresh €10.00 purchase and fully
    // reversing it (#169 — the amount is always the exact negation of a real
    // transaction, never a freely-typed adjustment). That anchor purchase and
    // its storno net to zero, so member1 ends up with 3 open transactions
    // (purch1 + the auto anchor purchase + the storno) totalling €25.00, all
    // of which the settlement below sweeps in (#161 §1).
    const txn1Id = await testTransactions.createSyncTransaction(member1.id, 2500, `${prefix} purch1`, product.id)
    const txn2Id = await testTransactions.createStorno(member1.id, 1000, `${prefix} corr1`)
    // Member 2: purchase €15.00, plus a €5.00 auto anchor purchase and its
    // storno (net zero) = €15.00 swept.
    const txn3Id = await testTransactions.createSyncTransaction(member2.id, 1500, `${prefix} purch2`, product.id)
    const txn4Id = await testTransactions.createStorno(member2.id, 500, `${prefix} corr2`)

    // ── Settle via the New Settlement screen ──────────────────────────
    // The run picks *members*; each is then settled in full (ADR-0030). The
    // four transaction ids above are what that sweep must end up covering,
    // not what the admin ticks.
    const journalPage = new JournalPage(page)
    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()

    await newSettlement.toggleSelectAll() // clear the default whole-club run
    await newSettlement.selectMember(member1.id)
    await newSettlement.selectMember(member2.id)

    const summary = await newSettlement.getRunSummary()
    expect(summary.members).toBe(2)
    expect(summary.transactions).toBe(6) // 2 purchases + 2 anchor purchases + 2 stornos

    const settlementId = await newSettlement.create()
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

    // Every transaction above was booked just now, so the swept period is the
    // run's own date and the row must not print it a second time (#378).
    await settlementsPage.expectNoTransactionPeriod(settlementId)

    expect((await settlementsPage.getSettlementMemberCount(settlementId))?.trim()).toMatch(/^2\s/)
    // 25.00 (member1) + 15.00 (member2) = 40.00 — the settlement sweeps each
    // member's whole unsettled position (#161 §1). The auto anchor purchases
    // createStorno() created for txn2Id/txn4Id are swept in too, but each
    // nets to zero against its own storno, so only the real purchases show
    // up in the total.
    expect(await settlementsPage.getSettlementTotalAmount(settlementId)).toMatch(/40[,.]00/)
    // "Entwurf" since #377: nothing has been exported yet, and the badge now
    // names the rung rather than lumping every live run under "Aktiv".
    expect((await settlementsPage.getSettlementStatusText(settlementId))?.trim()).toBe('Entwurf')

    // The run's canonical reference: the settlement's id with the hyphens
    // stripped. The same string is the pain.008 MsgId and the Verwendungszweck
    // the member reads, which is what makes a member's question answerable —
    // asserted further down against the exported file itself.
    const reference = settlementId.replaceAll('-', '').toLowerCase()
    await settlementsPage.expectSettlementReference(settlementId, reference)

    // ── Export summary CSV (via UI button) ───────────────────────────
    const csvSummary = await settlementsPage.clickExportCsv(settlementId)
    expect(csvSummary.headers()['content-type']).toContain('csv')
    const csvText = await csvSummary.text()
    const csvLines = csvText.trim().split('\n')
    // Every row names the run, so a member line still says which settlement it
    // belongs to once it is pasted next to another one's.
    expect(csvLines[0]).toBe('Settlement;Member Name;Email;IBAN;Amount EUR')
    expect(csvSummary.headers()['content-disposition']).toContain(`-${reference}.csv`)
    expect(csvLines[1]).toContain(reference)
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
      data: {
        creditor_id: creditorId,
        creditor_name: creditorName,
        creditor_iban: creditorIban,
        // #360/#456: SepaExportService also requires this now.
        mandate_template_url: 'https://club.example/anmeldung',
      },
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
    // Member totals: member1 = €25.00, member2 = €15.00 — the auto anchor
    // purchase/storno pairs net to zero, see the sweep note above (#161 §1).
    expect(xml).toContain('25.00')
    expect(xml).toContain('15.00')
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


  test('settlements: the SEPA export tells the treasurer whom the file leaves out', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    // #114. The download is a pain.008 document, so the file itself cannot say
    // that it collects less than the settlement records — the admin used to
    // save a short file and see nothing at all. A member whose IBAN is cleared
    // between settlement creation and export is exactly that case.
    const settlement = await settlementFactory.create({ amountCents: 1500, memberCount: 2 })
    const [, dropped] = settlement.members

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // A clean export says nothing — the warning has to mean something when it
    // does appear.
    await settlementsPage.clickExportSepa(settlement.id)
    await settlementsPage.expectNoExportShortfallWarning()

    // Clearing the IBAN revokes the mandate; the settlement still claims the
    // member's transactions and still records their €15.00.
    expect(
      (await authenticatedRequest.patch(`/api/admin/members/${dropped.id}`, { data: { iban: null } })).status()
    ).toBe(200)

    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.clickExportSepa(settlement.id)

    // Named count and both amounts: 15.00 collected of the 30.00 recorded.
    await settlementsPage.expectExportShortfallWarning(/1.*15,00.*30,00.*15,00/s)
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
    // (anchor purchase + storno) that net to zero — the storno's amount is
    // always the exact negation of the purchase it names (#169).
    const txn1Id = await testTransactions.createStorno(memberA.id, 1000, `${prefix} txn1`)
    const txn2Id = await testTransactions.createStorno(memberA.id, 2000, `${prefix} txn2`)
    const txn3Id = await testTransactions.createStorno(memberB.id, 3000, `${prefix} txn3`)
    // A run that collects nothing is refused (#372), and memberA's two pairs
    // net to zero on their own — so memberA also owes for one plain purchase,
    // which is what the settlement below actually collects.
    await testTransactions.createSyncTransaction(memberA.id, 1500, `${prefix} owed`)

    // First settlement: names memberA via txn1 + txn2, sweeping ALL of
    // memberA's open transactions — 5 items (2 anchors + 2 stornos + the
    // purchase). Each anchor nets to zero against its own storno, so the
    // total is the one purchase nothing reverses.
    const settlement1Id = await testTransactions.createSettlement([txn1Id, txn2Id])

    // ── Attempt duplicate: txn2 (already settled) + txn3 → must reject ─
    const execDate = await minimumExecutionDate(authenticatedRequest)
    const dupResp = await authenticatedRequest.post('/api/admin/settlements', {
      data: {
        transaction_ids: [txn2Id, txn3Id],
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
    // memberA's anchor+storno pairs each net to zero: (1000-1000)+(2000-2000),
    // leaving the plain purchase as the whole of what the run collects.
    expect(s1.total_amount_cents).toBe(1500)
    expect(s1.items.length).toBe(5)
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
    // Each storno must name (and reverse) a purchase (#169), and a member in
    // credit (net negative unsettled position) is excluded from settle-all
    // entirely (ruling #141) — settling only a lone storno would strand both
    // members there. So the purchase stays open alongside its storno: the
    // pair nets to zero, which is not credit, and both remain open for the
    // "open" counts and totals below.
    const purchase1 = await testTransactions.createSyncTransaction(member1.id, 1200, `${prefix} purchase1`)
    const txn1Id = await testTransactions.createStorno(member1.id, 1200, `${prefix} charge1`, purchase1)

    const purchase2 = await testTransactions.createSyncTransaction(member2.id, 800, `${prefix} purchase2`)
    const txn2Id = await testTransactions.createStorno(member2.id, 800, `${prefix} charge2`, purchase2)

    // …and one plain purchase each, because a run whose members all net to
    // zero collects nothing and is refused (#372). These are what the run
    // collects; the pairs above ride along and close out.
    await testTransactions.createSyncTransaction(member1.id, 1500, `${prefix} owed1`)
    await testTransactions.createSyncTransaction(member2.id, 500, `${prefix} owed2`)

    // ── Settle these two members, then undo ──────────────────────────
    // "Settle all" is no longer a separate button: the New Settlement screen
    // opens with every eligible member selected, so the whole-club run is the
    // default and this test narrows it to its own two members (ADR-0030).
    const journalPage = new JournalPage(page)
    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()

    await newSettlement.toggleSelectAll()
    await newSettlement.selectMember(member1.id)
    await newSettlement.selectMember(member2.id)

    const summary = await newSettlement.getRunSummary()
    // 2 stornoed purchases + their 2 stornos + the 2 plain purchases.
    expect(summary.transactions).toBe(6)
    expect(summary.members).toBe(2)

    const settlementId = await newSettlement.create()
    expect(settlementId).toMatch(/^[0-9a-f-]{36}$/) // UUID format
    await expect(page).toHaveURL(/\/settlements/)

    // ── Verify settlement on Settlements page ─────────────────────────
    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expectSettlementRowVisible(settlementId)
    expect((await settlementsPage.getSettlementStatusText(settlementId))?.trim()).toBe('Entwurf')
    expect((await settlementsPage.getSettlementMemberCount(settlementId))?.trim()).toMatch(/^2\s/)
    // Each stornoed purchase nets to zero (1200-1200, 800-800), so the total is
    // the two plain purchases: 15.00 + 5.00.
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


  test('SEPA validation: a storno needs no mandate; sync stores and flags unbanked sales', async ({
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

    // ── Storno needs no mandate: member without IBAN ─────────────────
    // This block used to assert a 422 `sepa_invalid`. That gate is GONE (#169,
    // ruling #158 §1): a storno *reduces* what the member owes, so refusing it
    // for a member who cannot be collected from is inverted — it withheld the
    // § 812 BGB remedy from exactly the people who needed it. The storno is now
    // scoped to a transaction, so each case needs a real purchase to reverse.
    const purchaseNoIban = await createPurchaseFor(authenticatedTerminalRequest, noIbanMember.id, 1000)
    const stornoNoIban = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/transactions/${purchaseNoIban}/storno`,
      { data: { reason: 'No IBAN, still reversible' } }
    )
    expect(stornoNoIban.status()).toBe(201)
    const stornoNoIbanBody = await stornoNoIban.json()
    expect(stornoNoIbanBody.member_id).toBe(noIbanMember.id)
    expect(stornoNoIbanBody.amount_cents).toBe(-1000)

    // ── Storno needs no mandate: member without mandate reference ─────
    const purchaseNoMandate = await createPurchaseFor(authenticatedTerminalRequest, noMandateMember.id, 1500)
    const stornoNoMandate = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/transactions/${purchaseNoMandate}/storno`,
      { data: { reason: 'No mandate, still reversible' } }
    )
    expect(stornoNoMandate.status()).toBe(201)
    expect((await stornoNoMandate.json()).amount_cents).toBe(-1500)

    // ── And for a fully valid member, unchanged ───────────────────────
    const purchaseForValid = await createPurchaseFor(authenticatedTerminalRequest, validMember.id, 2000)
    const stornoValid = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/transactions/${purchaseForValid}/storno`,
      { data: { reason: 'Storno for valid member' } }
    )
    expect(stornoValid.status()).toBe(201)
    const stornoValidBody = await stornoValid.json()
    expect(stornoValidBody.id).toBeTruthy()
    expect(stornoValidBody.member_id).toBe(validMember.id)
    // Derived, never typed: the exact negation of the purchase.
    expect(stornoValidBody.amount_cents).toBe(-2000)

    // ── Sync stores and flags: member without any SEPA data (#162) ──
    // The drink was already served against the terminal's cached state. The
    // server stores the sale and lets the settlement layer flag the member;
    // rejecting here would erase a sale nobody could ever be billed for.
    const unbankedTxnId = generateUUID()
    const syncReject = await authenticatedTerminalRequest.post('http://localhost:8080/api/sync/transactions', {
      data: {
        transactions: [{
          id: unbankedTxnId,
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
    expect(rejectResult.rejected.count).toBe(0)
    expect(rejectResult.rejected.errors).toHaveLength(0)
    expect(rejectResult.accepted_ids).toContain(unbankedTxnId)
    // The sale counts against the member's unsettled position like any other.
    expect(rejectResult.member_balances[noSepaMember.id]).toBe(2500)

    // ── The flag: the member surfaces in the treasurer's attention bucket ──
    // Derived, not stored (ruling #143 §1): an unsettled balance held by a
    // member without an active mandate is what `ineligible_members` means.
    const previewResp = await authenticatedRequest.post('http://localhost:8080/api/admin/settlements/preview', {
      data: { member_id: noSepaMember.id },
    })
    expect(previewResp.status()).toBe(200)
    const preview = await previewResp.json()
    expect(preview.eligible_members.map((m: { member_id: string }) => m.member_id)).not.toContain(noSepaMember.id)
    const flagged = preview.ineligible_members.find((m: { member_id: string }) => m.member_id === noSepaMember.id)
    expect(flagged).toBeTruthy()
    expect(flagged.balance_cents).toBe(2500)

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

    // ── Batch: mixed valid and invalid SEPA members, both stored ────
    const batchValidTxnId = generateUUID()
    const batchUnbankedTxnId = generateUUID()
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
            id: batchUnbankedTxnId,
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
    expect(batchResult.accepted_ids).toContain(batchUnbankedTxnId)
    expect(batchResult.rejected.count).toBe(0)
    // Both rows land, and the unbanked member's Deckel keeps growing.
    expect(batchResult.member_balances[noSepaMember.id]).toBe(5000)
  })

  test('journal: storno a transaction from its row, confirm what is reversed, see the linkage', async ({
    page,
    testTransactions,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const prefix = `StornoUI${ts}`

    const member = await testTransactions.createMember(`${prefix}First`, `${prefix}Last`)
    const purchaseId = await testTransactions.createSyncTransaction(member.id, 750, `${prefix} purchase`)

    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    await journalPage.search(`${prefix}Last`)
    await journalPage.waitForTableToLoad()

    const countBefore = await journalPage.getTransactionCount()

    // ── The confirmation must say WHAT is being reversed ──────────────
    await journalPage.openStornoDialog(purchaseId)
    const subject = await journalPage.getStornoDialogSubject()
    expect(subject.member).toContain(`${prefix}Last`)
    expect(subject.amount).toContain('7')      // €7.50, however the locale formats it
    expect(subject.amount).toContain('50')
    expect(subject.date).not.toBe('')
    expect(subject.product).not.toBe('')

    // Cancel leaves everything untouched — the dialog is a decision point.
    await journalPage.cancelStorno()
    await journalPage.expectStornoDialogHidden()
    expect(await journalPage.getTransactionCount()).toBe(countBefore)

    // ── Storno for real ───────────────────────────────────────────────
    await journalPage.openStornoDialog(purchaseId)
    await journalPage.fillStornoReason(`${prefix} wrong card scanned`)
    const response = await journalPage.confirmStorno()

    // The UI sent only a reason; the amount came back derived as the negation.
    const body = await response.json()
    expect(body.transaction_type).toBe('storno')
    expect(body.amount_cents).toBe(-750)
    expect(body.related_transaction_id).toBe(purchaseId)
    expect(response.request().postDataJSON()).toEqual({ reason: `${prefix} wrong card scanned` })

    await journalPage.expectStornoDialogHidden()

    // ── Persisted, and visibly linked in both directions ──────────────
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 })
      .toBe(countBefore + 1)

    await journalPage.expectTransactionRowVisible(body.id)
    await journalPage.expectLinkedToOriginal(body.id)
    await journalPage.expectMarkedAsStornoed(purchaseId)

    // ── The rules hold in the UI, not just the API ────────────────────
    // The original is spent: its action is disabled rather than silently failing.
    await journalPage.expectStornoDisabled(purchaseId)
    // And a storno offers no storno of its own.
    await journalPage.expectNoStornoAction(body.id)

    // ── Confirm against the database, not just the screen ─────────────
    const verify = await authenticatedRequest.get(
      `http://localhost:8080/api/admin/transactions?per_page=100&search=${prefix}Last`
    )
    expect(verify.ok()).toBeTruthy()
    const rows = (await verify.json()).data
    const stornoRow = rows.find((t: any) => t.id === body.id)
    expect(stornoRow).toBeDefined()
    expect(stornoRow.amount_cents).toBe(-750)
    expect(stornoRow.related_transaction_id).toBe(purchaseId)
    const originalRow = rows.find((t: any) => t.id === purchaseId)
    expect(originalRow.stornoed_by_transaction_id).toBe(body.id)
    // Purchase and storno net to zero.
    expect(originalRow.amount_cents + stornoRow.amount_cents).toBe(0)
  })

  test('journal: a transaction stornoed by someone else is refused, not double-booked', async ({
    page,
    testTransactions,
    authenticatedRequest,
  }) => {
    // Two admins open the same wrong booking. The second one to confirm must be
    // told why it failed, not shown a generic error and not allowed to write a
    // second storno — the unique index refuses it and the UI has to explain.
    const ts = Date.now()
    const prefix = `StornoRace${ts}`

    const member = await testTransactions.createMember(`${prefix}First`, `${prefix}Last`)
    const purchaseId = await testTransactions.createSyncTransaction(member.id, 900, `${prefix} purchase`)

    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    await journalPage.search(`${prefix}Last`)
    await journalPage.waitForTableToLoad()

    // Open the dialog, then let "the other admin" storno it behind our back.
    await journalPage.openStornoDialog(purchaseId)
    const behindOurBack = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/transactions/${purchaseId}/storno`,
      { data: { reason: `${prefix} the other admin got there first` } }
    )
    expect(behindOurBack.status()).toBe(201)

    await journalPage.fillStornoReason(`${prefix} our attempt`)
    await journalPage.confirmStorno(409)

    await journalPage.expectStornoDialogError(/stornier|storno/i)

    // Exactly one storno exists for that purchase — the database refused ours.
    const verify = await authenticatedRequest.get(
      `http://localhost:8080/api/admin/transactions?per_page=100&search=${prefix}Last`
    )
    const rows = (await verify.json()).data
    const stornos = rows.filter((t: any) => t.related_transaction_id === purchaseId)
    expect(stornos).toHaveLength(1)
  })

  test('journal: paging past page 1 sticks, and picking a period resets it (#89)', async ({
    page,
    testTransactions,
  }) => {
    // The PeriodPicker used to announce its range from a `useEffect` whose
    // deps included the consumer's (non-memoized) handler. Every re-render
    // handed it a new handler identity, so the effect re-fired and the handler
    // called setCurrentPage(1) — clicking page 2 snapped straight back to
    // page 1 and the journal could not be paged at all.
    const ts = Date.now()
    const prefix = `JPag${ts}`

    const member = await testTransactions.createMember(`${prefix}First`, `${prefix}Last`)
    // 12 rows across 2 pages of 10, so a reset to page 1 is visible in both the
    // active page button and the row count.
    for (let i = 0; i < 12; i++) {
      await testTransactions.createSyncTransaction(member.id, 100 + i, `${prefix} purchase ${i}`)
    }

    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    await journalPage.search(`${prefix}Last`)
    await journalPage.waitForTableToLoad()

    await journalPage.setPageSize(10)
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(10)
    await journalPage.expectActivePage(1)

    // ── Page 2, and it has to STAY page 2 ─────────────────────────────
    await journalPage.goToPage(2)
    await journalPage.waitForListToSettle()

    await journalPage.expectActivePage(2)
    expect(await journalPage.getPaginationInfo()).toContain('11-12')
    expect(await journalPage.getTransactionCount()).toBe(2)
    // The filter the page started with is untouched by paging.
    await journalPage.expectPeriodButtonActive('3m')

    // ── Picking a period DOES reset to page 1 ─────────────────────────
    // The reset itself is wanted — a new filter has a different page 1. What
    // was broken is that it happened without anyone touching the filter.
    await journalPage.selectPeriod('1m')
    await journalPage.waitForTableToLoad()
    await journalPage.expectActivePage(1)
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(10)
    await journalPage.expectPeriodButtonActive('1m')
  })

  test('settlements: paging past page 1 sticks (#89)', async ({
    page,
    testTransactions,
  }) => {
    // Same defect, second consumer of the PeriodPicker. The settlements list
    // has no search filter, so this test has to create enough settlements that
    // a second page exists regardless of what else is in the database
    // (Pattern 003: database-agnostic assertions).
    test.setTimeout(90000)

    const ts = Date.now()
    const prefix = `SPag${ts}`

    const member = await testTransactions.createMember(`${prefix}First`, `${prefix}Last`)

    // 11 settlements → at 10 per page there is always a page 2. A settlement
    // sweeps the member's whole open position (#161 §1), so each purchase
    // below starts from an empty position and ends up in its own settlement.
    for (let i = 0; i < 11; i++) {
      const purchaseId = await testTransactions.createSyncTransaction(
        member.id,
        100 + i,
        `${prefix} purchase ${i}`
      )
      await testTransactions.createSettlement([purchaseId])
    }

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    await settlementsPage.setPageSize(10)
    await settlementsPage.waitForListToSettle()
    await settlementsPage.expectActivePage(1)
    expect(await settlementsPage.getSettlementRowCount()).toBe(10)

    // ── Page 2, and it has to STAY page 2 ─────────────────────────────
    await settlementsPage.goToPage(2)
    await settlementsPage.waitForListToSettle()

    await settlementsPage.expectActivePage(2)
    // The toolbar counts from row 11 — it is showing the second page, not the
    // first one relabelled.
    expect(await settlementsPage.getPaginationInfo()).toContain('11-')
    expect(await settlementsPage.getSettlementRowCount()).toBeGreaterThan(0)
    await settlementsPage.expectPeriodButtonActive('3m')
  })

  test('journal: a member with no SEPA mandate can still be stornoed from the UI', async ({
    page,
    testTransactions,
    authenticatedRequest,
  }) => {
    // A storno *reduces* what the member owes. The old correction form refused
    // this outright, which gated a debt reduction on the ability to collect —
    // inverted, and it blocked the § 812 BGB remedy for exactly the members who
    // could not be billed (#158 §1).
    const ts = Date.now()
    const prefix = `StornoNoSepa${ts}`

    const memberData = createSepaInvalidMember(`${prefix}First`, `${prefix}Last`, 'both')
    const memberResp = await authenticatedRequest.post('http://localhost:8080/api/admin/members', {
      data: memberData,
    })
    expect(memberResp.status()).toBe(201)
    const member = await memberResp.json()
    expect(member.is_sepa_valid).toBeFalsy()

    const purchaseId = await testTransactions.createSyncTransaction(member.id, 420, `${prefix} purchase`)

    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()
    await journalPage.search(`${prefix}Last`)
    await journalPage.waitForTableToLoad()

    await journalPage.openStornoDialog(purchaseId)
    await journalPage.fillStornoReason(`${prefix} no mandate, still reversible`)
    const response = await journalPage.confirmStorno()

    const body = await response.json()
    expect(body.amount_cents).toBe(-420)
    await journalPage.expectStornoDialogHidden()
  })
})
