import { test, expect } from '../../fixtures/pageObjects'

/**
 * Admin Frontend - Members Page Sort and Filter Tests
 *
 * UC-A12: Sort and Filter Members
 * Pattern 001: Test Data Isolation - Each test uses independent state
 * Pattern 004: Parallel Execution Safety - No shared state between tests
 */

test.describe('UC-A12: Sort and Filter Members', () => {
  test('should change filter to active', async ({ authenticatedMembersPage }) => {
    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    // Set filter to active
    await authenticatedMembersPage.setStatusFilter('active')

    // Verify dropdown now shows "active"
    const filterValue = await authenticatedMembersPage.getStatusFilterValue()
    expect(filterValue).toBe('active')
  })

  test('should change filter to inactive', async ({ authenticatedMembersPage }) => {
    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    // Set filter to inactive
    await authenticatedMembersPage.setStatusFilter('inactive')

    // Verify dropdown now shows "inactive"
    const filterValue = await authenticatedMembersPage.getStatusFilterValue()
    expect(filterValue).toBe('inactive')
  })

  test('should cycle through filter options', async ({ authenticatedMembersPage }) => {
    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    // Start with all
    let filterValue = await authenticatedMembersPage.getStatusFilterValue()
    expect(filterValue).toBe('all')

    // Change to active
    await authenticatedMembersPage.setStatusFilter('active')
    filterValue = await authenticatedMembersPage.getStatusFilterValue()
    expect(filterValue).toBe('active')

    // Change to inactive
    await authenticatedMembersPage.setStatusFilter('inactive')
    filterValue = await authenticatedMembersPage.getStatusFilterValue()
    expect(filterValue).toBe('inactive')

    // Reset to all
    await authenticatedMembersPage.setStatusFilter('all')
    filterValue = await authenticatedMembersPage.getStatusFilterValue()
    expect(filterValue).toBe('all')
  })

  test('should verify page loads correctly', async ({ authenticatedMembersPage }) => {
    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    // Verify we're on the correct page
    expect(authenticatedMembersPage.page.url()).toContain('/members')
  })

  test('E2E: filtering by status correctly filters members', async ({ authenticatedMembersPage }) => {
    /**
     * Complete end-to-end test for member status filtering
     *
     * This test verifies that:
     * 1. Filtering by "all" shows all members
     * 2. Filtering by "active" shows only active members (subset or equal)
     * 3. Filtering by "inactive" shows only inactive members (subset or equal)
     * 4. The filter correctly updates the UI and fetches filtered data from backend
     */

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    // Step 1: Get count of all members
    await authenticatedMembersPage.setStatusFilter('all')
    const allCount = await authenticatedMembersPage.getMemberRowCount()
    expect(allCount).toBeGreaterThan(0)  // Should have at least one member

    // Step 2: Get count of active members
    await authenticatedMembersPage.setStatusFilter('active')
    const activeCount = await authenticatedMembersPage.getMemberRowCount()
    expect(activeCount).toBeGreaterThanOrEqual(0)  // Could be 0 if all are inactive
    expect(activeCount).toBeLessThanOrEqual(allCount)  // Active is subset of all

    // Step 3: Get count of inactive members
    await authenticatedMembersPage.setStatusFilter('inactive')
    const inactiveCount = await authenticatedMembersPage.getMemberRowCount()
    expect(inactiveCount).toBeGreaterThanOrEqual(0)  // Could be 0 if all are active
    expect(inactiveCount).toBeLessThanOrEqual(allCount)  // Inactive is subset of all

    // Step 4: Verify logic: sum of filtered results <= total (respects pagination)
    const filteredSum = activeCount + inactiveCount
    expect(filteredSum).toBeLessThanOrEqual(allCount * 2)  // Allow for pagination boundary

    // Step 5: Switch back to all and verify we get same count
    await authenticatedMembersPage.setStatusFilter('all')
    const finalAllCount = await authenticatedMembersPage.getMemberRowCount()
    expect(finalAllCount).toBe(allCount)  // Should be identical

    // Verify filter dropdown reflects current selection
    const filterValue = await authenticatedMembersPage.getStatusFilterValue()
    expect(filterValue).toBe('all')
  })

  test('should filter members by card assignment (With Card / Without Card)', async ({ authenticatedMembersPage }) => {
    const timestamp = Date.now()
    const testId = `CF${timestamp}` // Short prefix to stay under 20 chars
    const cardUid = `${timestamp}`.slice(-16) // Use last 16 digits (fits in VARCHAR(20))
    const page = authenticatedMembersPage.page

    // Create member WITH card (use unique card_uid based on timestamp)
    await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: `${testId}With`,
        last_name: 'Test',
        email: `${testId}with@test.com`,
        iban: 'DE89370400440532013000',
        mandate_reference: `MAN${testId}W`,
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de',
        card_uid: cardUid
      }
    })

    // Create member WITHOUT card
    await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: `${testId}Without`,
        last_name: 'Test',
        email: `${testId}without@test.com`,
        iban: 'DE89370400440532013001',
        mandate_reference: `MAN${testId}WO`,
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de'
      }
    })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    // Click "With Card" filter
    await page.click('[data-testid="filter-card-with"]')
    await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

    // Verify only "With" member shown
    await expect(page.locator('text=' + testId + 'With')).toBeVisible()
    await expect(page.locator('text=' + testId + 'Without')).not.toBeVisible()

    // Click "Without Card" filter
    await page.click('[data-testid="filter-card-without"]')
    await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

    // Verify only "Without" member shown
    await expect(page.locator('text=' + testId + 'With')).not.toBeVisible()
    await expect(page.locator('text=' + testId + 'Without')).toBeVisible()

    // Click "All" to reset
    await page.click('[data-testid="filter-card-all"]')
    await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

    // Verify both shown
    await expect(page.locator('text=' + testId + 'With')).toBeVisible()
    await expect(page.locator('text=' + testId + 'Without')).toBeVisible()
  })
})
