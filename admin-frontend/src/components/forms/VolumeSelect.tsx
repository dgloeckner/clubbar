/**
 * A product's size: picked from the sizes a club pours, or typed when it is not
 * one of them.
 *
 * The size is data on the product rather than part of its translated name
 * (ADR-0056), and the wire carries whole millilitres. This control is where an
 * admin sets it.
 *
 * - **The list is the validator, on the path almost every product takes.**
 *   Every option is a whole number of millilitres inside the API's range, so
 *   there is no decimal separator to read — which is what the typed *litres*
 *   field it replaced existed to get right (#863), and what it could still get
 *   wrong in the other direction: `50` litres for a half-litre glass passed the
 *   mask and reached a refusal.
 * - **A list cannot be complete, so the last option opens a field.** A club
 *   that pours a 0,7 l Schnapsflasche or a 1,5 l PET bottle has to be able to
 *   say so; a picker with no answer sends the size back into the product name,
 *   which is the habit ADR-0056 exists to end. The field is safe to type into
 *   for the reason the litres one was not — a millilitre is a whole number, so
 *   `maskVolumeInput` can simply refuse every character that is not a digit.
 * - **Millilitres on the wire, litres on screen.** The option's value *is* what
 *   the API receives; the preview beside the field shows the same number the
 *   way a member will read it — `0,5 l` — which is the point of storing one
 *   language-neutral number. It is also the guard on the typed field: a size
 *   entered in litres out of habit reads back as `5 ml`, in the place the
 *   admin is already looking.
 * - **A size the list does not contain opens the field, filled in.** A product
 *   saved when sizes were typed can hold 750 ml or 700 ml; the control notices
 *   that the value is not a preset and switches itself to the typed field, so
 *   opening such a product shows its size, lets it be corrected, and cannot
 *   quietly clear the column on the next unrelated save.
 *
 * Styling comes from the caller (`style`), like `MoneyField`: the component is
 * about the *value*, not the chrome. So is the wording — every label is passed
 * in, so the control carries no translation keys of its own.
 */

import { useState, type CSSProperties } from 'react'
import { formatMillilitres } from '../../styles/design-system'
import {
  isPresetVolume,
  maskVolumeInput,
  parseVolumeOption,
  VOLUME_CUSTOM_OPTION,
  VOLUME_PRESETS_ML,
} from '../../utils/volume'

export interface VolumeSelectProps {
  /** Whole millilitres, or `null` when the product has no size. */
  value: number | null
  /** Called with whole millilitres, or `null` when there is no size yet. */
  onChange: (millilitres: number | null) => void
  /** Base for the control's test ids; the select carries it unchanged. */
  testId: string
  /** The empty option's wording — "this product has no size". */
  emptyLabel: string
  /** The last option's wording — "not on the list, let me type it". */
  customLabel: string
  /** The typed field's accessible name, e.g. "Size in millilitres". */
  customFieldLabel: string
  /** The typed field's placeholder, e.g. "e.g. 700". */
  customPlaceholder?: string
  id?: string
  disabled?: boolean
  /** The page has an error for this field, so `aria-invalid` is set. */
  invalid?: boolean
  ariaLabel?: string
  /** Extra ids for `aria-describedby`, e.g. the page's hint paragraph. */
  describedBy?: string
  name?: string
  style?: CSSProperties
  /**
   * The typed field's box, when it differs from the select's.
   *
   * It usually does: a native `<select>` draws its own chevron inside the
   * padding box, so callers leave a gutter on the right that a text input has
   * no use for. Falls back to `style`.
   */
  customStyle?: CSSProperties
  onBlur?: () => void
}

export function VolumeSelect({
  value,
  onChange,
  testId,
  emptyLabel,
  customLabel,
  customFieldLabel,
  customPlaceholder,
  id,
  disabled = false,
  invalid = false,
  ariaLabel,
  describedBy,
  name,
  style,
  customStyle,
  onBlur,
}: VolumeSelectProps) {
  /**
   * The admin asked to type rather than pick.
   *
   * Only half the answer, on purpose: a *value* that is not a preset means the
   * field is open whatever this says. Deriving it that way rather than syncing
   * state to `value` in an effect is what makes the control correct when the
   * same instance is handed a different product — a select showing `700` with
   * no such option renders blank, and the next save clears a column nobody
   * touched. The state only has to carry the one case the value cannot: the
   * field is open and still empty.
   */
  const [typing, setTyping] = useState(false)

  const custom = typing || (value !== null && !isPresetVolume(value))

  function handleSelect(raw: string) {
    if (raw === VOLUME_CUSTOM_OPTION) {
      // Whatever was picked stays in the field, so switching to type `550`
      // after picking `500` starts from `500` rather than from nothing.
      setTyping(true)
      return
    }

    setTyping(false)
    onChange(parseVolumeOption(raw))
  }

  return (
    <>
      <select
        id={id}
        name={name}
        data-testid={testId}
        value={custom ? VOLUME_CUSTOM_OPTION : value === null ? '' : String(value)}
        onChange={(e) => handleSelect(e.target.value)}
        // Blur belongs to whichever control holds the value, so a caller that
        // validates on blur sees the field the admin actually left — moving
        // from the picker into the field it just opened is not leaving the
        // control.
        onBlur={custom ? undefined : onBlur}
        disabled={disabled}
        aria-invalid={(invalid && !custom) || undefined}
        aria-label={ariaLabel}
        aria-describedby={describedBy}
        style={style}
      >
        {/* First, and the default: a drinks list has snacks on it. */}
        <option value="">{emptyLabel}</option>
        {VOLUME_PRESETS_ML.map((millilitres) => (
          <option key={millilitres} value={millilitres}>
            {formatMillilitres(millilitres)}
          </option>
        ))}
        {/* Last: the sizes a club reaches for come first, and the escape hatch
            sits after them rather than competing with them. */}
        <option value={VOLUME_CUSTOM_OPTION}>{customLabel}</option>
      </select>

      {custom && (
        <input
          data-testid={`${testId}-custom`}
          // Text, not `number`, for the reason at the top of `MoneyField`: a
          // native number input reports anything it dislikes as the empty
          // string, so a rejected character would be indistinguishable from a
          // cleared field. `numeric` is the keypad without a separator on it —
          // there is no separator in a millilitre.
          type="text"
          inputMode="numeric"
          autoComplete="off"
          // Driven straight from the value, with no draft of its own: the text
          // and the number can then never disagree, which is the bug a second
          // copy of the same state exists to have.
          value={value === null ? '' : String(value)}
          onChange={(e) => onChange(parseVolumeOption(maskVolumeInput(e.target.value)))}
          onBlur={onBlur}
          disabled={disabled}
          aria-invalid={invalid || undefined}
          aria-label={customFieldLabel}
          aria-describedby={describedBy}
          placeholder={customPlaceholder}
          style={{ ...(customStyle ?? style), marginTop: '8px' }}
        />
      )}

      {/*
        The millilitres the API will receive. Redundant with the controls' own
        values, and kept anyway: every other value control in the panel
        (`MoneyField`, `DateField`) exposes `{testId}-value`, and the E2E suite
        asserts on that name rather than on a per-control shape — which matters
        more here than anywhere, because the size now has two controls and three
        renderings (`500`, `500 ml`, `0,5 l`).
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
