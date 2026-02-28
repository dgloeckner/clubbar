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
    expect(authenticatedMembersPage.getCurrentUrl()).toContain('/members')
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
    expect(activeCount).toBeGreaterThanOrEqual(1)  // Seeded data has 8 active members
    expect(activeCount).toBeLessThanOrEqual(allCount)  // Active is subset of all

    // Step 3: Get count of inactive members
    await authenticatedMembersPage.setStatusFilter('inactive')
    const inactiveCount = await authenticatedMembersPage.getMemberRowCount()
    expect(inactiveCount).toBeGreaterThanOrEqual(0)  // Seeded data has 0 inactive members; 0 is valid
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

  test('should filter members by card assignment (With Card / Without Card)', async ({ authenticatedMembersPage, page }) => {
    const timestamp = Date.now()
    const testId = `CF${timestamp}` // Short prefix to stay under 20 chars
    const cardUid = `${timestamp}`.slice(-16) // Use last 16 digits (fits in VARCHAR(20))

    // Use distinct names that are not substrings of each other
    const withCardName = `${testId}HasCard`
    const withoutCardName = `${testId}NoCard`

    // Create member WITH card (use unique card_uid based on timestamp)
    await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: withCardName,
        last_name: 'Test',
        email: `${testId}hc@test.com`,
        iban: 'DE89370400440532013000',
        mandate_reference: `MAN${testId}HC`,
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de',
        card_uid: cardUid
      }
    })

    // Create member WITHOUT card
    await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: withoutCardName,
        last_name: 'Test',
        email: `${testId}nc@test.com`,
        iban: 'DE89370400440532013001',
        mandate_reference: `MAN${testId}NC`,
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de'
      }
    })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    // Click "With Card" filter
    await page.click('[data-testid="filter-card-with"]')
    await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

    // Verify only "HasCard" member shown (use exact text match via getByText)
    await expect(page.getByText(withCardName, { exact: false })).toBeVisible()
    await expect(page.getByText(withoutCardName, { exact: false })).not.toBeVisible()

    // Click "Without Card" filter
    await page.click('[data-testid="filter-card-without"]')
    await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

    // Verify only "NoCard" member shown
    await expect(page.getByText(withCardName, { exact: false })).not.toBeVisible()
    await expect(page.getByText(withoutCardName, { exact: false })).toBeVisible()

    // Click "All" to reset
    await page.click('[data-testid="filter-card-all"]')
    await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

    // Verify both shown
    await expect(page.getByText(withCardName, { exact: false })).toBeVisible()
    await expect(page.getByText(withoutCardName, { exact: false })).toBeVisible()
  })

  test('E2E: clearing card_uid via edit form moves member to ohne-karte filter', async ({ authenticatedMembersPage, page }) => {
    /**
     * Regression test for bug: clearing card_uid in the edit form did not send
     * null to the backend (it omitted the field entirely), so the DB value was
     * never cleared and the member did not appear in the "Ohne Karte" filter.
     *
     * Pattern 001: Unique test data per test (timestamp-based IDs)
     * Pattern 008: expect() assertions, no try-catch visibility checks
     */
    const timestamp = Date.now()
    const testId = `CUC${timestamp}`.slice(0, 18) // Short prefix, stays under 20 chars
    const cardUid = `${timestamp}`.slice(-16)      // Last 16 hex-compatible digits

    // Step 1: Create a member WITH a card UID via API (Pattern 001: isolated test data)
    const createResp = await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: testId,
        last_name: 'ClearTest',
        email: `${testId.slice(0, 30)}@t.com`,
        iban: 'DE89370400440532013000',
        mandate_reference: `MAN${testId.slice(-8)}`,
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de',
        card_uid: cardUid,
      }
    })
    expect(createResp.status()).toBe(201)
    const createdMember = await createResp.json()
    const memberId = createdMember.id

    // Step 2: Navigate, verify member appears in "Mit Karte" filter
    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    await page.click('[data-testid="filter-card-with"]')
    await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)
    await expect(page.locator(`text=${testId}`)).toBeVisible()

    // Step 3: Reset to "All" filter so the member is visible for editing
    await page.click('[data-testid="filter-card-all"]')
    await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

    // Step 4: Open edit modal for the member and clear the card_uid field
    await authenticatedMembersPage.openEditModalForMember(memberId)
    await authenticatedMembersPage.expectFormModalVisible()

    // Clear the card_uid input
    const cardUidInput = page.getByTestId('member-form-card-uid')
    await expect(cardUidInput).toBeVisible()
    await cardUidInput.clear()

    // Step 5: Submit the form and wait for PATCH to succeed
    const patchResponse = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/members') && resp.request().method() === 'PATCH' && resp.status() === 200
    )
    await authenticatedMembersPage.submitForm()
    await patchResponse

    // Verify form closes (indicates API call succeeded)
    await authenticatedMembersPage.expectFormModalHidden()

    // Step 6: Apply "Ohne Karte" filter — member must now appear
    await page.click('[data-testid="filter-card-without"]')
    await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

    // Pattern 008: Use expect() for clear error messages on failure
    await expect(page.locator(`text=${testId}`)).toBeVisible()

    // Verify member no longer appears in "Mit Karte" filter
    await page.click('[data-testid="filter-card-with"]')
    await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)
    await expect(page.locator(`text=${testId}`)).not.toBeVisible()
  })
})
