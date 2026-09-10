/**
 * An amount field that speaks the panel's language.
 *
 * Replaces `<input type="number">` on every euro amount in the panel. That
 * control is not locale-aware: whatever language the page is in, its value
 * sanitisation accepts the dot and nothing else, so a German admin typing
 * `3,50` — the way German writes a price, and the way the panel *displays*
 * every price — handed the form an empty string. The field looked filled, the
 * form refused to save, and nothing on screen said why. German is the panel's
 * default language, so that was the default experience of typing a price.
 *
 * The design mirrors `DateField`, which answered the same question for dates:
 *
 * - **The locale decides the separator**, read from `Intl` rather than from a
 *   table of our own, and both separators are *accepted* whichever language is
 *   on — a numeric keypad emits a dot, and an admin who learned the panel in
 *   German types a comma into the English one.
 * - **The value never becomes ambiguous**: pages hold canonical dot-decimal
 *   text (`"3.50"`, `""` for empty — the form `toFixed(2)` produces and the
 *   cents parsers take), localised text is only ever on screen, and the
 *   canonical value is also in a hidden input for anything reading the field
 *   rather than typing into it.
 * - **The mask is not the validator.** A negative amount reaches the page's own
 *   refusal beside the field instead of being silently made positive.
 *
 * Styling comes from the caller (`inputStyle`), because the three pages that
 * hold amounts each dress their inputs to match the form around them and this
 * component is about the *value*, not the chrome.
 */

import { useEffect, useMemo, useRef, useState, type CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import { getIntlLocale } from '../../utils/i18n-helpers'
import {
  buildMoneyPlaceholder,
  formatCanonicalForDisplay,
  getMoneyFormat,
  maskMoneyInput,
  normaliseCanonicalMoney,
  toCanonicalMoney,
} from '../../utils/money'

export interface MoneyFieldProps {
  /** Canonical dot-decimal text, or `''` for empty. */
  value: string
  /** Called with canonical dot-decimal text, or `''` when the field is empty. */
  onChange: (canonical: string) => void
  /** Base for the field's test ids; the text input carries it unchanged. */
  testId: string
  id?: string
  /** Overrides the locale's example amount. */
  placeholder?: string
  required?: boolean
  disabled?: boolean
  /** The page has an error for this field, so `aria-invalid` is set. */
  invalid?: boolean
  ariaLabel?: string
  /** Extra ids for `aria-describedby`, e.g. the page's error paragraph. */
  describedBy?: string
  name?: string
  style?: CSSProperties
  onBlur?: () => void
}

export function MoneyField({
  value,
  onChange,
  testId,
  id,
  placeholder,
  required = false,
  disabled = false,
  invalid = false,
  ariaLabel,
  describedBy,
  name,
  style,
  onBlur,
}: MoneyFieldProps) {
  const { i18n } = useTranslation()
  const spec = useMemo(() => getMoneyFormat(getIntlLocale(i18n.language)), [i18n.language])

  const [text, setText] = useState(() => formatCanonicalForDisplay(value, spec))

  // The field is controlled by the page, so a value set elsewhere (loading a
  // product, resetting the form) has to reach the text — but not mid-keystroke,
  // which would fight the user for the caret.
  const isEditing = useRef(false)
  useEffect(() => {
    if (isEditing.current) return
    setText(formatCanonicalForDisplay(value, spec))
  }, [value, spec])

  const handleChange = (raw: string) => {
    isEditing.current = true
    const masked = maskMoneyInput(raw, spec)
    setText(masked)
    onChange(toCanonicalMoney(masked, spec))
  }

  const handleBlur = () => {
    isEditing.current = false
    const canonical = normaliseCanonicalMoney(toCanonicalMoney(text, spec))
    setText(formatCanonicalForDisplay(canonical, spec))
    if (canonical !== value) onChange(canonical)
    onBlur?.()
  }

  return (
    <>
      <input
        id={id}
        name={name}
        data-testid={testId}
        // Text, not `number`: see the note at the top of this file. `decimal`
        // is what puts a separator on a phone's keypad without bringing the
        // browser's own parsing with it.
        type="text"
        inputMode="decimal"
        autoComplete="off"
        value={text}
        onChange={(e) => handleChange(e.target.value)}
        onBlur={handleBlur}
        placeholder={placeholder ?? buildMoneyPlaceholder(spec)}
        required={required}
        disabled={disabled}
        aria-invalid={invalid || undefined}
        aria-label={ariaLabel}
        aria-describedby={describedBy}
        style={style}
      />

      {/*
        The canonical amount, for anything reading the field rather than typing
        into it: a plain form post, and the E2E suite, which asserts on the
        value the API will receive rather than on the locale's rendering of it.
      */}
      <input type="hidden" data-testid={`${testId}-value`} value={value} readOnly />
    </>
  )
}
