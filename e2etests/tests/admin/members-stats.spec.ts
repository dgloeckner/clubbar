import { test, expect } from '../../fixtures/auth.fixture'
import { MembersPage } from '../../pages/MembersPage'
import { createTestMember } from '../../utils/transactions'

const API_BASE = 'http://localhost:8080/api'

/**
 * Members Page - Statistics E2E Tests (Consolidated)
 *
 * Single flow test verifying all 3 stat cards:
 * 1. Active members count (only active, not inactive)
 * 2. Outstanding balance (sum of unsettled transactions)
 * 3. Last settlement date (most recent settlement)
 *
 * Uses auth.fixture for testTransactions (terminal sync + admin API).
 */

test.describe('Members Page Statistics', () => {

  test('stat cards: member count reflects only active, balance increases with transactions, settlement date updates', async ({
    page,
    authenticatedRequest,
    testTransactions,
  }) => {
    const membersPage = new MembersPage(page)

    // ── Baseline: capture current stat values ───────────────────────────
    const dashboardResp1 = page.waitForResponse(
      (r) => r.url().includes('/api/admin/dashboard') && r.status() === 200,
      { timeout: 10000 },
    )
    await membersPage.navigate()
    await membersPage.expectPageVisible()
    await dashboardResp1
    await membersPage.waitForStatsToLoad()

    const initialCount = parseInt(await membersPage.getMemberCount(), 10)

    // ── Create test data ────────────────────────────────────────────────
    // 2 active members → count should increase by 2
    await testTransactions.createMember('StActive1')
    await testTransactions.createMember('StActive2')

    // 1 inactive member → should NOT affect active count
    const inactiveData = createTestMember('StInactive')
    const inactiveResp = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: { ...inactiveData, is_active: false },
    })
    expect(inactiveResp.status()).toBe(201)

    // Settlement → last settlement date updates. Settle this member's first
    // transaction BEFORE creating the 3 × 350 purchases below: a settlement now
    // sweeps the named member's WHOLE unsettled position (#161 §1), so if the
    // 350-cent purchases already existed at settlement time they'd be swept in
    // too and the "balance increases by 1050" assertion below would no longer
    // hold.
    const balanceMember = await testTransactions.createMember('StBalance')
    const product = await testTransactions.createProduct('StProd', 350, 'StProduct')
    const txId = await testTransactions.createSyncTransaction(balanceMember.id, 500, 'settle-test', product.id)
    await testTransactions.createSettlement([txId])

    // 3 unsettled transactions (3 × 350 = 1050 cents), created after the
    // settlement above so they stay open → balance increases
    for (let i = 0; i < 3; i++) {
      await testTransactions.createSyncTransaction(balanceMember.id, 350, 'stat-test', product.id)
    }

    // ── Reload and verify stats ─────────────────────────────────────────
    const dashboardResp2 = page.waitForResponse(
      (r) => r.url().includes('/api/admin/dashboard') && r.status() === 200,
      { timeout: 10000 },
    )
    await membersPage.navigate()
    await membersPage.expectPageVisible()
    const dashboard = await (await dashboardResp2).json()
    await membersPage.waitForStatsToLoad()

    // Member count: increased by at least 3 (2 active + 1 balance member; inactive excluded)
    // May be more if parallel tests created members concurrently
    const finalCount = parseInt(await membersPage.getMemberCount(), 10)
    expect(finalCount).toBeGreaterThanOrEqual(initialCount + 3)

    // Balance: the card is a club-wide figure, so it cannot be asserted as
    // "the baseline plus what this test booked" — a settlement run by another
    // worker subtracts from the very same total, and this test then fails for
    // something it never did (Pattern 001). The two things the card actually
    // promises are checked instead, and both are isolation-safe.
    //
    // First: the card shows what the backend says is outstanding, right now.
    const finalBalanceCents = parseGermanCurrencyToCents(await membersPage.getOpenBalance())
    expect(finalBalanceCents).toBe(dashboard.metrics.outstanding_balance_cents)
    // Second: the 3 × 350 this test left open are part of it, and the 500 it
    // settled is not — read off this test's own member, whom nobody else
    // touches.
    const balanceRow = await authenticatedRequest.get(`${API_BASE}/admin/members/${balanceMember.id}`)
    expect(balanceRow.status()).toBe(200)
    expect((await balanceRow.json()).balance_cents).toBe(1050)

    // Last settlement date: contains current year (settlement was just created)
    const settlementDate = await membersPage.getLastSettlementDate()
    expect(settlementDate).toBeTruthy()
    expect(settlementDate).not.toBe('—')
    expect(settlementDate).toContain(new Date().getFullYear().toString())
  })
})

/** Parse German currency string "1.234,56 €" to cents (123456). */
function parseGermanCurrencyToCents(text: string): number {
  const match = text.match(/[\d.,]+/)
  if (!match) return 0
  const normalized = match[0].replace(/\./g, '').replace(',', '.')
  return Math.round(parseFloat(normalized) * 100)
}
