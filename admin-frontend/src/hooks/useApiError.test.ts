// @vitest-environment jsdom
/**
 * The refusal an admin actually reads.
 *
 * #757: a 409 was rendered from the response's `message`, which the backend
 * writes in English for its log. On a German panel — the default — the one
 * sentence explaining why the action failed was the one sentence not in the
 * admin's language, with the amount formatted the English way too.
 */

import { act, renderHook } from '@testing-library/react'
import { AxiosError } from 'axios'
import { beforeEach, describe, expect, it } from 'vitest'

import { useApiError } from './useApiError'
import i18n from '../i18n/config'

/** A 409 exactly as `ErrorHandler` writes it. */
function refusal(reason: string, params?: Record<string, unknown>, message = 'English sentence'): AxiosError {
  const err = new AxiosError('Request failed with status code 409')
  err.response = {
    status: 409,
    statusText: 'Conflict',
    headers: {},
    config: { headers: undefined as never },
    data: { error: 'business_rule_violation', message, ...(params ? { reason, params } : { reason }) },
  }
  return err
}

function hook() {
  return renderHook(() => useApiError()).result.current
}

describe('useApiError', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('de')
  })

  it('says why in German, with the amount formatted the German way', () => {
    const { apiErrorMessage } = hook()
    const text = apiErrorMessage(refusal('member_balance_outstanding', { balance_cents: 750 }), 'Fallback')

    expect(text).toContain('offener Saldo')
    expect(text).toContain('7,50')
    expect(text).not.toContain('€7.50')
    expect(text).not.toContain('English sentence')
  })

  it('says the same thing in English when the panel is English', async () => {
    await i18n.changeLanguage('en')
    const { apiErrorMessage } = hook()
    const text = apiErrorMessage(refusal('member_balance_outstanding', { balance_cents: 750 }), 'Fallback')

    expect(text).toContain('outstanding balance')
    expect(text).toContain('€7.50')
  })

  it('shows a negative balance as the credit it is', () => {
    const { apiErrorMessage } = hook()
    expect(apiErrorMessage(refusal('member_balance_outstanding', { balance_cents: -1250 }), 'Fallback'))
      .toContain('-12,50')
  })

  it('renders a date param in the admin locale, not as ISO', () => {
    const { apiErrorMessage } = hook()
    const text = apiErrorMessage(refusal('settlement_execution_date_passed', { execution_date: '2026-08-01' }), 'F')

    expect(text).toContain('01.08.2026')
    expect(text).not.toContain('2026-08-01')
  })

  it('falls back to the caller\'s translated message, never to the English one', () => {
    const { apiErrorMessage } = hook()

    // A code this build has no wording for — an older panel, newer backend.
    expect(apiErrorMessage(refusal('a_reason_from_the_future'), 'Fallback')).toBe('Fallback')
    // And a failure that names no reason at all.
    expect(apiErrorMessage(new Error('boom'), 'Fallback')).toBe('Fallback')
  })

  it('keeps per-field validation messages, which name the rejected input', () => {
    const err = new AxiosError('Request failed with status code 422')
    err.response = {
      status: 422,
      statusText: 'Unprocessable Entity',
      headers: {},
      config: { headers: undefined as never },
      data: { error: 'validation_failed', messages: { email: ['E-Mail bereits vergeben'] } },
    }

    expect(hook().apiErrorMessage(err, 'Fallback')).toBe('E-Mail bereits vergeben')
  })

  it('keeps one identity across renders, so an effect that depends on it settles', async () => {
    // Every caller lists `apiErrorMessage` in its effect's dependency array —
    // the lint rule requires it. A helper rebuilt each render therefore
    // re-runs that effect each render, and a page whose effect aborts and
    // refetches forever renders an empty list. Closing over `useFormatters()`
    // did exactly that.
    const { result, rerender } = renderHook(() => useApiError())
    const first = result.current

    rerender()
    expect(result.current.apiErrorMessage).toBe(first.apiErrorMessage)
    expect(result.current.reasonText).toBe(first.reasonText)

    // A language switch is the one thing that must change them.
    await act(async () => {
      await i18n.changeLanguage('en')
    })
    expect(result.current.reasonText).not.toBe(first.reasonText)
  })

  it('translates a code that arrived inside a 200 — the gates report that way', () => {
    const { reasonText } = hook()

    expect(reasonText('settlement_already_cancelled', null, 'Fallback')).toContain('bereits storniert')
    expect(reasonText(null, null, 'Fallback')).toBe('Fallback')
    expect(reasonText('a_reason_from_the_future', {}, 'Fallback')).toBe('Fallback')
  })
})
