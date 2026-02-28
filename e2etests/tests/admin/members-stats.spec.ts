/**
 * E2E Tests for Members Page Statistics
 *
 * Tests the three stat cards displayed on the members page:
 * - Active members count (Mitglieder)
 * - Outstanding balance (Offene Posten)
 * - Last settlement date (Letzte Abrechnung)
 *
 * Pattern: E2E Pattern 001 (Test Data Isolation)
 * Pattern: E2E Pattern 006 (Page Object Model)
 * Pattern: E2E Pattern 007 (Page Object Fixtures)
 * - Each test creates its own isolated data
 * - Tests verify end-to-end flow (API → Backend → Database → UI)
 * - Uses Page Object Model for all UI interactions
 * - Uses authenticated fixtures for page objects and API requests
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { MembersPage } from '../../pages/MembersPage'

test.describe('Members Page - Statistics', () => {
  test('should display correct active members count', async ({ page, authenticatedRequest }) => {
    const membersPage = new MembersPage(page)

    // Arrange: Get baseline count (register watcher BEFORE navigate)
    const dashboardRespBefore = page.waitForResponse(
      (r) => r.url().includes('/api/admin/dashboard') && r.status() === 200,
      { timeout: 10000 }
    )
    await membersPage.navigate()
    await membersPage.expectPageVisible()
    await dashboardRespBefore
    await membersPage.waitForStatsToLoad()

    const initialCount = parseInt(await membersPage.getMemberCount(), 10)

    // Create test members (3 active, 2 inactive)
    const timestamp = Date.now()

    // Create 3 active members
    for (let i = 0; i < 3; i++) {
      const uniqueId = `${timestamp}-active-${i}`
      const ibanSuffix = String(Math.floor(Math.random() * 10000000000)).padStart(10, '0')
      const mandateDate = new Date().toISOString().slice(0, 10)

      const response = await authenticatedRequest.post('/api/admin/members', {
        data: {
          first_name: `Active${i}`,
          last_name: `Member${uniqueId}`,
          email: `active${uniqueId}@example.com`,
          iban: `DE89370400440532${ibanSuffix}`,
          mandate_reference: `MAND-${uniqueId}`,
          mandate_signed_at: mandateDate,
          preferred_language: 'de',
          is_active: true,
        },
      })
      expect(response.ok()).toBeTruthy()
    }

    // Create 2 inactive members (should NOT affect count)
    for (let i = 0; i < 2; i++) {
      const uniqueId = `${timestamp}-inactive-${i}`
      const ibanSuffix = String(Math.floor(Math.random() * 10000000000)).padStart(10, '0')
      const mandateDate = new Date().toISOString().slice(0, 10)

      const response = await authenticatedRequest.post('/api/admin/members', {
        data: {
          first_name: `Inactive${i}`,
          last_name: `Member${uniqueId}`,
          email: `inactive${uniqueId}@example.com`,
          iban: `DE89370400440532${ibanSuffix}`,
          mandate_reference: `MAND-${uniqueId}`,
          mandate_signed_at: mandateDate,
          preferred_language: 'de',
          is_active: false,
        },
      })
      expect(response.ok()).toBeTruthy()
    }

    // Act: Reload page — register watcher BEFORE navigate
    const dashboardRespAfter = page.waitForResponse(
      (r) => r.url().includes('/api/admin/dashboard') && r.status() === 200,
      { timeout: 10000 }
    )
    await membersPage.navigate()
    await membersPage.expectPageVisible()
    await dashboardRespAfter
    await membersPage.waitForStatsToLoad()

    // Assert: Verify active members count increased by at least 3
    // (may be more if other tests run in parallel)
    const finalCount = parseInt(await membersPage.getMemberCount(), 10)
    expect(finalCount).toBeGreaterThanOrEqual(initialCount + 3)
  })

  test('should display correct outstanding balance', async ({ page, testTransactions }) => {
    const membersPage = new MembersPage(page)

    // Arrange: Get baseline balance
    const dashboardRespBefore = page.waitForResponse(
      (r) => r.url().includes('/api/admin/dashboard') && r.status() === 200,
      { timeout: 10000 }
    )
    await membersPage.navigate()
    await membersPage.expectPageVisible()
    await dashboardRespBefore
    await membersPage.waitForStatsToLoad()

    const initialBalanceText = await membersPage.getOpenBalance()
    const initialBalanceMatch = initialBalanceText.match(/[\d.,]+/)
    // German number format: 3.843,15 means 3,843.15 (. is thousand separator, , is decimal)
    const initialBalanceStr = initialBalanceMatch
      ? initialBalanceMatch[0].replace(/\./g, '').replace(',', '.')
      : '0'
    const initialBalanceCents = Math.round(parseFloat(initialBalanceStr) * 100)

    // Create member, product, and 3 transactions: 3.50 EUR each = 10.50 EUR total (1050 cents)
    const member = await testTransactions.createMember('Balance', 'Test')
    const product = await testTransactions.createProduct('TestProd', 350, 'Test Product')

    for (let i = 0; i < 3; i++) {
      await testTransactions.createSyncTransaction(member.id, 350, 'balance-test', product.id)
    }

    // Act: Reload page — register watcher BEFORE navigate
    const dashboardRespAfter = page.waitForResponse(
      (r) => r.url().includes('/api/admin/dashboard') && r.status() === 200,
      { timeout: 10000 }
    )
    await membersPage.navigate()
    await membersPage.expectPageVisible()
    await dashboardRespAfter
    await membersPage.waitForStatsToLoad()

    // Assert: Verify balance increased by at least 1050 cents (10.50 EUR)
    // (may be more if other tests run in parallel)
    const finalBalanceText = await membersPage.getOpenBalance()
    const finalBalanceMatch = finalBalanceText.match(/[\d.,]+/)
    // German number format: 3.843,15 means 3,843.15 (. is thousand separator, , is decimal)
    const finalBalanceStr = finalBalanceMatch
      ? finalBalanceMatch[0].replace(/\./g, '').replace(',', '.')
      : '0'
    const finalBalanceCents = Math.round(parseFloat(finalBalanceStr) * 100)

    expect(finalBalanceCents).toBeGreaterThanOrEqual(initialBalanceCents + 1050)
  })

  test('should display last settlement date', async ({ page, testTransactions }) => {
    const membersPage = new MembersPage(page)

    // Arrange: Create member, product, transaction, and settlement
    const member = await testTransactions.createMember('Settlement', 'Test')
    const product = await testTransactions.createProduct('SettleProd', 500, 'Settle Product')
    const txnId = await testTransactions.createSyncTransaction(member.id, 500, 'settle-test', product.id)
    await testTransactions.createSettlement([txnId])

    // Act: Navigate to members page — register watcher BEFORE navigate
    const dashboardResp = page.waitForResponse(
      (r) => r.url().includes('/api/admin/dashboard') && r.status() === 200,
      { timeout: 10000 }
    )
    await membersPage.navigate()
    await membersPage.expectPageVisible()
    await dashboardResp
    await membersPage.waitForStatsToLoad()

    // Assert: Verify last settlement date is displayed
    const settlementDateText = await membersPage.getLastSettlementDate()

    // Should not be empty or "—"
    expect(settlementDateText).toBeTruthy()
    expect(settlementDateText).not.toBe('—')

    // Should contain the current year
    const expectedYear = new Date().getFullYear().toString()
    expect(settlementDateText).toContain(expectedYear)
  })

  test('should display all three stat cards with proper structure', async ({ page }) => {
    const membersPage = new MembersPage(page)

    // Register dashboard response watcher BEFORE navigation (Pattern 008: register before trigger)
    const dashboardResponsePromise = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/dashboard') && resp.status() === 200,
      { timeout: 10000 }
    )

    // Act: Navigate to members page
    await membersPage.navigate()
    await membersPage.expectPageVisible()

    // Wait for dashboard API to load stat card data (async, fires after page mount)
    await dashboardResponsePromise
    await membersPage.waitForStatsToLoad()

    // Assert: All three stat values should be retrievable (cards are visible)
    const memberCount = await membersPage.getMemberCount()
    const openBalance = await membersPage.getOpenBalance()
    const lastSettlement = await membersPage.getLastSettlementDate()

    // Member count should be a positive number (seeded data has 8 active members)
    expect(parseInt(memberCount, 10)).toBeGreaterThanOrEqual(1)

    // Open balance should contain currency symbol or number
    expect(openBalance).toMatch(/[\d.,€]/)

    // Last settlement should be a string (may be "—" if no settlements exist)
    expect(typeof lastSettlement).toBe('string')
  })
})
