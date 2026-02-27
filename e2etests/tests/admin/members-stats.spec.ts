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
  test('should display correct active members count', async ({ page }) => {
    const membersPage = new MembersPage(page)

    // Arrange: Get baseline count
    await membersPage.navigate()
    await membersPage.expectPageVisible()

    // Wait for stats to load
    await page.waitForTimeout(2000)

    const initialCount = parseInt(await membersPage.getMemberCount(), 10)

    // Create test members (3 active, 2 inactive)
    const timestamp = Date.now()

    // Create 3 active members
    for (let i = 0; i < 3; i++) {
      const uniqueId = `${timestamp}-active-${i}`
      const ibanSuffix = String(Math.floor(Math.random() * 10000000000)).padStart(10, '0')
      const mandateDate = new Date().toISOString().slice(0, 10)

      const response = await page.request.post('http://localhost:8080/api/admin/members', {
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

      const response = await page.request.post('http://localhost:8080/api/admin/members', {
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

    // Act: Reload page to see updated count
    await membersPage.navigate()
    await membersPage.expectPageVisible()

    // Wait for stats to load
    await page.waitForTimeout(2000)

    // Assert: Verify active members count increased by at least 3
    // (may be more if other tests run in parallel)
    const finalCount = parseInt(await membersPage.getMemberCount(), 10)
    expect(finalCount).toBeGreaterThanOrEqual(initialCount + 3)
  })

  test('should display correct outstanding balance', async ({ page }) => {
    const membersPage = new MembersPage(page)

    // Arrange: Get baseline balance
    await membersPage.navigate()
    await membersPage.expectPageVisible()

    // Wait for stats to load
    await page.waitForTimeout(2000)

    const initialBalanceText = await membersPage.getOpenBalance()
    const initialBalanceMatch = initialBalanceText.match(/[\d.,]+/)
    // German number format: 3.843,15 means 3,843.15 (. is thousand separator, , is decimal)
    const initialBalanceStr = initialBalanceMatch
      ? initialBalanceMatch[0].replace(/\./g, '').replace(',', '.')
      : '0'
    const initialBalanceCents = Math.round(parseFloat(initialBalanceStr) * 100)

    // Create member with transactions
    const timestamp = Date.now()
    const uniqueId = `${timestamp}-balance`
    const ibanSuffix = String(Math.floor(Math.random() * 10000000000)).padStart(10, '0')
    const mandateDate = new Date().toISOString().slice(0, 10)

    const memberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: 'Balance',
        last_name: `Test${uniqueId}`,
        email: `balance${uniqueId}@example.com`,
        iban: `DE89370400440532${ibanSuffix}`,
        mandate_reference: `MAND-${uniqueId}`,
        mandate_signed_at: mandateDate,
        preferred_language: 'de',
        is_active: true,
      },
    })
    expect(memberResponse.ok()).toBeTruthy()
    const member = await memberResponse.json()

    // Create category first
    const categoryResponse = await page.request.post('http://localhost:8080/api/admin/categories', {
      data: {
        names: { de: `Category${uniqueId}`, en: `Category${uniqueId}` },
        sort_order: 1,
        is_active: true,
      },
    })
    expect(categoryResponse.ok()).toBeTruthy()
    const category = await categoryResponse.json()

    // Create product
    const productResponse = await page.request.post('http://localhost:8080/api/admin/products', {
      data: {
        names: { de: `Product${uniqueId}`, en: `Product${uniqueId}` },
        price_cents: 350, // 3.50 EUR
        category_id: category.id,
        is_active: true,
      },
    })
    expect(productResponse.ok()).toBeTruthy()
    const product = await productResponse.json()

    // Create 3 transactions: 3.50 EUR each = 10.50 EUR total (1050 cents)
    const transactions = [
      {
        id: crypto.randomUUID(),
        member_id: member.id,
        product_id: product.id,
        amount_cents: 350,
        created_at: new Date().toISOString(),
      },
      {
        id: crypto.randomUUID(),
        member_id: member.id,
        product_id: product.id,
        amount_cents: 350,
        created_at: new Date().toISOString(),
      },
      {
        id: crypto.randomUUID(),
        member_id: member.id,
        product_id: product.id,
        amount_cents: 350,
        created_at: new Date().toISOString(),
      },
    ]

    // Sync transactions require terminal API authentication (Bearer token)
    const syncResponse = await page.request.post('http://localhost:8080/api/sync/transactions', {
      headers: {
        'Authorization': 'Bearer test-terminal-token-do-not-use-in-production-0a1b2c3d4e5f6g7h',
      },
      data: { transactions },
    })

    if (!syncResponse.ok()) {
      const errorBody = await syncResponse.text()
      console.log('Sync transactions failed:', syncResponse.status(), errorBody)
    }

    expect(syncResponse.ok()).toBeTruthy()

    // Act: Reload page to see updated balance
    await membersPage.navigate()
    await membersPage.expectPageVisible()

    // Wait for stats to update
    await page.waitForTimeout(2000)

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

  test('should display last settlement date', async ({ page }) => {
    const membersPage = new MembersPage(page)

    // Arrange: Create settlement with member and transaction
    const timestamp = Date.now()
    const uniqueId = `${timestamp}-settlement`
    const ibanSuffix = String(Math.floor(Math.random() * 10000000000)).padStart(10, '0')
    const mandateDate = new Date().toISOString().slice(0, 10)

    // Create member
    const memberResponse = await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: 'Settlement',
        last_name: `Test${uniqueId}`,
        email: `settlement${uniqueId}@example.com`,
        iban: `DE89370400440532${ibanSuffix}`,
        mandate_reference: `MAND-${uniqueId}`,
        mandate_signed_at: mandateDate,
        preferred_language: 'de',
        is_active: true,
      },
    })
    expect(memberResponse.ok()).toBeTruthy()
    const member = await memberResponse.json()

    // Create category first
    const categoryResponse = await page.request.post('http://localhost:8080/api/admin/categories', {
      data: {
        names: { de: `Category${uniqueId}`, en: `Category${uniqueId}` },
        sort_order: 1,
        is_active: true,
      },
    })
    expect(categoryResponse.ok()).toBeTruthy()
    const category = await categoryResponse.json()

    // Create product
    const productResponse = await page.request.post('http://localhost:8080/api/admin/products', {
      data: {
        names: { de: `Product${uniqueId}`, en: `Product${uniqueId}` },
        price_cents: 500,
        category_id: category.id,
        is_active: true,
      },
    })
    expect(productResponse.ok()).toBeTruthy()
    const product = await productResponse.json()

    // Create transaction
    const transaction = {
      id: crypto.randomUUID(),
      member_id: member.id,
      product_id: product.id,
      amount_cents: 500,
      created_at: new Date().toISOString(),
    }
    // Sync transactions require terminal API authentication (Bearer token)
    const syncResponse = await page.request.post('http://localhost:8080/api/sync/transactions', {
      headers: {
        'Authorization': 'Bearer test-terminal-token-do-not-use-in-production-0a1b2c3d4e5f6g7h',
      },
      data: { transactions: [transaction] },
    })
    expect(syncResponse.ok()).toBeTruthy()

    // Create settlement with the transaction
    const settlementDate = new Date().toISOString().slice(0, 10)
    const executionDate = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10)

    const settlementResponse = await page.request.post('http://localhost:8080/api/admin/settlements', {
      data: {
        transaction_ids: [transaction.id],
        settlement_date: settlementDate,
        execution_date: executionDate,
        settlement_type: 'sepa',
        description: `Test Settlement ${uniqueId}`,
      },
    })

    if (!settlementResponse.ok()) {
      const errorBody = await settlementResponse.text()
      console.log('Settlement creation failed:', settlementResponse.status(), errorBody)
    }

    expect(settlementResponse.ok()).toBeTruthy()

    // Act: Navigate to members page
    await membersPage.navigate()
    await membersPage.expectPageVisible()

    // Wait for stats to load
    await page.waitForTimeout(2000)

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

    // Act: Navigate to members page
    await membersPage.navigate()
    await membersPage.expectPageVisible()

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
