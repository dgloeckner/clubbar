/**
 * Member Test Utilities
 *
 * Provides a high-level API for creating test members via the admin API.
 * Uses page.request (authenticated via Playwright storage state) so it works
 * in admin-chromium E2E tests alongside page fixtures.
 *
 * Implements E2E Testing Pattern 001: Test Data Isolation
 *   - Generates unique names/emails via createTestMember() defaults
 *   - Throws on failure with a clear error message
 *
 * Usage:
 *   import { createMemberViaPage } from '../../utils/members'
 *
 *   const member = await createMemberViaPage(page)
 *   const memberId = member.id
 *
 *   // SEPA-invalid member (no iban/mandate fields):
 *   await createMemberViaPage(page, { withSepa: false, firstName: 'NoSepa' })
 *
 *   // Member with RFID card:
 *   await createMemberViaPage(page, { cardUid: '1A2B3C4D' })
 */

import type { Page } from '@playwright/test'
import { createTestMember } from './transactions'

const API_BASE = 'http://localhost:8080/api'

export interface CreateMemberOptions {
  /** Override first name. Default: auto-generated unique value. */
  firstName?: string
  /** Override last name. Default: 'Test'. */
  lastName?: string
  /** Override email. Default: auto-generated unique value. */
  email?: string
  /** Override preferred language. Default: 'de'. */
  preferredLanguage?: 'de' | 'en'
  /**
   * Set to false to create a SEPA-invalid member (no iban/mandate fields).
   * Default: true (creates a SEPA-valid member).
   */
  withSepa?: boolean
  /** Override IBAN. Only used when withSepa !== false. Default: valid test IBAN. */
  iban?: string
  /** Override mandate_signed_at. Only used when withSepa !== false. Default: '2024-01-01'. */
  mandateSignedAt?: string
  /** Optional RFID card UID. Default: not included. */
  cardUid?: string
  /** Whether member is active. Default: true. */
  isActive?: boolean
}

export interface CreatedMember {
  id: string
  first_name: string
  last_name: string
  email: string
  [key: string]: any
}

/**
 * Create a test member via the admin API using page.request (storage-state auth).
 *
 * @param page - Playwright Page (must be authenticated via storage state)
 * @param options - Overrides for member fields
 * @returns The created member object from the API response
 * @throws Error if the API returns a non-201 status
 */
export async function createMemberViaPage(
  page: Page,
  options: CreateMemberOptions = {}
): Promise<CreatedMember> {
  const defaults = createTestMember()
  const withSepa = options.withSepa !== false

  const data: Record<string, any> = {
    first_name: options.firstName ?? defaults.first_name,
    last_name: options.lastName ?? defaults.last_name,
    email: options.email ?? defaults.email,
    preferred_language: options.preferredLanguage ?? defaults.preferred_language,
  }

  if (withSepa) {
    data.iban = options.iban ?? defaults.iban
    data.mandate_reference = defaults.mandate_reference  // Always a unique UUID-based value
    data.mandate_signed_at = options.mandateSignedAt ?? defaults.mandate_signed_at
  }

  if (options.cardUid !== undefined) {
    data.card_uid = options.cardUid
  }

  if (options.isActive !== undefined) {
    data.is_active = options.isActive
  }

  const response = await page.request.post(`${API_BASE}/admin/members`, { data })
  if (response.status() !== 201) {
    const error = await response.json().catch(() => response.text())
    throw new Error(`createMemberViaPage failed (${response.status()}): ${JSON.stringify(error)}`)
  }

  return response.json()
}
