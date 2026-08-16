import { describe, expect, it } from 'vitest'
import { getAuditLogSummary } from './auditLogSummary'
import type { AuditLogEntry } from '../api/generated'

/** Captures the translation key and interpolation options a call resolved to, instead of real copy. */
function fakeT(key: string, options?: Record<string, unknown>): string {
  return options ? `${key}::${JSON.stringify(options)}` : key
}

function entry(overrides: Partial<AuditLogEntry>): AuditLogEntry {
  return {
    id: 1,
    admin_user_id: 'admin-1',
    admin_user_email: 'admin@example.com',
    action: 'update',
    entity_type: 'member',
    entity_id: 'member-12345678',
    old_values: null,
    new_values: null,
    ip_address: '127.0.0.1',
    user_agent: null,
    created_at: '2026-08-01T00:00:00Z',
    ...overrides,
  }
}

describe('getAuditLogSummary', () => {
  it('never falls through to a generic "no changes" message for actions with no diff by design', () => {
    const cases: Array<[AuditLogEntry['action'], string]> = [
      ['login', 'auditLog.summary.login'],
      ['logout', 'auditLog.summary.logout'],
      ['totp_enrolled', 'auditLog.summary.totpEnrolled'],
    ]
    for (const [action, expectedKey] of cases) {
      const result = getAuditLogSummary(entry({ action, old_values: null, new_values: null }), fakeT, 'en')
      expect(result).toContain(expectedKey)
    }
  })

  it('summarizes totp_enrolled without needing a diff', () => {
    const result = getAuditLogSummary(entry({ action: 'totp_enrolled', old_values: null, new_values: null }), fakeT, 'en')
    expect(result).toBe('auditLog.summary.totpEnrolled::{"actor":"admin@example.com"}')
  })

  it('summarizes totp_reset using the target admin captured in new_values', () => {
    const result = getAuditLogSummary(
      entry({ action: 'totp_reset', entity_id: 'target-1', new_values: { target_email: 'target@example.com' } }),
      fakeT,
      'en',
    )
    expect(result).toBe('auditLog.summary.totpReset::{"actor":"admin@example.com","target":"target@example.com"}')
  })

  it('falls back to the entity id when totp_reset has no target email', () => {
    const result = getAuditLogSummary(
      entry({ action: 'totp_reset', entity_id: 'target-12345678', new_values: null }),
      fakeT,
      'en',
    )
    expect(result).toContain('"target":"target-1"')
  })

  it('distinguishes a plain failed login from a failed step-up re-authentication', () => {
    const plain = getAuditLogSummary(
      entry({ action: 'login_failed', new_values: { attempted_email: 'unknown@example.com' } }),
      fakeT,
      'en',
    )
    expect(plain).toBe('auditLog.summary.loginFailed::{"email":"unknown@example.com"}')

    const stepUp = getAuditLogSummary(
      entry({ action: 'login_failed', new_values: { attempted_email: 'admin@example.com', context: 'step_up_reauth' } }),
      fakeT,
      'en',
    )
    expect(stepUp).toBe('auditLog.summary.loginFailedStepUp::{"actor":"admin@example.com"}')
  })

  it('names both amounts for a price divergence, and no actor', () => {
    // A terminal synced this and no admin acted, so the sentence must not
    // borrow the "An admin" fallback actor for something no admin did.
    const result = getAuditLogSummary(
      entry({
        action: 'transaction_price_divergence',
        entity_type: 'transaction',
        admin_user_id: null,
        admin_user_email: null,
        new_values: {
          member_id: 'member-1',
          product_id: 'product-1',
          terminal_id: 'terminal-1',
          amount_cents: 500,
          current_price_cents: 350,
        },
      }),
      fakeT,
      'en',
    )
    expect(result).toContain('auditLog.summary.transactionPriceDivergence::')
    expect(result).toContain('"amount"')
    expect(result).toContain('"price"')
    expect(result).not.toContain('actor')
  })

  it('falls back to the generic price-divergence sentence when the amounts are missing', () => {
    const result = getAuditLogSummary(
      entry({ action: 'transaction_price_divergence', entity_type: 'transaction', new_values: null }),
      fakeT,
      'en',
    )
    expect(result).toBe('auditLog.summary.transactionPriceDivergenceGeneric')
  })

  it('extracts a member subject from first_name/last_name', () => {
    const result = getAuditLogSummary(
      entry({ action: 'update', entity_type: 'member', new_values: { first_name: 'Jane', last_name: 'Doe' } }),
      fakeT,
      'en',
    )
    expect(result).toContain('"subject":"Jane Doe"')
  })

  it('extracts a localized product/category name from a multilingual name object', () => {
    const result = getAuditLogSummary(
      entry({ action: 'create', entity_type: 'product', new_values: { name: { de: 'Bier', en: 'Beer' } } }),
      fakeT,
      'en',
    )
    expect(result).toContain('"subject":"Beer"')
  })

  it('falls back to a truncated entity id when no identifying field is present', () => {
    const result = getAuditLogSummary(
      entry({ action: 'delete', entity_type: 'product', entity_id: 'abcdefgh-1234', old_values: {}, new_values: null }),
      fakeT,
      'en',
    )
    expect(result).toContain('"subject":"abcdefgh"')
  })

  it('includes settlement totals only when they are present in new_values', () => {
    const withTotals = getAuditLogSummary(
      entry({ action: 'settlement_create', entity_type: 'settlement', new_values: { member_count: 5, total_amount_cents: 12345 } }),
      fakeT,
      'en',
    )
    expect(withTotals).toContain('auditLog.summary.settlementCreate::')
    expect(withTotals).toContain('"memberCount":5')

    const withoutTotals = getAuditLogSummary(
      entry({ action: 'settlement_create', entity_type: 'settlement', new_values: null }),
      fakeT,
      'en',
    )
    expect(withoutTotals).toBe('auditLog.summary.settlementCreateGeneric::{"actor":"admin@example.com"}')
  })

  it('falls back to an unknown-actor label when admin_user_email is absent (e.g. a failed login)', () => {
    const result = getAuditLogSummary(
      entry({ action: 'login_failed', admin_user_email: null, new_values: { attempted_email: 'x@example.com' } }),
      fakeT,
      'en',
    )
    // loginFailed doesn't use `actor`, so pin a case that does instead.
    const totpResult = getAuditLogSummary(
      entry({ action: 'totp_enrolled', admin_user_email: null }),
      fakeT,
      'en',
    )
    expect(result).toBeTruthy()
    expect(totpResult).toContain('"actor":"auditLog.summary.unknownActor"')
  })
})
