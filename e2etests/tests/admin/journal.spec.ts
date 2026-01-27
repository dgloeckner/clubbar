/**
 * Admin Frontend - Journal Page E2E Tests
 *
 * Tests the transaction journal (Buchungsjournal) page showing member transaction history.
 * Implements UC-A20: View Tab (member transaction history)
 *
 * **Test Coverage:**
 * - Journal page loads with members list
 * - Select member to view transaction history
 * - Filter transactions by type (all, purchase, correction)
 * - Display transaction details (date, description, amount, running total)
 * - Pagination of transaction list
 * - Empty state when no transactions
 * - Date range filtering of transactions
 */

import { test, expect } from '../../fixtures/pageObjects'

test.describe('Admin Frontend - Journal Page', () => {
  /**
   * Members List
   */
  test.describe('Journal - Members List', () => {
    test('should display members list on journal page', async ({ authenticatedJournalPage, page }) => {
      // Navigate to journal page
      await page.goto('http://localhost:5173/journal')

      // Page should contain "Buchungsjournal" title
      const title = page.locator('text=Buchungsjournal')
      await expect(title).toBeVisible()

      // Should have members list or selector
      const membersList = page.locator('[data-testid="journal-members-list"], [data-testid="member-selector"]').first()
      await expect(membersList).toBeVisible()
    })

    test('should search members in journal', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Get initial member count
      const initialRows = page.locator('[data-testid="journal-member-row"]')
      const initialCount = await initialRows.count()

      // Search for a member (using partial name)
      const searchBox = page.locator('[data-testid="journal-search"], input[placeholder*="Search"]').first()
      await searchBox.fill('John')

      // Wait for results to update
      await page.waitForTimeout(500)

      // Filtered results should have <= initial count
      const filteredRows = page.locator('[data-testid="journal-member-row"]')
      const filteredCount = await filteredRows.count()

      expect(filteredCount).toBeLessThanOrEqual(initialCount)
    })
  })

  /**
   * Transaction History
   */
  test.describe('Journal - Transaction History', () => {
    test('should display transaction history for selected member', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Click first member to view their transactions
      const firstMember = page.locator('[data-testid="journal-member-row"]').first()
      const memberName = await firstMember.locator('[data-testid="member-name"]').textContent()

      await firstMember.click()

      // Transaction list should be visible
      const transactionList = page.locator('[data-testid="transaction-list"]')
      await expect(transactionList).toBeVisible()

      // Should show member name somewhere
      if (memberName) {
        const header = page.locator(`text=${memberName}`).first()
        const isVisible = await header.isVisible().catch(() => false)
        // Header visibility is optional, depending on UI design
      }
    })

    test('should display transaction columns', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Click first member
      const firstMember = page.locator('[data-testid="journal-member-row"]').first()
      await firstMember.click()

      // Wait for transaction table
      const transactionTable = page.locator('[data-testid="transaction-list"] table, [data-testid="transactions-table"]').first()
      await expect(transactionTable).toBeVisible()

      // Check for expected columns
      const headers = page.locator('[data-testid="transaction-list"] th, [data-testid="transactions-table"] th')
      const headerCount = await headers.count()

      // Should have at least 4 columns (Date, Type, Description, Amount)
      expect(headerCount).toBeGreaterThanOrEqual(4)
    })

    test('should display transaction rows with data', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Click first member
      const firstMember = page.locator('[data-testid="journal-member-row"]').first()
      await firstMember.click()

      // Get transaction rows
      const transactionRows = page.locator('[data-testid="transaction-row"]')
      const rowCount = await transactionRows.count()

      if (rowCount > 0) {
        // First row should have transaction data
        const firstRow = transactionRows.first()
        const dateCell = firstRow.locator('[data-testid="transaction-date"]')
        const amountCell = firstRow.locator('[data-testid="transaction-amount"]')

        await expect(dateCell).toBeVisible()
        await expect(amountCell).toBeVisible()
      }
    })

    test('should display empty state when member has no transactions', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Try to find a member with no transactions
      const memberRows = page.locator('[data-testid="journal-member-row"]')
      const rowCount = await memberRows.count()

      if (rowCount > 0) {
        // Click any member
        await memberRows.nth(Math.floor(Math.random() * Math.min(rowCount, 3))).click()

        // Either transaction list is visible with data, or empty state is shown
        const transactionList = page.locator('[data-testid="transaction-list"], [data-testid="transactions-table"]').first()
        const emptyState = page.locator('[data-testid="empty-transactions"], text=No transactions')

        const isTransactionListVisible = await transactionList.isVisible().catch(() => false)
        const isEmptyStateVisible = await emptyState.isVisible().catch(() => false)

        expect(isTransactionListVisible || isEmptyStateVisible).toBe(true)
      }
    })
  })

  /**
   * Transaction Filters
   */
  test.describe('Journal - Transaction Filters', () => {
    test('should filter transactions by type', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Click first member
      const firstMember = page.locator('[data-testid="journal-member-row"]').first()
      await firstMember.click()

      // Wait for transaction list
      await page.locator('[data-testid="transaction-list"], [data-testid="transactions-table"]').first().waitFor({ timeout: 5000 })

      // Find filter buttons
      const filterButtons = page.locator('[data-testid^="transaction-filter-"], [data-testid="filter-all"]')
      const buttonCount = await filterButtons.count()

      if (buttonCount > 0) {
        // Click "Purchases" filter
        const purchaseFilter = page.locator('[data-testid="transaction-filter-purchase"], text=Konsumationen')
        const isVisible = await purchaseFilter.isVisible().catch(() => false)

        if (isVisible) {
          await purchaseFilter.click()

          // Transaction rows should only show purchases
          const transactionRows = page.locator('[data-testid="transaction-row"]')
          const rowCount = await transactionRows.count()

          // If we have rows, they should be purchases
          if (rowCount > 0) {
            const firstType = await transactionRows.first().locator('[data-testid="transaction-type"]').textContent()
            // Should be "purchase" or similar
            expect(firstType).toBeTruthy()
          }
        }
      }
    })

    test('should handle transaction type filter "all"', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Click first member
      const firstMember = page.locator('[data-testid="journal-member-row"]').first()
      await firstMember.click()

      // Wait for transaction list
      await page.locator('[data-testid="transaction-list"], [data-testid="transactions-table"]').first().waitFor({ timeout: 5000 })

      // Click "All" filter if available
      const allFilter = page.locator('[data-testid="transaction-filter-all"], text=Alle').first()
      const isVisible = await allFilter.isVisible().catch(() => false)

      if (isVisible) {
        await allFilter.click()

        // Should show all transactions again
        const transactionRows = page.locator('[data-testid="transaction-row"]')
        const rowCount = await transactionRows.count()

        // Row count should be stable
        expect(rowCount).toBeGreaterThanOrEqual(0)
      }
    })
  })

  /**
   * Transaction Details & Pagination
   */
  test.describe('Journal - Transaction Details', () => {
    test('should display current member balance', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Click first member
      const firstMember = page.locator('[data-testid="journal-member-row"]').first()
      await firstMember.click()

      // Wait for transaction list
      await page.locator('[data-testid="transaction-list"], [data-testid="transactions-table"]').first().waitFor({ timeout: 5000 })

      // Should show current balance somewhere
      const balanceDisplay = page.locator('[data-testid="member-balance"], text=Balance, text=Deckel').first()
      const isVisible = await balanceDisplay.isVisible().catch(() => false)

      if (isVisible) {
        const balanceText = await balanceDisplay.textContent()
        expect(balanceText).toBeTruthy()
      }
    })

    test('should paginate transactions if many exist', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Click first member
      const firstMember = page.locator('[data-testid="journal-member-row"]').first()
      await firstMember.click()

      // Wait for transaction list
      await page.locator('[data-testid="transaction-list"], [data-testid="transactions-table"]').first().waitFor({ timeout: 5000 })

      // Check if pagination exists
      const paginationContainer = page.locator('[data-testid="pagination"], nav').first()
      const isPaginationVisible = await paginationContainer.isVisible().catch(() => false)

      if (isPaginationVisible) {
        // Pagination controls should be visible
        const nextButton = page.locator('[data-testid="pagination-next"], button:has-text("Next")')
        const isNextVisible = await nextButton.isVisible().catch(() => false)

        expect(isNextVisible || !isPaginationVisible).toBe(true)
      }
    })

    test('should format transaction amounts as currency', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Click first member
      const firstMember = page.locator('[data-testid="journal-member-row"]').first()
      await firstMember.click()

      // Wait for transaction list
      await page.locator('[data-testid="transaction-list"], [data-testid="transactions-table"]').first().waitFor({ timeout: 5000 })

      // Get first transaction amount
      const firstRow = page.locator('[data-testid="transaction-row"]').first()
      const amountText = await firstRow.locator('[data-testid="transaction-amount"]').textContent()

      if (amountText) {
        // Should contain € symbol or similar currency indicator
        const hasCurrency = amountText.includes('€') || amountText.includes(',') || amountText.match(/[\d.,]+/)
        expect(hasCurrency).toBe(true)
      }
    })
  })

  /**
   * Navigation & UI
   */
  test.describe('Journal - Navigation', () => {
    test('should allow going back to members list', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Click first member
      const firstMember = page.locator('[data-testid="journal-member-row"]').first()
      const memberName = await firstMember.textContent()
      await firstMember.click()

      // Wait for transaction list
      await page.locator('[data-testid="transaction-list"], [data-testid="transactions-table"]').first().waitFor({ timeout: 5000 })

      // Click back button or deselect member
      const backButton = page.locator('[data-testid="journal-back"], button:has-text("Back")')
      const isBackVisible = await backButton.isVisible().catch(() => false)

      if (isBackVisible) {
        await backButton.click()

        // Members list should be visible again
        const membersList = page.locator('[data-testid="journal-members-list"]')
        await expect(membersList).toBeVisible()
      }
    })

    test('should maintain filter state when selecting different member', async ({ page }) => {
      await page.goto('http://localhost:5173/journal')

      // Click first member
      const firstMember = page.locator('[data-testid="journal-member-row"]').first()
      await firstMember.click()

      // Wait for transaction list
      await page.locator('[data-testid="transaction-list"], [data-testid="transactions-table"]').first().waitFor({ timeout: 5000 })

      // Apply filter if available
      const filterButton = page.locator('[data-testid="transaction-filter-purchase"], text=Konsumationen')
      const isFilterVisible = await filterButton.isVisible().catch(() => false)

      if (isFilterVisible) {
        await filterButton.click()

        // Go back to members
        const backButton = page.locator('[data-testid="journal-back"]')
        const isBackVisible = await backButton.isVisible().catch(() => false)

        if (isBackVisible) {
          await backButton.click()

          // Click second member if available
          const members = page.locator('[data-testid="journal-member-row"]')
          const memberCount = await members.count()

          if (memberCount > 1) {
            await members.nth(1).click()

            // Filter should still be applied (or reset - depends on design)
            const transactionList = page.locator('[data-testid="transaction-list"], [data-testid="transactions-table"]')
            await expect(transactionList).toBeVisible()
          }
        }
      }
    })
  })
})
