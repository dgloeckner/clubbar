/**
 * FieldLabel — a form label that says what the field is *for*, not just what
 * it is called.
 *
 * The member form used to mark its mandatory fields with a `*` appended to the
 * label text: same colour, same size, same weight as the label it hangs off,
 * in a two-column grid where half the fields are optional (#629). It is a
 * lookup key for a convention, not something the eye can scan, and it says the
 * same thing about a field the API refuses the member without as it would
 * about one that merely switches a feature on.
 *
 * Three requirement tiers, each with its own marker:
 *
 *   required     — a pill that is amber while the field is empty and green
 *                  once it is filled, so the form visibly converges as it is
 *                  completed and an unfinished field is findable at a glance
 *   conditional  — a pill naming the capability the value unlocks ("required
 *                  for terminal access"), which is what an optional-but-load-
 *                  bearing field actually means
 *   optional     — muted text, deliberately the quietest of the three
 *
 * The marker text is inside the `<label>`, so it is part of the field's
 * accessible name rather than a colour a screen reader cannot see; the glyph
 * in front of it is decorative and hidden. Callers pair this with
 * `aria-required` on the control itself.
 */

import { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'

export type FieldRequirement = 'required' | 'optional' | 'conditional'

export interface FieldLabelProps {
  /** The control's `id`. Omitted only where the control is not a single input. */
  htmlFor?: string
  label: string
  requirement: FieldRequirement
  /**
   * `required`: whether the field currently carries a value. Drives the
   * amber → green switch; ignored by the other tiers.
   */
  satisfied?: boolean
  /**
   * `conditional`: the already-translated marker text, naming what the value
   * unlocks (e.g. "required for terminal access").
   */
  unlocks?: string
  /**
   * `optional`: already-translated qualifier shown instead of the bare word,
   * e.g. "SEPA, optional".
   */
  optionalNote?: string
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

      {requirement === 'required' && (
        <RequirementPill
          tone={satisfied ? 'success' : 'warning'}
          glyph={satisfied ? '✓' : '!'}
          text={t('common.mandatory')}
          testId={testId ? `${testId}-marker` : undefined}
          state={satisfied ? 'satisfied' : 'open'}
        />
      )}

      {requirement === 'conditional' && unlocks && (
        <RequirementPill
          tone="info"
          glyph="→"
          text={unlocks}
          testId={testId ? `${testId}-marker` : undefined}
          state="conditional"
        />
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

      {children}
    </label>
  )
}

function RequirementPill({
  tone,
  glyph,
  text,
  testId,
  state,
}: {
  tone: 'warning' | 'success' | 'info'
  glyph: string
  text: string
  testId?: string
  state: string
}) {
  const badge = theme.badges[tone]

  return (
    <span
      data-testid={testId}
      data-state={state}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: theme.spacing.xs,
        padding: `2px ${theme.spacing.sm}`,
        borderRadius: theme.borderRadius.full,
        background: badge.bg,
        border: `1px solid ${badge.border}`,
        color: badge.text,
        fontSize: theme.typography.fontSize.xs,
        fontWeight: theme.typography.fontWeight.semibold,
        lineHeight: theme.typography.lineHeight.tight,
        whiteSpace: 'nowrap',
      }}
    >
      <span aria-hidden="true">{glyph}</span>
      {text}
    </span>
  )
}
