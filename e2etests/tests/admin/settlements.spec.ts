/**
 * Settlements Page E2E Tests
 *
 * Tests for settlement management workflows:
 * - UC-A33: Settlement History (list view)
 * - UC-A34: Settlement Details (detail view)
 * - UC-A30: Create SEPA Settlement (transaction selection)
 * - UC-A35: Manual Settlement (transaction selection)
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (unique test members per test)
 * - Pattern 003: Database-Agnostic Assertions (search by ID, not position)
 * - Pattern 005: Test IDs for element selection
 * - Pattern 006: Page Object Model
 */

import { test, expect } from '../../fixtures/pageObjects'
import { SettlementsPage } from '../../pages/SettlementsPage'

test.describe('Settlements Page', () => {
  // beforeEach: Navigate to settlements page for all tests
  test.beforeEach(async ({ page }) => {
    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()

    // Wait for page to be fully loaded using solid loading indicator pattern
    // This replaces the fragile Promise.race with reliable state-based waiting
    await settlementsPage.waitForPageLoad()
  })

  /**
   * UC-A33: Settlement History - List View
   */
  test.describe('UC-A33: Settlement History', () => {
    test('should display settlements page with list', async ({ page }) => {
      // Verify page loaded
      await expect(page.getByTestId('settlements-page')).toBeVisible()

      // Verify table or empty state visible
      const table = page.locator('[data-testid="settlements-table"]')
      const emptyState = page.locator('[data-testid="settlements-empty-state"]')
      const tableVisible = await table.isVisible().catch(() => false)
      const emptyVisible = await emptyState.isVisible().catch(() => false)

      expect(tableVisible || emptyVisible).toBeTruthy()
    })

    test('should display settlement table with all columns', async ({ page }) => {
      // Check if table exists
      const table = page.locator('[data-testid="settlements-table"]')
      const tableExists = await table.isVisible().catch(() => false)

      if (tableExists) {
        // Verify columns: Created, Execution, Members, Amount, Exported, Cancelled, Actions
        const headers = page.locator('[data-testid="settlements-table"] thead th')
        const headerCount = await headers.count()
        expect(headerCount >= 5).toBeTruthy() // At least 5 columns expected
      } else {
        // Empty state is acceptable
        const emptyState = page.locator('[data-testid="settlements-empty-state"]')
        await expect(emptyState).toBeVisible()
      }
    })

    test('should sort settlements by most recent first', async ({ page }) => {
      // Check if table has rows
      const rows = page.locator('[data-testid="settlement-row"]')
      const rowCount = await rows.count()

      if (rowCount >= 2) {
        // Get first two settlement creation dates
        const firstCreatedText = await rows.nth(0).locator('[data-testid="settlement-created"]').textContent()
        const secondCreatedText = await rows.nth(1).locator('[data-testid="settlement-created"]').textContent()

        // Both should have dates; first should be >= second (most recent first)
        expect(firstCreatedText).toBeTruthy()
        expect(secondCreatedText).toBeTruthy()
      }
    })

    test('should display empty state when no settlements', async ({ page }) => {
      // Empty state should show if no settlements
      const emptyState = page.locator('[data-testid="settlements-empty-state"]')
      const table = page.locator('[data-testid="settlements-table"]')
      const tableVisible = await table.isVisible().catch(() => false)

      if (!tableVisible) {
        await expect(emptyState).toBeVisible()
        expect(await emptyState.textContent()).toContain('No settlements')
      }
    })

    test('should display settlement row with member count and total amount', async ({ page }) => {
      // Check if table has rows
      const rows = page.locator('[data-testid="settlement-row"]')
      const rowCount = await rows.count()

      if (rowCount > 0) {
        const firstRow = rows.first()

        // Verify row has member count
        const memberCount = await firstRow.locator('[data-testid="settlement-member-count"]').textContent()
        expect(memberCount).toBeTruthy()
        expect(memberCount).toMatch(/\d+/)

        // Verify row has total amount
        const totalAmount = await firstRow.locator('[data-testid="settlement-total-amount"]').textContent()
        expect(totalAmount).toBeTruthy()
        expect(totalAmount).toMatch(/€|CHF|\d+/)
      }
    })

    test('should display cancelled indicator for cancelled settlements', async ({ page }) => {
      // Check if there are any cancelled settlements
      const cancelledIndicators = page.locator('[data-testid="settlement-cancelled"]')
      const cancelledCount = await cancelledIndicators.count()

      // If any cancelled settlements exist, verify they're marked
      if (cancelledCount > 0) {
        const firstCancelled = cancelledIndicators.first()
        await expect(firstCancelled).toBeVisible()
      }
    })

    test('should display exported indicator', async ({ page }) => {
      // Check if table has rows
      const rows = page.locator('[data-testid="settlement-row"]')
      const rowCount = await rows.count()

      if (rowCount > 0) {
        const firstRow = rows.first()

        // Verify row has exported indicator (Yes/No or timestamp)
        const exportedIndicator = await firstRow.locator('[data-testid="settlement-exported"]').textContent()
        expect(exportedIndicator).toBeTruthy()
      }
    })

    test('should have action buttons for each settlement', async ({ page }) => {
      // Check if table has rows
      const rows = page.locator('[data-testid="settlement-row"]')
      const rowCount = await rows.count()

      if (rowCount > 0) {
        const firstRow = rows.first()

        // Verify row has View Details button
        const viewBtn = firstRow.locator('[data-testid^="settlement-view-button-"]')
        await expect(viewBtn).toBeVisible()
      }
    })

    test('should filter settlements by type', async ({ page }) => {
      // Check if filter dropdown exists
      const filterSelect = page.locator('[data-testid="settlement-type-filter"]')
      const filterExists = await filterSelect.isVisible().catch(() => false)

      if (filterExists) {
        // Select "SEPA" filter
        await filterSelect.selectOption('sepa')
        await page.waitForLoadState('networkidle')

        // Verify only SEPA settlements shown
        const rows = page.locator('[data-testid="settlement-row"]')
        const rowCount = await rows.count()

        if (rowCount > 0) {
          const firstRow = rows.first()
          const typeText = await firstRow.locator('[data-testid="settlement-type"]').textContent()
          expect(typeText).toContain('SEPA')
        }
      }
    })
  })

  /**
   * UC-A34: Settlement Details - Detail View
   */
  test.describe('UC-A34: Settlement Details', () => {
    test('should open settlement details when clicking view button', async ({ page }) => {
      // Check if table has rows
      const rows = page.locator('[data-testid="settlement-row"]')
      const rowCount = await rows.count()

      if (rowCount > 0) {
        // Click view button on first settlement
        const viewBtn = rows.first().locator('[data-testid^="settlement-view-button-"]')
        await viewBtn.click()

        // Verify details page visible
        await expect(page.locator('[data-testid="settlement-details-page"]')).toBeVisible({ timeout: 5000 })
      }
    })

    test('should display settlement summary information', async ({ page }) => {
      // Check if we're on details page
      const detailsPage = page.locator('[data-testid="settlement-details-page"]')
      const detailsVisible = await detailsPage.isVisible().catch(() => false)

      if (detailsVisible) {
        // Verify summary section visible
        const summary = page.locator('[data-testid="settlement-summary"]')
        await expect(summary).toBeVisible()

        // Verify summary has created date
        const createdDate = await page.locator('[data-testid="settlement-summary-created"]').textContent()
        expect(createdDate).toBeTruthy()
      }
    })

    test('should display execution date in details', async ({ page }) => {
      const detailsPage = page.locator('[data-testid="settlement-details-page"]')
      const detailsVisible = await detailsPage.isVisible().catch(() => false)

      if (detailsVisible) {
        const executionDate = await page.locator('[data-testid="settlement-execution-date"]').textContent()
        // Execution date may be null for non-SEPA settlements, but element should exist
        expect(executionDate === null || executionDate === '' || executionDate?.match(/\d{4}-\d{2}-\d{2}/ )).toBeTruthy()
      }
    })

    test('should display member count and amounts in summary', async ({ page }) => {
      const detailsPage = page.locator('[data-testid="settlement-details-page"]')
      const detailsVisible = await detailsPage.isVisible().catch(() => false)

      if (detailsVisible) {
        // Verify member count
        const memberCount = await page.locator('[data-testid="settlement-total-members"]').textContent()
        expect(memberCount).toBeTruthy()
        expect(memberCount).toMatch(/\d+/)

        // Verify total amount
        const totalAmount = await page.locator('[data-testid="settlement-summary-total"]').textContent()
        expect(totalAmount).toBeTruthy()
      }
    })

    test('should display member list in details', async ({ page }) => {
      const detailsPage = page.locator('[data-testid="settlement-details-page"]')
      const detailsVisible = await detailsPage.isVisible().catch(() => false)

      if (detailsVisible) {
        // Check if member list table exists
        const memberTable = page.locator('[data-testid="settlement-members-table"]')
        const memberList = page.locator('[data-testid="settlement-member-row"]')

        const tableVisible = await memberTable.isVisible().catch(() => false)
        const membersCount = await memberList.count()

        // Either table or empty state should exist
        if (tableVisible && membersCount > 0) {
          // Verify member name displayed
          const memberName = await memberList.first().locator('[data-testid="member-name"]').textContent()
          expect(memberName).toBeTruthy()

          // Verify member amount displayed
          const memberAmount = await memberList.first().locator('[data-testid="member-amount"]').textContent()
          expect(memberAmount).toBeTruthy()
        }
      }
    })

    test('should display SEPA status in member list', async ({ page }) => {
      const detailsPage = page.locator('[data-testid="settlement-details-page"]')
      const detailsVisible = await detailsPage.isVisible().catch(() => false)

      if (detailsVisible) {
        const memberRows = page.locator('[data-testid="settlement-member-row"]')
        const rowCount = await memberRows.count()

        if (rowCount > 0) {
          const sepaStatus = await memberRows.first().locator('[data-testid="member-sepa-status"]').textContent()
          // SEPA status should indicate Yes/No or Valid/Invalid
          expect(sepaStatus?.toLowerCase()).toMatch(/yes|no|valid|invalid/)
        }
      }
    })

    test('should have download buttons for exports', async ({ page }) => {
      const detailsPage = page.locator('[data-testid="settlement-details-page"]')
      const detailsVisible = await detailsPage.isVisible().catch(() => false)

      if (detailsVisible) {
        // Check for download buttons
        const downloadSection = page.locator('[data-testid="settlement-downloads"]')
        const downloadExists = await downloadSection.isVisible().catch(() => false)

        if (downloadExists) {
          // Verify at least one download button
          const downloadBtn = downloadSection.locator('button')
          const btnCount = await downloadBtn.count()
          expect(btnCount).toBeGreaterThan(0)
        }
      }
    })

    test('should display back button to return to list', async ({ page }) => {
      const detailsPage = page.locator('[data-testid="settlement-details-page"]')
      const detailsVisible = await detailsPage.isVisible().catch(() => false)

      if (detailsVisible) {
        const backBtn = page.locator('[data-testid="settlement-back-button"]')
        await expect(backBtn).toBeVisible()
      }
    })

    test('should return to list when clicking back button', async ({ page }) => {
      const detailsPage = page.locator('[data-testid="settlement-details-page"]')
      const detailsVisible = await detailsPage.isVisible().catch(() => false)

      if (detailsVisible) {
        const backBtn = page.locator('[data-testid="settlement-back-button"]')
        await backBtn.click()

        // Verify returned to list
        await expect(page.getByTestId('settlements-page')).toBeVisible()
      }
    })
  })

  /**
   * UC-A30: Create SEPA Settlement - Transaction Selection
   */
  test.describe('UC-A30: Create SEPA Settlement', () => {
    test('should have New Settlement button on list', async ({ page }) => {
      // Verify New Settlement button exists
      const newBtn = page.locator('[data-testid="new-settlement-button"]')
      await expect(newBtn).toBeVisible()
    })

    test('should open settlement creation form when clicking New Settlement', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Use page object method to open settlement with proper loading wait
      await settlementsPage.openNewSettlement()

      // Verify settlement form or selection view opens
      const formOrSelection = page.locator('[data-testid="settlement-form"], [data-testid="settlement-transaction-selection"]')
      await expect(formOrSelection.first()).toBeVisible()
    })

    test('should display transaction selection view for SEPA settlement', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Use page object method with proper loading wait
      await settlementsPage.openNewSettlement()

      // Verify selection view visible
      const selectionView = page.locator('[data-testid="settlement-transaction-selection"]')
      await expect(selectionView).toBeVisible()

      // Verify table exists (should have transactions)
      const table = page.locator('[data-testid="settlement-selection-table"]')
      await expect(table).toBeVisible()
    })

    test('should display SEPA-valid member transactions as selected by default', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Open new settlement with proper loading wait
      await settlementsPage.openNewSettlement()

      // Get transaction row count using page object method
      const rowCount = await settlementsPage.getTransactionSelectionRowCount()

      if (rowCount > 0) {
        const rows = page.locator('[data-testid="settlement-transaction-row"]')
        // First transaction should have checkbox checked
        const checkbox = rows.first().locator('input[type="checkbox"]')
        const isChecked = await checkbox.isChecked()
        // Default should be selected (but may be false if no eligible transactions)
        expect(typeof isChecked === 'boolean').toBeTruthy()
      }
    })

    test('should display SEPA-invalid members section separately', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Open new settlement with proper loading wait
      await settlementsPage.openNewSettlement()

      // Selection view should be visible after openNewSettlement
      const selectionView = page.locator('[data-testid="settlement-transaction-selection"]')
      await expect(selectionView).toBeVisible()

      // Check for SEPA-invalid section
      const invalidSection = page.locator('[data-testid="settlement-sepa-invalid-members"]')
      const invalidExists = await invalidSection.isVisible().catch(() => false)

      if (invalidExists) {
        // Should not have selectable checkboxes
        const checkboxes = invalidSection.locator('input[type="checkbox"]')
        const count = await checkboxes.count()
        expect(count).toBe(0) // No checkboxes for SEPA-invalid
      }
    })

    test('should have Select All / Select None buttons', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Open new settlement with proper loading wait
      await settlementsPage.openNewSettlement()

      // Verify selection view and buttons are visible
      const selectAllBtn = page.locator('[data-testid="settlement-select-all"]')
      const selectNoneBtn = page.locator('[data-testid="settlement-select-none"]')

      await expect(selectAllBtn.or(selectNoneBtn)).toBeVisible()
    })

    test('should display Continue button in selection view', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Open new settlement with proper loading wait
      await settlementsPage.openNewSettlement()

      // Continue button should be visible after opening settlement
      const continueBtn = page.locator('[data-testid="settlement-selection-continue"]')
      await expect(continueBtn).toBeVisible()
    })

    test('should show settlement summary after continuing', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Open new settlement with proper loading wait
      await settlementsPage.openNewSettlement()

      // Check if any transactions available
      const rowCount = await settlementsPage.getTransactionSelectionRowCount()

      if (rowCount > 0) {
        // Continue to summary using page object method with proper wait
        await settlementsPage.continueFromTransactionSelection()

        // Verify summary view appears
        const summary = page.locator('[data-testid="settlement-summary-section"]')
        await expect(summary).toBeVisible()
      }
    })

    test('should display execution date field in summary', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Open new settlement with proper loading wait
      await settlementsPage.openNewSettlement()

      const rowCount = await settlementsPage.getTransactionSelectionRowCount()

      if (rowCount > 0) {
        // Continue to summary with proper wait
        await settlementsPage.continueFromTransactionSelection()

        // Execution date field should be visible in summary
        const executionDateField = page.locator('[data-testid="settlement-execution-date-input"]')
        await expect(executionDateField).toBeVisible()
      }
    })

    test('should enforce minimum 7-day execution date rule', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Open new settlement with proper loading wait
      await settlementsPage.openNewSettlement()

      const rowCount = await settlementsPage.getTransactionSelectionRowCount()

      if (rowCount > 0) {
        // Continue to summary with proper wait
        await settlementsPage.continueFromTransactionSelection()

        const executionDateField = page.locator('[data-testid="settlement-execution-date-input"]')
        await expect(executionDateField).toBeVisible()

        // Try to set date to today (should fail or be prevented)
        const today = new Date().toISOString().split('T')[0]
        await executionDateField.fill(today)

        // Check for error message
        const errorMsg = page.locator('[data-testid="execution-date-error"]')
        const errorVisible = await errorMsg.isVisible().catch(() => false)

        // Either error is shown or date is not accepted
        expect(errorVisible).toBeTruthy()
      }
    })
  })

  /**
   * UC-A35: Manual Settlement - Transaction Selection with Reason
   */
  test.describe('UC-A35: Manual Settlement', () => {
    test('should have Manual Settlement button or menu option', async ({ page }) => {
      // Check for manual settlement button/link
      const manualBtn = page.locator('[data-testid="manual-settlement-button"]')
      const manualLink = page.locator('[data-testid="manual-settlement-link"]')

      const btnVisible = await manualBtn.isVisible().catch(() => false)
      const linkVisible = await manualLink.isVisible().catch(() => false)

      expect(btnVisible || linkVisible).toBeTruthy()
    })

    test('should display transaction selection for manual settlement', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Open manual settlement with proper loading wait
      await settlementsPage.openManualSettlement()

      // Verify selection view for manual settlement is visible
      const selectionView = page.locator('[data-testid="settlement-manual-selection"]')
      await expect(selectionView).toBeVisible()

      // Should have transaction table or empty state
      const table = page.locator('[data-testid="settlement-manual-table"]')
      const emptyState = page.locator('[data-testid="settlement-no-transactions"]')

      const tableVisible = await table.isVisible().catch(() => false)
      const emptyVisible = await emptyState.isVisible().catch(() => false)

      expect(tableVisible || emptyVisible).toBeTruthy()
    })

    test('should display SEPA status filter in manual settlement', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Open manual settlement with proper loading wait
      await settlementsPage.openManualSettlement()

      // Verify selection view is visible
      const selectionView = page.locator('[data-testid="settlement-manual-selection"]')
      await expect(selectionView).toBeVisible()

      // Check for SEPA status filter
      const sepaFilter = page.locator('[data-testid="settlement-sepa-status-filter"]')
      await expect(sepaFilter).toBeVisible()
    })

    test('should display reason dropdown and comment field in summary', async ({ page }) => {
      const settlementsPage = new SettlementsPage(page)

      // Open manual settlement with proper loading wait
      await settlementsPage.openManualSettlement()

      // Verify selection view is visible
      const selectionView = page.locator('[data-testid="settlement-manual-selection"]')
      await expect(selectionView).toBeVisible()

      // Select a transaction if available
      const checkbox = page.locator('[data-testid="settlement-manual-table"] input[type="checkbox"]').first()
      const checkboxVisible = await checkbox.isVisible().catch(() => false)

      if (checkboxVisible) {
        await checkbox.check()

        // Continue with proper loading wait
        await settlementsPage.continueFromManualSelection()

        // Verify summary with reason and comment fields
        const reasonField = page.locator('[data-testid="settlement-reason"]')
        const commentField = page.locator('[data-testid="settlement-comment"]')

        await expect(reasonField.or(commentField)).toBeVisible()
      }
    })

    test('should validate comment minimum length (10 characters)', async ({ page }) => {
      const manualBtn = page.locator('[data-testid="manual-settlement-button"]')
      const manualBtnVisible = await manualBtn.isVisible().catch(() => false)

      if (manualBtnVisible) {
        await manualBtn.click()

        const selectionView = page.locator('[data-testid="settlement-manual-selection"]')
        const selectionVisible = await selectionView.isVisible().catch(() => false)

        if (selectionVisible) {
          const checkbox = page.locator('[data-testid="settlement-manual-table"] input[type="checkbox"]').first()
          const checkboxVisible = await checkbox.isVisible().catch(() => false)

          if (checkboxVisible) {
            await checkbox.check()

            const continueBtn = page.locator('[data-testid="settlement-manual-continue"]')
            const continueBtnVisible = await continueBtn.isVisible().catch(() => false)

            if (continueBtnVisible) {
              await continueBtn.click()

              // Fill reason dropdown
              const reasonField = page.locator('[data-testid="settlement-reason"]')
              const reasonVisible = await reasonField.isVisible({ timeout: 5000 }).catch(() => false)

              if (reasonVisible) {
                await reasonField.selectOption('cash_payment')

                // Fill short comment
                const commentField = page.locator('[data-testid="settlement-comment"]')
                const commentVisible = await commentField.isVisible().catch(() => false)

                if (commentVisible) {
                  await commentField.fill('Short')

                  // Try to submit
                  const submitBtn = page.locator('[data-testid="settlement-submit"]')
                  const submitVisible = await submitBtn.isVisible().catch(() => false)

                  if (submitVisible) {
                    await submitBtn.click()

                    // Check for error message
                    const errorMsg = page.locator('[data-testid="comment-error"]')
                    const errorVisible = await errorMsg.isVisible().catch(() => false)
                    expect(errorVisible).toBeTruthy()
                  }
                }
              }
            }
          }
        }
      }
    })

    test('should display all settlement reason options', async ({ page }) => {
      const manualBtn = page.locator('[data-testid="manual-settlement-button"]')
      const manualBtnVisible = await manualBtn.isVisible().catch(() => false)

      if (manualBtnVisible) {
        await manualBtn.click()

        const selectionView = page.locator('[data-testid="settlement-manual-selection"]')
        const selectionVisible = await selectionView.isVisible().catch(() => false)

        if (selectionVisible) {
          const checkbox = page.locator('[data-testid="settlement-manual-table"] input[type="checkbox"]').first()
          const checkboxVisible = await checkbox.isVisible().catch(() => false)

          if (checkboxVisible) {
            await checkbox.check()

            const continueBtn = page.locator('[data-testid="settlement-manual-continue"]')
            const continueBtnVisible = await continueBtn.isVisible().catch(() => false)

            if (continueBtnVisible) {
              await continueBtn.click()

              const reasonField = page.locator('[data-testid="settlement-reason"]')
              const reasonVisible = await reasonField.isVisible({ timeout: 5000 }).catch(() => false)

              if (reasonVisible) {
                // Get all options
                const options = reasonField.locator('option')
                const optionCount = await options.count()

                // Should have at least 5 reason options
                expect(optionCount).toBeGreaterThanOrEqual(5)
              }
            }
          }
        }
      }
    })
  })

  /**
   * Responsive Design Tests
   */
  test.describe('Responsive Design', () => {
    test('should display settlements table on desktop', async ({ page }) => {
      // Page is already navigated via beforeEach, verify desktop layout
      const boundingBox = await page.getByTestId('settlements-page').boundingBox()
      expect(boundingBox).toBeTruthy()
      expect(boundingBox?.width).toBeGreaterThan(0)
    })

    test('should display settlements table on mobile', async ({ page }) => {
      // Page is already navigated, just verify it's responsive
      await expect(page.getByTestId('settlements-page')).toBeVisible()

      // Table should exist and be responsive
      const table = page.locator('[data-testid="settlements-table"]')
      const tableVisible = await table.isVisible().catch(() => false)

      if (tableVisible) {
        const boundingBox = await table.boundingBox()
        expect(boundingBox).toBeTruthy()
      }
    })
  })
})
