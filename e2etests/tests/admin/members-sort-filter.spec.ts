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
})
