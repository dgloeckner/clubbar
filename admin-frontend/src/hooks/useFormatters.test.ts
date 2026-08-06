// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useFormatters } from './useFormatters'
import { toIsoDate } from '../utils/dates'

const i18nState = vi.hoisted(() => ({ language: 'de' }))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    i18n: { language: i18nState.language },
    // Return the key so assertions can target the translation key itself
    // without coupling the test to locale JSON contents.
    t: (key: string) => key,
  }),
}))

/** Intl inserts non-breaking spaces; normalize for readable assertions. */
function plain(formatted: string): string {
  return formatted.replace(/[\u00a0\u202f]/g, ' ')
}

function withLanguage(language: string) {
  i18nState.language = language
  return renderHook(() => useFormatters()).result.current
}

describe('useFormatters', () => {
  describe('locale selection', () => {
    it('maps the i18n language to an Intl locale', () => {
      expect(withLanguage('de').intlLocale).toBe('de-DE')
      expect(withLanguage('en').intlLocale).toBe('en-GB')
    })

    it('exposes the raw language code', () => {
      expect(withLanguage('en').language).toBe('en')
    })
  })

  describe('formatPrice', () => {
    it('formats cents as German EUR currency', () => {
      expect(plain(withLanguage('de').formatPrice(350))).toBe('3,50 €')
    })

    it('formats cents as British EUR currency', () => {
      expect(plain(withLanguage('en').formatPrice(350))).toBe('€3.50')
    })

    it('formats negative amounts', () => {
      expect(plain(withLanguage('de').formatPrice(-1250))).toBe('-12,50 €')
    })
  })

  describe('formatDate', () => {
    it('formats dates in German order with dots', () => {
      expect(withLanguage('de').formatDate('2026-08-05')).toBe('05.08.2026')
    })

    it('formats dates in British order with slashes', () => {
      expect(withLanguage('en').formatDate('2026-08-05')).toBe('05/08/2026')
    })
  })

  describe('formatRelativeDate', () => {
    it('returns the "never" label for an empty date', () => {
      expect(withLanguage('de').formatRelativeDate('')).toBe('dates.never')
    })

    it('labels today as today', () => {
      const today = toIsoDate(new Date())
      expect(withLanguage('de').formatRelativeDate(today)).toBe('dates.today')
    })

    it('labels yesterday as yesterday', () => {
      const yesterday = new Date()
      yesterday.setDate(yesterday.getDate() - 1)
      expect(withLanguage('de').formatRelativeDate(toIsoDate(yesterday))).toBe(
        'dates.yesterday'
      )
    })

    it('falls back to the full date for anything older', () => {
      expect(withLanguage('de').formatRelativeDate('2020-02-01')).toBe(
        '01.02.2020'
      )
    })
  })
})
