import { useCallback } from 'react'
import { useTranslation } from 'react-i18next'

import { formatDate, formatPrice } from '../styles/design-system'
import { getIntlLocale } from '../utils/i18n-helpers'
import { getApiErrorMessage, getApiErrorReason } from '../utils/apiErrors'

/**
 * The admin's own words for a refusal the backend reported.
 *
 * The backend answers a refused business rule with an English sentence in
 * `message` and, since #757, a `reason` code plus `params` beside it. The
 * sentence is for the log; the code is what a panel running in German turns
 * into German. Before this existed, `MembersPage` showed `message` verbatim,
 * so a German admin trying to anonymize a member with a tab open was told
 * "Cannot anonymize: outstanding balance of €7.50" — English text, and an
 * amount formatted the English way, on an otherwise German screen.
 *
 * Two entry points, one rule:
 *
 * - `apiErrorMessage(err, fallback)` for a thrown request.
 * - `reasonText(code, params, fallback)` for a code that arrived inside a
 *   normal 200 response — the settlement gates report why a button is
 *   disabled that way (`cancellation_blocked_code`).
 *
 * Both fall back to the caller's already-translated string when the reason is
 * absent or has no entry in the locale file. They never fall back to the
 * backend's English sentence: an untranslated sentence is the bug, and a
 * silent fallback to it would hide the next one.
 */
export function useApiError() {
  const { t, i18n } = useTranslation()

  // The locale *string*, not `useFormatters()`. That hook returns a fresh
  // object every render, so closing over its functions would give every
  // callback below a new identity each render — and a caller that lists
  // `apiErrorMessage` in an effect's dependencies (which the lint rule
  // requires) would re-run that effect forever, aborting its own fetch each
  // time. A language code changes when the language does, and not otherwise.
  const intlLocale = getIntlLocale(i18n.language)

  /**
   * Values as the sentence wants to read them, in the admin's locale.
   *
   * The backend sends values, never formatted prose (see `BusinessRuleReason`),
   * so the formatting happens here, where the locale is known:
   *
   * - `x_cents` gains a sibling `x` holding the formatted amount, so
   *   `{balance_cents: 750}` interpolates `{{balance}}` as `7,50 €` in German
   *   and `€7.50` in English. The raw cents stay available for pluralisation.
   * - `x_date` / `x_on` are replaced in place by the localised date: an ISO
   *   date is never what a sentence wants to show.
   */
  const localizeParams = useCallback(
    (params: Record<string, unknown>): Record<string, unknown> => {
      const out: Record<string, unknown> = { ...params }

      for (const [key, value] of Object.entries(params)) {
        if (key.endsWith('_cents') && typeof value === 'number') {
          out[key.slice(0, -'_cents'.length)] = formatPrice(value, intlLocale)
        } else if ((key.endsWith('_date') || key.endsWith('_on')) && typeof value === 'string' && value) {
          out[key] = formatDate(value, intlLocale)
        }
      }

      return out
    },
    [intlLocale],
  )

  /**
   * A reason code as a sentence, or `fallback` when this build has no wording
   * for it — an older panel against a newer backend, most likely.
   */
  const reasonText = useCallback(
    (code: string | null | undefined, params: Record<string, unknown> | null | undefined, fallback: string): string => {
      if (!code) return fallback

      // `defaultValue: ''` is how i18next is asked "do you know this key?"
      // without it echoing the key back as the answer.
      const text = t(`errors.reasons.${code}`, { ...localizeParams(params ?? {}), defaultValue: '' })
      return text || fallback
    },
    [t, localizeParams],
  )

  /** A failed request as a sentence the admin can read. */
  const apiErrorMessage = useCallback(
    (err: unknown, fallback: string): string => {
      const reason = getApiErrorReason(err)
      if (reason) {
        return reasonText(reason.code, reason.params, fallback)
      }

      // No reason code: a validation failure naming fields, or an error that
      // never reached the business layer. `getApiErrorMessage` still prefers
      // the per-field messages, which is the detail the admin needs.
      return getApiErrorMessage(err, fallback)
    },
    [reasonText],
  )

  return { apiErrorMessage, reasonText }
}
