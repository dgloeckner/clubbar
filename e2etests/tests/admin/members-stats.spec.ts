/**
 * E2E Tests for Members Page Statistics
 *
 * Tests the three stat cards displayed on the members page:
 * - Active members count (Mitglieder)
 * - Outstanding balance (Offene Posten)
 * - Last settlement date (Letzte Abrechnung)
 *
 * Pattern: E2E Pattern 001 (Test Data Isolation)
 * - Each test creates its own isolated data
 * - Tests verify end-to-end flow (API → Backend → Database → UI)
 */

import { test, expect } from '../../fixtures/auth.fixture'

test.describe('Members Page - Statistics', () => {
  test('should display correct active members count', async ({ page, authenticatedRequest }) => {
    // Arrange: Get baseline count
    const request = authenticatedRequest

    await page.goto('http://localhost:5173/members')
    await page.waitForSelector('[data-testid="stat-card-mitglieder"]')

    const initialText = await page.locator('[data-testid="stat-card-mitglieder-value"]').textContent()
    const initialCount = parseInt(initialText || '0', 10)

    // Create test members (3 active, 2 inactive)
    const timestamp = Date.now()

    // Create 3 active members
    for (let i = 0; i < 3; i++) {
      const uniqueId = `${timestamp}-active-${i}`
      const ibanSuffix = String(Math.floor(Math.random() * 10000000000)).padStart(10, '0')
      const mandateDate = new Date().toISOString().slice(0, 10)

      const response = await request.post('/api/admin/members', {
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

      const response = await request.post('/api/admin/members', {
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
    await page.reload()
    await page.waitForSelector('[data-testid="stat-card-mitglieder"]')

    // Assert: Verify active members count increased by exactly 3
    const finalText = await page.locator('[data-testid="stat-card-mitglieder-value"]').textContent()
    const finalCount = parseInt(finalText || '0', 10)

    expect(finalCount).toBe(initialCount + 3)
  })

  test('should display correct outstanding balance', async ({ page, authenticatedRequest }) => {
    // Arrange: Get baseline balance
    const request = authenticatedRequest

    await page.goto('http://localhost:5173/members')
    await page.waitForSelector('[data-testid="stat-card-offene-posten"]')

    const initialBalanceText = await page.locator('[data-testid="stat-card-offene-posten-value"]').textContent()
    const initialBalanceMatch = initialBalanceText?.match(/[\d.,]+/)
    const initialBalanceStr = initialBalanceMatch ? initialBalanceMatch[0].replace(',', '.') : '0'
    const initialBalanceCents = Math.round(parseFloat(initialBalanceStr) * 100)

    // Create member with transactions
    const timestamp = Date.now()
    const uniqueId = `${timestamp}-balance`
    const ibanSuffix = String(Math.floor(Math.random() * 10000000000)).padStart(10, '0')
    const mandateDate = new Date().toISOString().slice(0, 10)

    const memberResponse = await request.post('/api/admin/members', {
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

    // Create product
    const productResponse = await request.post('/api/admin/products', {
      data: {
        names: { de: `Product${uniqueId}`, en: `Product${uniqueId}` },
        price_cents: 350, // 3.50 EUR
        category_id: null,
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

    const syncResponse = await request.post('/api/sync/transactions', {
      data: { transactions },
    })
    expect(syncResponse.ok()).toBeTruthy()

    // Act: Reload page to see updated balance
    await page.reload()
    await page.waitForSelector('[data-testid="stat-card-offene-posten"]')

    // Assert: Verify balance increased by 1050 cents (10.50 EUR)
    const finalBalanceText = await page.locator('[data-testid="stat-card-offene-posten-value"]').textContent()
    const finalBalanceMatch = finalBalanceText?.match(/[\d.,]+/)
    const finalBalanceStr = finalBalanceMatch ? finalBalanceMatch[0].replace(',', '.') : '0'
    const finalBalanceCents = Math.round(parseFloat(finalBalanceStr) * 100)

    expect(finalBalanceCents).toBe(initialBalanceCents + 1050)
  })

  test('should display last settlement date', async ({ page, authenticatedRequest }) => {
    // Arrange: Create settlement with member and transaction
    const request = authenticatedRequest
    const timestamp = Date.now()
    const uniqueId = `${timestamp}-settlement`
    const ibanSuffix = String(Math.floor(Math.random() * 10000000000)).padStart(10, '0')
    const mandateDate = new Date().toISOString().slice(0, 10)

    // Create member
    const memberResponse = await request.post('/api/admin/members', {
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

    // Create product
    const productResponse = await request.post('/api/admin/products', {
      data: {
        names: { de: `Product${uniqueId}`, en: `Product${uniqueId}` },
        price_cents: 500,
        category_id: null,
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
    const syncResponse = await request.post('/api/sync/transactions', {
      data: { transactions: [transaction] },
    })
    expect(syncResponse.ok()).toBeTruthy()

    // Create settlement
    const settlementDate = new Date().toISOString().slice(0, 10)
    const executionDate = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 10)

    const settlementResponse = await request.post('/api/admin/settlements', {
      data: {
        settlement_date: settlementDate,
        execution_date: executionDate,
        description: `Test Settlement ${uniqueId}`,
      },
    })
    expect(settlementResponse.ok()).toBeTruthy()

    // Act: Navigate to members page
    await page.goto('http://localhost:5173/members')
    await page.waitForSelector('[data-testid="stat-card-letzte-abrechnung"]')

    // Assert: Verify last settlement date is displayed
    const settlementDateText = await page.locator('[data-testid="stat-card-letzte-abrechnung-value"]').textContent()

    // Should not be empty or "—"
    expect(settlementDateText).toBeTruthy()
    expect(settlementDateText).not.toBe('—')

    // Should contain the current year
    const expectedYear = new Date().getFullYear().toString()
    expect(settlementDateText).toContain(expectedYear)
  })

  test('should display all three stat cards with proper structure', async ({ page, authenticatedRequest }) => {
    // Act: Navigate to members page
    await page.goto('http://localhost:5173/members')
    await page.waitForSelector('[data-testid="members-stats-grid"]')

    // Assert: Verify all three stat cards are present
    const mitgliederCard = page.locator('[data-testid="stat-card-mitglieder"]')
    const offenePostenCard = page.locator('[data-testid="stat-card-offene-posten"]')
    const letzteAbrechnungCard = page.locator('[data-testid="stat-card-letzte-abrechnung"]')

    await expect(mitgliederCard).toBeVisible()
    await expect(offenePostenCard).toBeVisible()
    await expect(letzteAbrechnungCard).toBeVisible()

    // Verify each card has label and value
    await expect(page.locator('[data-testid="stat-card-mitglieder-label"]')).toBeVisible()
    await expect(page.locator('[data-testid="stat-card-mitglieder-value"]')).toBeVisible()

    await expect(page.locator('[data-testid="stat-card-offene-posten-label"]')).toBeVisible()
    await expect(page.locator('[data-testid="stat-card-offene-posten-value"]')).toBeVisible()

    await expect(page.locator('[data-testid="stat-card-letzte-abrechnung-label"]')).toBeVisible()
    await expect(page.locator('[data-testid="stat-card-letzte-abrechnung-value"]')).toBeVisible()
  })
})
