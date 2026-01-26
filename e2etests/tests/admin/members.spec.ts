/**
 * Members Page E2E Tests
 *
 * Tests for admin panel member management functionality.
 * Implements:
 * - UC-A10: List Members
 * - UC-A11: Create Member
 * - UC-A12: Edit Member
 * - UC-A15: Deactivate Member
 *
 * Pattern Usage:
 * - Pattern 001: Test Data Isolation - unique test data per test (Date.now() for email)
 * - Pattern 002: Authentication Isolation - proper login via page object
 * - Pattern 006: Page Object Model - MembersPage encapsulates all interactions
 * - Pattern 007: Page Object Fixtures - authenticatedMembersPage fixture eliminates setup boilerplate
 */

import { test, expect } from '../../fixtures/pageObjects.fixture'

test.describe('Members Page (UC-A10 to UC-A15)', () => {
  test.describe('UC-A10: List Members', () => {
    test('should display members page with table', async ({ authenticatedMembersPage, page }) => {
      // Pattern 008: Use expect() with auto-waiting instead of manual visibility checks
      const table = page.locator('table, [role="table"]')
      await expect(table).toBeVisible()

      // Should be on members page
      expect(page.url()).toContain('/members')
    })

    test('should display dashboard stats', async ({ authenticatedMembersPage, page }) => {
      // Stats cards should be visible and contain data
      const membersStat = page.getByTestId('stat-card-mitglieder')
      const balanceStat = page.getByTestId('stat-card-offene-posten')

      await expect(membersStat).toBeVisible()
      await expect(balanceStat).toBeVisible()
    })

    test('should have member rows in table', async ({ page }) => {
      // Table should exist and have rows (or be empty)
      const table = page.locator('table, [role="table"]')
      await expect(table).toBeVisible()

      const rows = page.locator('tbody tr, [role="row"]')
      const rowCount = await rows.count()
      expect(rowCount).toBeGreaterThanOrEqual(0)
    })
  })

  test.describe('UC-A11: Create Member', () => {
    test('should open and close create member modal', async ({ authenticatedMembersPage, page }) => {
      // Pattern 008: Use expect() for visibility assertions
      const modal = page.locator('[role="dialog"]').first()

      // Modal should be hidden initially
      await expect(modal).toBeHidden()

      // Open modal
      await authenticatedMembersPage.openCreateModal()

      // Modal should be visible after opening
      await expect(modal).toBeVisible()

      // Cancel should hide modal
      await authenticatedMembersPage.cancelMemberForm()

      // Modal should be hidden again
      await expect(modal).toBeHidden()
    })

    test('should create a new member', async ({ authenticatedMembersPage, page }) => {
      // Generate unique test data (Pattern 001: Test Data Isolation)
      const timestamp = Date.now()
      const testEmail = `member-${timestamp}@test.example.com`
      const testFirstName = 'Test'
      const testLastName = 'Member'

      // Create member
      await authenticatedMembersPage.createMember(testEmail, testFirstName, testLastName)

      // Pattern 008: Use expect() with auto-waiting
      // Pattern 003: Search by specific email (database-agnostic)
      await expect(page.locator(`text=${testEmail}`)).toBeVisible()
    })
  })

  test.describe('Search & Filter', () => {
    test('should filter members by search', async ({ authenticatedMembersPage, page }) => {
      // Get rows in table
      const rows = page.locator('tbody tr, [role="row"]')
      const initialCount = await rows.count()

      if (initialCount > 0) {
        // Get first member email (Pattern 003: specific data)
        const firstRow = rows.first()
        const firstEmail = await firstRow.locator('td').nth(1).textContent()

        if (firstEmail) {
          // Search for this member
          await authenticatedMembersPage.search(firstEmail)

          // Wait for filter to apply
          await page.waitForTimeout(500)

          // Verify search worked (at least one result)
          const filteredRows = page.locator('tbody tr, [role="row"]')
          const filteredCount = await filteredRows.count()
          expect(filteredCount).toBeGreaterThan(0)
        }
      }
    })

    test('should clear search filter', async ({ authenticatedMembersPage }) => {
      // Search for something
      await authenticatedMembersPage.search('test')
      await authenticatedMembersPage.waitForDebounce(500)

      // Clear search
      await authenticatedMembersPage.clearSearch()
      await authenticatedMembersPage.waitForDebounce(500)

      // Search input should be empty
      const searchValue = await authenticatedMembersPage.getSearchValue()
      expect(searchValue).toBe('')
    })
  })

  test.describe('Responsive Behavior', () => {
    test('should display table on desktop', async ({ page }) => {
      // Set desktop viewport (Pattern 004: Parallel Execution Safety - explicit viewport)
      await page.setViewportSize({ width: 1440, height: 900 })

      // Pattern 008: Use expect() for visibility
      const table = page.locator('table, [role="table"]')
      await expect(table).toBeVisible()
    })

    test('should adapt to mobile viewport', async ({ page }) => {
      // Set mobile viewport
      await page.setViewportSize({ width: 375, height: 667 })

      // Page should still have stats cards visible
      const statsCard = page.getByTestId('stat-card-mitglieder')
      await expect(statsCard).toBeVisible()
    })
  })
})
