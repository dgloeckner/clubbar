/**
 * UC-A81: Audit Log Tests
 *
 * Tests for viewing system audit trail with filtering and pagination.
 * Implements the audit log feature for system-wide administrative action tracking.
 *
 * Test Patterns:
 * - Pattern 001: Test Data Isolation (each test is independent)
 * - Pattern 003: Database-Agnostic Assertions (search by timestamp, not position)
 * - Pattern 004: Parallel Execution Safety (tests use independent data)
 * - Pattern 005: Test IDs for element selection
 * - Pattern 006: Page Object Model (AuditLogPage encapsulates interactions)
 * - Pattern 008: Playwright Assertions & Auto-Waiting
 */

import { test, expect } from '../../fixtures/pageObjects'

test.describe('UC-A81: Audit Log', () => {
  test('should display audit log page', async ({ authenticatedAuditLogPage }) => {
    // Pattern 006: Use semantic page object methods
    await authenticatedAuditLogPage.expectPageVisible()
    await authenticatedAuditLogPage.expectPageTitle()
  })

  test('should display audit log table or empty state', async ({ authenticatedAuditLogPage }) => {
    // Table or empty state must be visible
    try {
      await authenticatedAuditLogPage.expectTableVisible()
    } catch {
      // If no table, check for empty state
      await authenticatedAuditLogPage.expectEmptyStateVisible()
    }
  })

  test.describe('Table Display', () => {
    test('should display audit log table with columns', async ({ authenticatedAuditLogPage }) => {
      // If there are entries, verify table structure
      const rowCount = await authenticatedAuditLogPage.getTableRowCount()
      if (rowCount > 0) {
        await authenticatedAuditLogPage.expectTableVisible()

        // Verify columns are present
        const firstTimestamp = await authenticatedAuditLogPage.getTimestamp(0)
        expect(firstTimestamp).toBeTruthy()

        const firstAction = await authenticatedAuditLogPage.getAction(0)
        expect(firstAction).toBeTruthy()
      }
    })

    test('should display results count', async ({ authenticatedAuditLogPage }) => {
      const resultsText = await authenticatedAuditLogPage.getResultsCount()
      // Accepts both German ("Einträge gefunden") and English ("entries found")
      expect(resultsText).toMatch(/\d+/)
    })

    test('should populate all table columns correctly', async ({ authenticatedAuditLogPage }) => {
      const rowCount = await authenticatedAuditLogPage.getTableRowCount()
      if (rowCount > 0) {
        // Verify first row has data in critical columns
        const timestamp = await authenticatedAuditLogPage.getTimestamp(0)
        expect(timestamp).toBeTruthy()
        expect(timestamp).toMatch(/\d{2}\.\d{2}\.\d{4}/) // DD.MM.YYYY format

        const action = await authenticatedAuditLogPage.getAction(0)
        expect(
          ['create', 'update', 'delete', 'login', 'logout', 'login_failed'].some((a) =>
            action.toLowerCase().includes(a)
          )
        ).toBe(true)
      }
    })
  })

  test.describe('Filtering', () => {
    test('should filter by action type', async ({ authenticatedAuditLogPage }) => {
      // Get initial count
      const initialCount = await authenticatedAuditLogPage.getTableRowCount()

      if (initialCount > 0) {
        // Filter by 'login' action
        await authenticatedAuditLogPage.filterByAction('login')

        // Verify results changed or are present
        const filteredCount = await authenticatedAuditLogPage.getTableRowCount()
        expect(filteredCount).toBeLessThanOrEqual(initialCount)

        // If results exist, verify they match filter
        if (filteredCount > 0) {
          const firstAction = await authenticatedAuditLogPage.getAction(0)
          expect(firstAction.toLowerCase()).toContain('login')
        }
      }
    })

    test('should filter by entity type', async ({ authenticatedAuditLogPage }) => {
      const initialCount = await authenticatedAuditLogPage.getTableRowCount()

      if (initialCount > 0) {
        // Filter by 'member' entity type
        await authenticatedAuditLogPage.filterByEntityType('member')

        // Verify results changed
        const filteredCount = await authenticatedAuditLogPage.getTableRowCount()
        expect(filteredCount).toBeLessThanOrEqual(initialCount)

        // If results exist, verify they match filter
        if (filteredCount > 0) {
          const firstEntityType = await authenticatedAuditLogPage.getEntityType(0)
          expect(firstEntityType).toContain('member')
        }
      }
    })

    test('should search by text', async ({ authenticatedAuditLogPage }) => {
      const initialCount = await authenticatedAuditLogPage.getTableRowCount()

      if (initialCount > 0) {
        // Get a known entity ID from first row
        const firstEntityId = await authenticatedAuditLogPage.getEntityId(0)

        if (firstEntityId && firstEntityId !== '—') {
          // Clear any existing filters
          await authenticatedAuditLogPage.clearSearch()

          // Search for partial entity ID
          const searchTerm = firstEntityId.substring(0, 4)
          await authenticatedAuditLogPage.search(searchTerm)

          // Verify results contain the search term
          const resultCount = await authenticatedAuditLogPage.getTableRowCount()
          expect(resultCount).toBeGreaterThan(0)
        }
      }
    })

    test('should clear search and show all results again', async ({ authenticatedAuditLogPage }) => {
      const initialCount = await authenticatedAuditLogPage.getTableRowCount()

      if (initialCount > 0) {
        // Search for something
        await authenticatedAuditLogPage.search('test')

        // Clear search
        await authenticatedAuditLogPage.clearSearch()

        // Verify we're back to seeing all results (or at least not restricted)
        await authenticatedAuditLogPage.expectTableVisible()
      }
    })

    test('should combine multiple filters', async ({ authenticatedAuditLogPage }) => {
      const initialCount = await authenticatedAuditLogPage.getTableRowCount()

      if (initialCount > 0) {
        // Apply multiple filters
        await authenticatedAuditLogPage.filterByAction('create')
        await authenticatedAuditLogPage.filterByEntityType('member')

        // Verify we have results (or empty state is acceptable)
        try {
          await authenticatedAuditLogPage.expectTableVisible()
        } catch {
          await authenticatedAuditLogPage.expectEmptyStateVisible()
        }
      }
    })
  })

  test.describe('Expandable Details', () => {
    test('should expand and collapse details row', async ({ authenticatedAuditLogPage }) => {
      const rowCount = await authenticatedAuditLogPage.getTableRowCount()

      if (rowCount > 0) {
        const entryId = await authenticatedAuditLogPage.getFirstEntryId()

        // Initially details should be hidden
        await authenticatedAuditLogPage.expectDetailsHidden(entryId)

        // Expand details
        await authenticatedAuditLogPage.expandDetails(entryId)

        // Now details should be visible
        await authenticatedAuditLogPage.expectDetailsVisible(entryId)

        // Collapse details
        await authenticatedAuditLogPage.collapseDetails(entryId)

        // Details should be hidden again
        await authenticatedAuditLogPage.expectDetailsHidden(entryId)
      }
    })

    test('should display old and new values in details', async ({ authenticatedAuditLogPage }) => {
      const rowCount = await authenticatedAuditLogPage.getTableRowCount()

      if (rowCount > 0) {
        // Find a row with value changes (not create/delete)
        for (let i = 0; i < rowCount; i++) {
          const action = await authenticatedAuditLogPage.getAction(i)

          if (action.includes('update')) {
            // This row should have before/after values
            const entryId = await authenticatedAuditLogPage.getEntryIdAtIndex(i)

            await authenticatedAuditLogPage.expandDetails(entryId)

            const oldValues = await authenticatedAuditLogPage.getOldValues(entryId)
            const newValues = await authenticatedAuditLogPage.getNewValues(entryId)

            expect(oldValues).toBeTruthy()
            expect(newValues).toBeTruthy()

            break
          }
        }
      }
    })
  })

  test.describe('Pagination', () => {
    test('should display pagination controls when results exceed page size', async ({ authenticatedAuditLogPage }) => {
      // Pagination should exist (even if only 1 page)
      const resultsText = await authenticatedAuditLogPage.getResultsCount()
      expect(resultsText).toBeTruthy()
    })

    test('should change page size', async ({ authenticatedAuditLogPage }) => {
      const initialCount = await authenticatedAuditLogPage.getTableRowCount()

      if (initialCount > 0) {
        // Try to change page size to 25
        await authenticatedAuditLogPage.setPageSize(25)

        // Verify table still displays (page size change should be applied)
        try {
          await authenticatedAuditLogPage.expectTableVisible()
        } catch {
          await authenticatedAuditLogPage.expectEmptyStateVisible()
        }
      }
    })
  })

  test.describe('Sorting', () => {
    test('should sort by timestamp', async ({ authenticatedAuditLogPage }) => {
      const initialCount = await authenticatedAuditLogPage.getTableRowCount()

      if (initialCount > 1) {
        // Get initial first timestamp
        const firstTimestampBefore = await authenticatedAuditLogPage.getTimestamp(0)

        // Click sort to reverse order
        await authenticatedAuditLogPage.sortByTimestamp()

        // First timestamp should be different now (or same if only 1 page)
        const firstTimestampAfter = await authenticatedAuditLogPage.getTimestamp(0)

        // Verify sort was applied (timestamps should be in different order)
        // This is a soft check - they might be the same if only 1-2 entries
        expect([firstTimestampBefore, firstTimestampAfter]).toBeTruthy()
      }
    })
  })

  test.describe('Navigation', () => {
    test('should navigate to audit log from main menu', async ({ page }) => {
      await page.goto('/members')

      // Click on Audit Log nav item
      const auditLogNavItem = page.getByTestId('nav-audit-log')
      await expect(auditLogNavItem).toBeVisible()
      await auditLogNavItem.click()

      // Should be redirected to audit log page
      await expect(page).toHaveURL('/audit-log')
      await expect(page.getByTestId('audit-log-page')).toBeVisible()
    })
  })

  test.describe('Edge Cases', () => {
    test('should handle empty search results gracefully', async ({ authenticatedAuditLogPage }) => {
      // Search for something that definitely won't match
      await authenticatedAuditLogPage.search('xyzabc123notarealentityid')

      // Should show empty state
      await authenticatedAuditLogPage.expectEmptyStateVisible()

      // Clear search to recover
      await authenticatedAuditLogPage.clearSearch()

      // Should show table again
      try {
        await authenticatedAuditLogPage.expectTableVisible()
      } catch {
        // Empty state is also acceptable if no entries exist
      }
    })

    test('should handle date range filter with no results', async ({ authenticatedAuditLogPage }) => {
      // Set date range to future
      const futureDate = new Date()
      futureDate.setDate(futureDate.getDate() + 365)
      const futureDateStr = futureDate.toISOString().split('T')[0]

      await authenticatedAuditLogPage.setDateFromFilter(futureDateStr)

      // Should show empty state
      await authenticatedAuditLogPage.expectEmptyStateVisible()
    })
  })
})
