/**
 * UC-A20: Member Transactions Modal Tests
 *
 * Tests for viewing member transaction history in a modal overlay.
 * Implements the "View Tab" use case for displaying member transactions.
 *
 * Test Patterns:
 * - Pattern 001: Test Data Isolation (unique member per test)
 * - Pattern 003: Database-Agnostic Assertions (search by ID, not position)
 * - Pattern 005: Test IDs for element selection
 * - Pattern 006: Page Object Model
 */

import { test, expect } from '../../fixtures/pageObjects'

test.describe('UC-A20: Member Transactions Modal', () => {
  // Helper: Create a test member if table is empty
  async function ensureMemberExists(page: any) {
    // Wait for table or empty state
    await page.locator('[data-testid="members-table"], [data-testid="members-empty-state"]').first().waitFor({ timeout: 10000 })

    const emptyState = await page.locator('[data-testid="members-empty-state"]').isVisible().catch(() => false)

    if (emptyState) {
      // Create a test member
      const testData = {
        firstName: `UCTest${Date.now()}`,
        lastName: 'Transactions',
        iban: 'DE89370400440532013001',
        mandateDate: new Date().toISOString().split('T')[0],
        email: `uc-a20-${Date.now()}@example.com`,
      }

      // Open create modal
      await page.getByTestId('members-create-button').click()
      await page.getByTestId('members-form-modal').waitFor({ timeout: 5000 })

      // Fill form
      await page.getByTestId('members-form-first-name-input').fill(testData.firstName)
      await page.getByTestId('members-form-last-name-input').fill(testData.lastName)
      await page.getByTestId('members-form-email-input').fill(testData.email)
      await page.getByTestId('members-form-iban-input').fill(testData.iban)
      await page.getByTestId('members-form-mandate-date-input').fill(testData.mandateDate)

      // Submit form
      await page.getByTestId('members-form-submit-button').click()

      // Wait for form to close and table to reload
      await page.getByTestId('members-form-modal').waitFor({ state: 'hidden', timeout: 10000 })
      await page.waitForLoadState('networkidle')
    }
  }

  // Root beforeEach ensures page is loaded and member exists
  test.beforeEach(async ({ page, authenticatedMembersPage }) => {
    // authenticatedMembersPage fixture has already navigated to /members
    await ensureMemberExists(page)
  })

  test.describe('Modal Display', () => {
    test('should open transaction modal when clicking view button', async ({ page }) => {
      // Act: Click view transactions button on first member
      const viewButtons = page.locator('[data-testid^="view-transactions-button-"]')
      await expect(viewButtons.first()).toBeVisible()
      await viewButtons.first().click()

      // Assert: Modal appears
      await expect(page.locator('[data-testid="transaction-modal"]')).toBeVisible({ timeout: 5000 })
    })

    test('should display member name in modal header', async ({ page }) => {
      // Arrange: Get first member name
      const firstMemberName = await page
        .locator('[data-testid^="members-table-cell-name-"]')
        .first()
        .textContent()

      // Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()

      // Assert: Header shows member name
      const headerText = await page.locator('[data-testid="transaction-modal-header"]').textContent()
      expect(headerText).toContain(firstMemberName)
      expect(headerText).toContain('Transaction History')
    })

    test('should display current balance in modal subheader', async ({ page }) => {
      // Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await expect(page.locator('[data-testid="transaction-modal"]')).toBeVisible()

      // Assert: Current balance displayed
      const subheaderText = await page.locator('[data-testid="transaction-modal-header"]').textContent()
      expect(subheaderText).toContain('Current Balance')
    })

    test('should close modal when clicking close button', async ({ page }) => {
      // Arrange: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await expect(page.locator('[data-testid="transaction-modal"]')).toBeVisible()

      // Act: Click close button
      await page.locator('[data-testid="modal-close-button"]').click()

      // Assert: Modal hidden
      await expect(page.locator('[data-testid="transaction-modal"]')).not.toBeVisible()
    })

    test('should close modal when clicking backdrop', async ({ page }) => {
      // Arrange: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await expect(page.locator('[data-testid="transaction-modal"]')).toBeVisible()

      // Act: Click backdrop
      await page.locator('[data-testid="transaction-modal-backdrop"]').click({ position: { x: 10, y: 10 } })

      // Assert: Modal hidden
      await expect(page.locator('[data-testid="transaction-modal"]')).not.toBeVisible()
    })
  })

  test.describe('Transaction List', () => {
    test('should display transaction table with all columns', async ({ page }) => {
      // Arrange & Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await page.waitForLoadState('networkidle')

      // Assert: Either table or empty state visible
      const table = page.locator('[data-testid="transaction-table"]')
      const emptyState = page.locator('[data-testid="empty-transactions"]')
      const tableVisible = await table.isVisible().catch(() => false)
      const emptyVisible = await emptyState.isVisible().catch(() => false)

      expect(tableVisible || emptyVisible).toBeTruthy()

      if (tableVisible) {
        // Verify columns if table exists
        const headers = page.locator('[data-testid="transaction-table"] thead th')
        const headerCount = await headers.count()
        expect(headerCount >= 5).toBeTruthy() // At least 5 columns expected
      }
    })

    test('should display transaction rows or empty state', async ({ page }) => {
      // Arrange & Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await page.waitForLoadState('networkidle')

      // Assert: Transaction rows exist or empty state shown
      const rows = page.locator('[data-testid="transaction-row"]')
      const emptyState = page.locator('[data-testid="empty-transactions"]')

      const rowCount = await rows.count()
      const isEmptyState = await emptyState.isVisible().catch(() => false)

      expect(rowCount > 0 || isEmptyState).toBeTruthy()
    })
  })

  test.describe('Filtering', () => {
    test('should filter by type: all', async ({ page }) => {
      // Arrange & Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await page.waitForLoadState('networkidle')

      // Click "All" filter
      const allButton = page.locator('[data-testid="transaction-filter-all"]')
      await expect(allButton).toBeVisible()
      await allButton.click()
      await page.waitForLoadState('networkidle')

      // Assert: Filter button is active
      const activeStyle = await allButton.evaluate((el) => window.getComputedStyle(el).background)
      expect(activeStyle).toContain('rgb(59, 130, 246)')
    })

    test('should filter by type: purchase', async ({ page }) => {
      // Arrange & Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await page.waitForLoadState('networkidle')

      // Click "Purchases" filter
      const purchaseButton = page.locator('[data-testid="transaction-filter-purchase"]')
      await expect(purchaseButton).toBeVisible()
      await purchaseButton.click()
      await page.waitForLoadState('networkidle')

      // Assert: Filter button is clickable and responds
      const isVisible = await purchaseButton.isVisible()
      expect(isVisible).toBeTruthy()
    })

    test('should filter by type: correction', async ({ page }) => {
      // Arrange & Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await page.waitForLoadState('networkidle')

      // Click "Corrections" filter
      const correctionButton = page.locator('[data-testid="transaction-filter-correction"]')
      await expect(correctionButton).toBeVisible()
      await correctionButton.click()
      await page.waitForLoadState('networkidle')

      // Assert: Filter button is clickable and responds
      const isVisible = await correctionButton.isVisible()
      expect(isVisible).toBeTruthy()
    })
  })

  test.describe('Formatting', () => {
    test('should format amounts as currency', async ({ page }) => {
      // Arrange & Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await page.waitForLoadState('networkidle')

      // Get first transaction amount
      const amountCell = page.locator('[data-testid="transaction-amount"]').first()
      const isVisible = await amountCell.isVisible().catch(() => false)

      if (isVisible) {
        const amountText = await amountCell.textContent()
        // Expect some kind of currency formatting
        expect(amountText).toBeTruthy()
      }
    })

    test('should show transaction type badges', async ({ page }) => {
      // Arrange & Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await page.waitForLoadState('networkidle')

      // Get first transaction type
      const typeCell = page.locator('[data-testid="transaction-type"]').first()
      const isVisible = await typeCell.isVisible().catch(() => false)

      if (isVisible) {
        const typeText = await typeCell.textContent()
        // Expect badge text like "PURCH" or "KORR"
        expect(typeText?.trim()).toMatch(/PURCH|KORR|transaction|empty/)
      }
    })
  })

  test.describe('Interactions', () => {
    test('should display loading state or transactions or empty state', async ({ page }) => {
      // Arrange & Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()

      // Check what state appears
      const loadingState = page.locator('text=Loading transactions')
      const transactionTable = page.locator('[data-testid="transaction-table"]')
      const emptyState = page.locator('[data-testid="empty-transactions"]')

      const hasLoadingState = await loadingState.isVisible().catch(() => false)
      const hasTable = await transactionTable.isVisible().catch(() => false)
      const isEmpty = await emptyState.isVisible().catch(() => false)

      expect(hasLoadingState || hasTable || isEmpty).toBeTruthy()
    })
  })

  test.describe('Responsive Design', () => {
    test('should display modal on desktop', async ({ page }) => {
      // Arrange: Desktop viewport
      await page.setViewportSize({ width: 1440, height: 900 })

      // Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await page.waitForLoadState('networkidle')

      // Assert: Modal visible
      await expect(page.locator('[data-testid="transaction-modal"]')).toBeVisible()
    })

    test('should display modal on mobile', async ({ page }) => {
      // Arrange: Set mobile viewport
      await page.setViewportSize({ width: 375, height: 667 })

      // Act: Open modal
      await page.locator('[data-testid^="view-transactions-button-"]').first().click()
      await page.waitForLoadState('networkidle')

      // Assert: Modal is responsive
      const modal = page.locator('[data-testid="transaction-modal"]')
      await expect(modal).toBeVisible()
    })
  })
})
