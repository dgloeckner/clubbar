/**
 * FieldLabel — a form label that says what the field is *for*, not just what
 * it is called.
 *
 * The member form used to mark its mandatory fields with a `*` appended to the
 * label text: same colour, same size, same weight as the label it hangs off,
 * in a two-column grid where half the fields are optional (#629). It is a
 * lookup key for a convention, not something the eye can scan.
 *
 * The first answer marked *every* tier, in colour: an amber pill that turned
 * green once the field was filled, and a blue pill on the conditional ones
 * naming what they unlock. That made a completed member form a field of green
 * and blue pills saying, at length, that nothing was wrong — five ticks, a
 * green requirements panel and a green SEPA alert, all in the same dialog
 * (#830). Nothing was emphasised, because everything was.
 *
 * So a marker now means one thing: **this field is why the status strip is not
 * green.**
 *
 *   required, empty  — an orange "Pflicht" pill, paired with an orange border
 *                      on the input itself
 *   required, filled — nothing at all. Green is the outcome in the strip, not
 *                      a tick per field
 *   conditional      — a quiet muted note naming the capability ("für
 *                      Terminal"). The strip's tile explains the dependency
 *                      when it actually matters; the note is only there so the
 *                      field is not mistaken for decoration
 *   optional         — muted text, deliberately the quietest of the four
 *
 * The marker text is inside the `<label>`, so it is part of the field's
 * accessible name rather than a colour a screen reader cannot see; the glyph
 * in front of it is decorative and hidden. Callers pair this with
 * `aria-required` on the control itself.
 */

import { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { FieldInfo } from './FieldInfo'

export type FieldRequirement = 'required' | 'optional' | 'conditional'

export interface FieldLabelProps {
  /** The control's `id`. Omitted only where the control is not a single input. */
  htmlFor?: string
  label: string
  requirement: FieldRequirement
  /**
   * `required`: whether the field currently carries a value. A satisfied field
   * carries no marker; only a missing one does.
   */
  satisfied?: boolean
  /**
   * `conditional`: the already-translated note, naming what the value unlocks
   * (e.g. "for the terminal").
   */
  unlocks?: string
  /**
   * `optional`: already-translated qualifier shown instead of the bare word,
   * e.g. "SEPA, optional".
   */
  optionalNote?: string
  /**
   * The long explanation, behind an info icon beside the label. The short form
   * belongs in the field's placeholder — this is for what does not fit there.
   */
  info?: string
  /** Rendered after the marker — a validation tick, a lock, a stored-value note. */
  children?: ReactNode
  /** Base test id; the marker itself gets `${testId}-marker`. */
  testId?: string
}

export function FieldLabel({
  htmlFor,
  label,
  requirement,
  satisfied = false,
  unlocks,
  optionalNote,
  info,
  children,
  testId,
}: FieldLabelProps) {
  const { t } = useTranslation()

  return (
    <label
      htmlFor={htmlFor}
      data-testid={testId}
      style={{
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        gap: theme.spacing.sm,
        marginBottom: theme.spacing.sm,
        fontSize: theme.typography.fontSize.sm,
        fontWeight: theme.typography.fontWeight.semibold,
      }}
    >
      {label}

      {requirement === 'required' && !satisfied && (
        <span
          data-testid={testId ? `${testId}-marker` : undefined}
          data-state="open"
          style={{
            display: 'inline-flex',
            alignItems: 'center',
            padding: `2px ${theme.spacing.sm}`,
            borderRadius: theme.borderRadius.full,
            background: theme.badges.warning.bg,
            border: `1px solid ${theme.badges.warning.border}`,
            color: theme.badges.warning.text,
            fontSize: theme.typography.fontSize.xs,
            fontWeight: theme.typography.fontWeight.semibold,
            lineHeight: theme.typography.lineHeight.tight,
            whiteSpace: 'nowrap',
          }}
        >
          {t('common.mandatory')}
        </span>
      )}

      {requirement === 'conditional' && unlocks && (
        <span
          data-testid={testId ? `${testId}-marker` : undefined}
          data-state="conditional"
          style={{
            color: theme.colors.text.muted,
            fontWeight: theme.typography.fontWeight.normal,
          }}
        >
          {unlocks}
        </span>
      )}

      {requirement === 'optional' && (
        <span
          data-testid={testId ? `${testId}-marker` : undefined}
          data-state="optional"
          style={{
            color: theme.colors.text.secondary,
            fontWeight: theme.typography.fontWeight.normal,
          }}
        >
          {optionalNote ?? t('common.optional')}
        </span>
      )}

      {info && <FieldInfo content={info} testId={testId ? `${testId}-info` : undefined} />}

      {children}
    </label>
  )
}
