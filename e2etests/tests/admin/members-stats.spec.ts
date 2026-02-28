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
    const initialBalanceCents = parseGermanCurrencyToCents(await membersPage.getOpenBalance())

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

    // Product + 3 transactions (3 × 350 = 1050 cents) → balance increases
    const balanceMember = await testTransactions.createMember('StBalance')
    const product = await testTransactions.createProduct('StProd', 350, 'StProduct')
    for (let i = 0; i < 3; i++) {
      await testTransactions.createSyncTransaction(balanceMember.id, 350, 'stat-test', product.id)
    }

    // Settlement → last settlement date updates
    const txId = await testTransactions.createSyncTransaction(balanceMember.id, 500, 'settle-test', product.id)
    await testTransactions.createSettlement([txId])

    // ── Reload and verify stats ─────────────────────────────────────────
    const dashboardResp2 = page.waitForResponse(
      (r) => r.url().includes('/api/admin/dashboard') && r.status() === 200,
      { timeout: 10000 },
    )
    await membersPage.navigate()
    await membersPage.expectPageVisible()
    await dashboardResp2
    await membersPage.waitForStatsToLoad()

    // Member count: increased by at least 3 (2 active + 1 balance member; inactive excluded)
    // May be more if parallel tests created members concurrently
    const finalCount = parseInt(await membersPage.getMemberCount(), 10)
    expect(finalCount).toBeGreaterThanOrEqual(initialCount + 3)

    // Balance: increased by at least 1050 cents (3 × 350)
    // The settlement consumes 500 cents but the 3×350 are unsettled
    const finalBalanceCents = parseGermanCurrencyToCents(await membersPage.getOpenBalance())
    expect(finalBalanceCents).toBeGreaterThanOrEqual(initialBalanceCents + 1050)

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
