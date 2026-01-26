import { test, expect } from '@playwright/test'

test.describe('Members Page (UC-A10 to UC-A15)', () => {
  test.beforeEach(async ({ page }) => {
    // Login first
    await page.goto('http://localhost:5173/login')
    await page.getByTestId('login-email-input').fill('admin@example.com')
    await page.getByTestId('login-password-input').fill('password123')
    await page.getByTestId('login-submit-button').click()
    await page.waitForURL('**/members', { timeout: 15000 })
  })

  test.describe('UC-A10: List Members', () => {
    test('should display members page with table', async ({ page }) => {
      // Page title should be visible
      expect(page.url()).toContain('/members')

      // Table or list should be visible
      const table = page.locator('table, [role="table"]').first()
      await expect(table).toBeVisible()
    })

    test('should display member columns', async ({ page }) => {
      // Table headers should contain expected columns
      const headers = page.locator('th, [role="columnheader"]')
      const headerText = (await headers.allTextContents()).map(t => t.toLowerCase()).join(' ')

      // Should have columns for name, email, balance, status
      expect(headerText).toMatch(/name|email|mitglied/i)
    })

    test('should display member rows', async ({ page }) => {
      // Member table should have rows
      const rows = page.locator('tbody tr, [role="row"]').filter({ hasNot: page.locator('th') })
      const count = await rows.count()

      expect(count).toBeGreaterThan(0)
    })

    test('should display dashboard stats', async ({ page }) => {
      // Stats cards should be visible
      const membersStat = page.getByTestId('stat-card-mitglieder')
      const balanceStat = page.getByTestId('stat-card-offene-posten')

      await expect(membersStat).toBeVisible()
      await expect(balanceStat).toBeVisible()
    })
  })

  test.describe('UC-A11: Create Member', () => {
    test('should open create member modal', async ({ page }) => {
      // Find create button
      const createBtn = page.locator('button:has-text("Create"), button:has-text("Hinzufügen"), button:has-text("Neu")').first()
      await createBtn.click()

      // Modal should open
      const modal = page.locator('[role="dialog"]').first()
      await expect(modal).toBeVisible()

      // Form should have email input
      const emailInput = modal.locator('input[type="email"]').first()
      await expect(emailInput).toBeVisible()
    })

    test('should submit member form', async ({ page }) => {
      // Open create modal
      const createBtn = page.locator('button:has-text("Create"), button:has-text("Hinzufügen"), button:has-text("Neu")').first()
      await createBtn.click()

      const modal = page.locator('[role="dialog"]').first()

      // Fill form with test data
      const timestamp = Date.now()
      const email = `test-member-${timestamp}@example.com`

      await modal.locator('input[type="email"]').fill(email)
      await modal.locator('input[placeholder*="First"], input[placeholder*="Vorname"]').fill('Test')
      await modal.locator('input[placeholder*="Last"], input[placeholder*="Nachname"]').fill('Member')

      // Submit form
      const submitBtn = modal.locator('button:has-text("Save"), button:has-text("Speichern"), button:has-text("Create")').first()
      await submitBtn.click()

      // Modal should close or success message appears
      await expect(modal).not.toBeVisible({ timeout: 5000 })

      // Member should appear in table
      const newMember = page.locator(`text=${email}`)
      await expect(newMember).toBeVisible({ timeout: 5000 })
    })
  })

  test.describe('UC-A12: Edit Member', () => {
    test('should open edit modal for existing member', async ({ page }) => {
      // Get first member row
      const firstRow = page.locator('tbody tr, [role="row"]').filter({ hasNot: page.locator('th') }).first()

      // Click edit button in row
      const editBtn = firstRow.locator('button:has-text("Edit"), button:has-text("Bearbeiten"), [aria-label*="edit"]').first()
      await editBtn.click()

      // Modal should open with member data
      const modal = page.locator('[role="dialog"]').first()
      await expect(modal).toBeVisible()

      // Form should be pre-filled
      const emailInput = modal.locator('input[type="email"]').first()
      const emailValue = await emailInput.inputValue()
      expect(emailValue).toBeTruthy()
    })

    test('should update member data', async ({ page }) => {
      // Open edit modal for first member
      const firstRow = page.locator('tbody tr, [role="row"]').filter({ hasNot: page.locator('th') }).first()
      const editBtn = firstRow.locator('button:has-text("Edit"), button:has-text("Bearbeiten"), [aria-label*="edit"]').first()
      await editBtn.click()

      const modal = page.locator('[role="dialog"]').first()

      // Update a field
      const phoneInput = modal.locator('input[type="tel"], input[placeholder*="Phone"], input[placeholder*="Telefon"]').first()
      await phoneInput.fill('+41791234567')

      // Submit
      const submitBtn = modal.locator('button:has-text("Save"), button:has-text("Speichern")').first()
      await submitBtn.click()

      // Modal closes and changes are visible
      await expect(modal).not.toBeVisible({ timeout: 5000 })
    })
  })

  test.describe('UC-A15: Deactivate Member', () => {
    test('should deactivate a member', async ({ page }) => {
      // Get first member row
      const firstRow = page.locator('tbody tr, [role="row"]').filter({ hasNot: page.locator('th') }).first()

      // Find and click deactivate/delete button
      const deleteBtn = firstRow.locator('button:has-text("Delete"), button:has-text("Löschen"), button:has-text("Deactivate"), [aria-label*="delete"]').first()
      await deleteBtn.click()

      // Confirmation dialog should appear
      const confirmDialog = page.locator('[role="dialog"], [role="alertdialog"]').filter({ hasText: /confirm|bestätigen|wirklich/i }).first()
      await expect(confirmDialog).toBeVisible()

      // Click confirm
      const confirmBtn = confirmDialog.locator('button:has-text("Confirm"), button:has-text("Bestätigen"), button:has-text("Delete")').first()
      await confirmBtn.click()

      // Dialog closes
      await expect(confirmDialog).not.toBeVisible({ timeout: 5000 })
    })
  })

  test.describe('Search & Filter', () => {
    test('should search members by email', async ({ page }) => {
      // Find search input
      const searchInput = page.locator('input[placeholder*="Search"], input[placeholder*="Suchen"]').first()
      await expect(searchInput).toBeVisible()

      // Search for a member
      await searchInput.fill('admin@example.com')

      // Table should update with results
      const rows = page.locator('tbody tr, [role="row"]').filter({ hasNot: page.locator('th') })
      await expect(rows.first()).toBeVisible()

      // Result should contain search term
      const firstRow = rows.first()
      const rowText = await firstRow.textContent()
      expect(rowText).toContain('admin@example.com')
    })

    test('should clear search filter', async ({ page }) => {
      // Search for something
      const searchInput = page.locator('input[placeholder*="Search"], input[placeholder*="Suchen"]').first()
      await searchInput.fill('admin')

      // Get initial count
      const initialCount = await page.locator('tbody tr, [role="row"]').filter({ hasNot: page.locator('th') }).count()

      // Clear search
      await searchInput.clear()

      // More results should be visible
      const finalCount = await page.locator('tbody tr, [role="row"]').filter({ hasNot: page.locator('th') }).count()
      expect(finalCount).toBeGreaterThanOrEqual(initialCount)
    })
  })

  test.describe('Responsive Behavior', () => {
    test('should show table on desktop', async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 })
      const table = page.locator('table').first()
      await expect(table).toBeVisible()
    })

    test('should be scrollable on mobile', async ({ page }) => {
      await page.setViewportSize({ width: 375, height: 667 })

      // Table should still be accessible
      const table = page.locator('table, [role="table"]').first()
      await expect(table).toBeVisible()
    })
  })
})
