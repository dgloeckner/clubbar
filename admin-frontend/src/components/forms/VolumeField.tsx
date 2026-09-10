/**
 * A product-size field that speaks the panel's language.
 *
 * A product's size is data on the product rather than part of its translated
 * name (ADR-0056), and the wire carries whole millilitres. Nobody types a beer
 * as `500`, though: a Getränkewart thinks in `0,5`, and in a German panel
 * writes it with a comma.
 *
 * So the field mirrors `MoneyField`, which answered the same question for euro
 * amounts, and keeps the same three properties:
 *
 * - **The locale decides the separator**, read from `Intl` rather than from a
 *   table of our own, and both separators are *accepted* whichever language is
 *   on — a numeric keypad emits a dot in German, and an admin who learned the
 *   panel in German types a comma into the English one.
 * - **Canonical on the wire, locale on screen.** The page holds whole
 *   millilitres (or `null`), which is what the API takes; the litres are a
 *   rendering, and the canonical value is also in a hidden input for the E2E
 *   suite to assert on.
 * - **The mask is not the validator.** A size above ten litres reaches the
 *   page's own refusal beside the field rather than being silently clamped.
 *
 * Styling comes from the caller (`style`), like `MoneyField`: the component is
 * about the *value*, not the chrome.
 */

import { useEffect, useMemo, useRef, useState, type CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import { getIntlLocale } from '../../utils/i18n-helpers'
import {
  buildVolumePlaceholder,
  formatCanonicalLitresForDisplay,
  getVolumeFormat,
  maskVolumeInput,
  millilitresToCanonicalLitres,
  parseLitresToMillilitres,
  toCanonicalLitres,
} from '../../utils/volume'

export interface VolumeFieldProps {
  /** Whole millilitres, or `null` when the product has no size. */
  value: number | null
  /** Called with whole millilitres, or `null` when the field is empty. */
  onChange: (millilitres: number | null) => void
  /** Base for the field's test ids; the text input carries it unchanged. */
  testId: string
  id?: string
  /** Overrides the locale's example size. */
  placeholder?: string
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

export function VolumeField({
  value,
  onChange,
  testId,
  id,
  placeholder,
  disabled = false,
  invalid = false,
  ariaLabel,
  describedBy,
  name,
  style,
  onBlur,
}: VolumeFieldProps) {
  const { i18n } = useTranslation()
  const spec = useMemo(() => getVolumeFormat(getIntlLocale(i18n.language)), [i18n.language])

  const [text, setText] = useState(() =>
    formatCanonicalLitresForDisplay(millilitresToCanonicalLitres(value), spec),
  )

  // The field is controlled by the page, so a value set elsewhere (loading a
  // product, resetting the form) has to reach the text — but not mid-keystroke,
  // which would fight the user for the caret.
  const isEditing = useRef(false)
  useEffect(() => {
    if (isEditing.current) return
    setText(formatCanonicalLitresForDisplay(millilitresToCanonicalLitres(value), spec))
  }, [value, spec])

  const handleChange = (raw: string) => {
    isEditing.current = true
    const masked = maskVolumeInput(raw, spec)
    setText(masked)
    onChange(parseLitresToMillilitres(toCanonicalLitres(masked, spec)))
  }

  const handleBlur = () => {
    isEditing.current = false
    // Rewrite whatever survived the mask as the stored value reads, so a typed
    // `0,500` settles to `0,5` and unparseable leftovers clear rather than
    // sitting on screen contradicting the value the form holds.
    const millilitres = parseLitresToMillilitres(toCanonicalLitres(text, spec))
    setText(formatCanonicalLitresForDisplay(millilitresToCanonicalLitres(millilitres), spec))
    if (millilitres !== value) onChange(millilitres)
    onBlur?.()
  }

  return (
    <>
      <input
        id={id}
        name={name}
        data-testid={testId}
        // Text, not `number`: that control accepts one decimal separator
        // whatever the page's language is, and reports anything else as an
        // empty string (#863). `decimal` is what puts a separator on a phone's
        // keypad without bringing the browser's own parsing with it.
        type="text"
        inputMode="decimal"
        autoComplete="off"
        value={text}
        onChange={(e) => handleChange(e.target.value)}
        onBlur={handleBlur}
        placeholder={placeholder ?? buildVolumePlaceholder(spec)}
        disabled={disabled}
        aria-invalid={invalid || undefined}
        aria-label={ariaLabel}
        aria-describedby={describedBy}
        style={style}
      />

      {/*
        The millilitres the API will receive, for anything reading the field
        rather than typing into it — a plain form post, and the E2E suite, which
        asserts on the value that travels rather than on the locale's rendering
        of it.
      */}
      <input
        type="hidden"
        data-testid={`${testId}-value`}
        value={value === null ? '' : String(value)}
        readOnly
      />
    </>
  )
}
