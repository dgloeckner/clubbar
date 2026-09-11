/**
 * A product's size, picked from the sizes a club pours.
 *
 * The size is data on the product rather than part of its translated name
 * (ADR-0056), and the wire carries whole millilitres. This control is where an
 * admin sets it, and it is a plain `<select>` on purpose:
 *
 * - **The list is the validator.** Every option is a whole number of
 *   millilitres inside the API's range, so there is no decimal separator to
 *   read — which is what the typed field it replaces existed to get right
 *   (#863), and what it could still get wrong in the other direction: `50`
 *   litres for a half-litre glass passed the mask and reached a refusal.
 * - **Millilitres on the wire, litres on screen.** The option's value *is* what
 *   the API receives; the preview beside the field shows the same number the
 *   way a member will read it — `0,5 l` — which is the point of storing one
 *   language-neutral number.
 * - **A size from outside the list is kept.** A product saved when sizes were
 *   typed can hold 750 ml; `volumeOptionsFor` offers it alongside the presets
 *   so opening such a product and saving it does not quietly clear its size.
 *
 * Styling comes from the caller (`style`), like `MoneyField`: the component is
 * about the *value*, not the chrome. So is the wording — `emptyLabel` is passed
 * in, so the control carries no translation keys of its own.
 */

import type { CSSProperties } from 'react'
import { formatMillilitres } from '../../styles/design-system'
import { parseVolumeOption, volumeOptionsFor } from '../../utils/volume'

export interface VolumeSelectProps {
  /** Whole millilitres, or `null` when the product has no size. */
  value: number | null
  /** Called with whole millilitres, or `null` when the empty option is chosen. */
  onChange: (millilitres: number | null) => void
  /** Base for the control's test ids; the select carries it unchanged. */
  testId: string
  /** The empty option's wording — "this product has no size". */
  emptyLabel: string
  id?: string
  disabled?: boolean
  /** The page has an error for this field, so `aria-invalid` is set. */
  invalid?: boolean
  ariaLabel?: string
  /** Extra ids for `aria-describedby`, e.g. the page's hint paragraph. */
  describedBy?: string
  name?: string
  style?: CSSProperties
  onBlur?: () => void
}

export function VolumeSelect({
  value,
  onChange,
  testId,
  emptyLabel,
  id,
  disabled = false,
  invalid = false,
  ariaLabel,
  describedBy,
  name,
  style,
  onBlur,
}: VolumeSelectProps) {
  const options = volumeOptionsFor(value)

  return (
    <>
      <select
        id={id}
        name={name}
        data-testid={testId}
        value={value === null ? '' : String(value)}
        onChange={(e) => onChange(parseVolumeOption(e.target.value))}
        onBlur={onBlur}
        disabled={disabled}
        aria-invalid={invalid || undefined}
        aria-label={ariaLabel}
        aria-describedby={describedBy}
        style={style}
      >
        {/* First, and the default: a drinks list has snacks on it. */}
        <option value="">{emptyLabel}</option>
        {options.map((millilitres) => (
          <option key={millilitres} value={millilitres}>
            {formatMillilitres(millilitres)}
          </option>
        ))}
      </select>

      {/*
        The millilitres the API will receive. Redundant with the select's own
        value, and kept anyway: every other value control in the panel
        (`MoneyField`, `DateField`) exposes `{testId}-value`, and the E2E suite
        asserts on that name rather than on a per-control shape.
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
