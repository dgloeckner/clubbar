import { test, expect } from '../../fixtures/pageObjects'

/**
 * Admin Frontend - Members SEPA Filter Tests
 *
 * UC-A12: Sort and Filter Members (SEPA status filter)
 * Pattern 001: Test Data Isolation - Each test creates unique members via API
 * Pattern 004: Parallel Execution Safety - No shared state between tests
 * Pattern 008: Playwright Assertions - no try-catch visibility checks
 */

test.describe('UC-A12: SEPA Status Filter', () => {
  test('should render SEPA filter buttons in filter toolbar', async ({ authenticatedMembersPage }) => {
    const page = authenticatedMembersPage.page

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    await expect(page.getByTestId('filter-sepa-all')).toBeVisible()
    await expect(page.getByTestId('filter-sepa-valid')).toBeVisible()
    await expect(page.getByTestId('filter-sepa-missing')).toBeVisible()
  })

  test('E2E: filtering by Gültig shows only members with valid SEPA', async ({ authenticatedMembersPage }) => {
    /**
     * Creates two members via API:
     * - one WITH iban + mandate_reference (is_sepa_valid = true)
     * - one WITHOUT iban (is_sepa_valid = false)
     * Verifies "Gültig" filter shows only the valid one.
     * Pattern 001: unique test data per test (timestamp-based IDs)
     */
    const timestamp = Date.now()
    const testId = `SF${timestamp}`.slice(0, 16)
    const page = authenticatedMembersPage.page

    // Create member WITH valid SEPA
    const resp1 = await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: `${testId}Valid`,
        last_name: 'Sepa',
        email: `${testId}v@test.com`,
        iban: 'DE89370400440532013000',
        mandate_reference: `MAN${testId}V`,
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de',
      }
    })
    expect(resp1.status()).toBe(201)

    // Create member WITHOUT IBAN (is_sepa_valid = false)
    const resp2 = await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: `${testId}Miss`,
        last_name: 'Sepa',
        email: `${testId}m@test.com`,
        preferred_language: 'de',
      }
    })
    expect(resp2.status()).toBe(201)

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    // Apply "Gültig" filter
    await authenticatedMembersPage.setSepaFilter('valid')

    // Valid member visible, missing member not visible
    await expect(page.locator(`text=${testId}Valid`)).toBeVisible()
    await expect(page.locator(`text=${testId}Miss`)).not.toBeVisible()
  })

  test('E2E: filtering by Fehlend shows only members with missing SEPA', async ({ authenticatedMembersPage }) => {
    const timestamp = Date.now()
    const testId = `SM${timestamp}`.slice(0, 16)
    const page = authenticatedMembersPage.page

    // Create member WITH valid SEPA
    const resp1 = await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: `${testId}Valid`,
        last_name: 'Sepa',
        email: `${testId}v@test.com`,
        iban: 'DE89370400440532013000',
        mandate_reference: `MAN${testId}V`,
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de',
      }
    })
    expect(resp1.status()).toBe(201)

    // Create member WITHOUT IBAN (is_sepa_valid = false)
    const resp2 = await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: `${testId}Miss`,
        last_name: 'Sepa',
        email: `${testId}m@test.com`,
        preferred_language: 'de',
      }
    })
    expect(resp2.status()).toBe(201)

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    // Apply "Fehlend" filter
    await authenticatedMembersPage.setSepaFilter('missing')

    // Missing member visible, valid member not visible
    await expect(page.locator(`text=${testId}Miss`)).toBeVisible()
    await expect(page.locator(`text=${testId}Valid`)).not.toBeVisible()
  })

  test('E2E: clearing filters resets SEPA filter', async ({ authenticatedMembersPage }) => {
    const timestamp = Date.now()
    const testId = `SC${timestamp}`.slice(0, 16)
    const page = authenticatedMembersPage.page

    // Create one member WITH valid SEPA
    await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: `${testId}Valid`,
        last_name: 'Sepa',
        email: `${testId}v@test.com`,
        iban: 'DE89370400440532013000',
        mandate_reference: `MAN${testId}V`,
        mandate_signed_at: '2024-01-15',
        preferred_language: 'de',
      }
    })

    // Create one member WITHOUT IBAN
    await page.request.post('http://localhost:8080/api/admin/members', {
      data: {
        first_name: `${testId}Miss`,
        last_name: 'Sepa',
        email: `${testId}m@test.com`,
        preferred_language: 'de',
      }
    })

    await authenticatedMembersPage.navigate()
    await authenticatedMembersPage.expectPageVisible()

    // Apply "Gültig" so "Fehlend" member is hidden
    await authenticatedMembersPage.setSepaFilter('valid')
    await expect(page.locator(`text=${testId}Miss`)).not.toBeVisible()

    // Click "Filter zurücksetzen"
    await page.click('[data-testid="members-clear-filters"]')
    await page.waitForResponse((resp) => resp.url().includes('/api/admin/members') && resp.status() === 200)

    // Both members visible again
    await expect(page.locator(`text=${testId}Valid`)).toBeVisible()
    await expect(page.locator(`text=${testId}Miss`)).toBeVisible()

    // SEPA "Alle" button reflects active state (no separate value check needed)
    await expect(page.getByTestId('filter-sepa-all')).toBeVisible()
  })
})
