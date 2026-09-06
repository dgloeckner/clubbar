/**
 * StoredFieldBox — the read-only box that stands in for a form input when the
 * value is already on file and is not routinely retyped.
 *
 * Two member fields need this shape (#392):
 *
 * - the IBAN, which is sealed and can never be read back (ADR-0036), so the
 *   input can only ever *overwrite* it; and
 * - the mandate reference, which the server mints when the mandate is opened
 *   (ADR-0006) and which is then the identifier the bank quotes back on a
 *   return.
 *
 * Both used to be plain inputs standing empty next to a grey example value,
 * which reads as "this is what is stored, greyed out" — the opposite of the
 * truth. Showing the stored value *in place of* the input, with the actions
 * that can change it, states the same rule without a sentence of explanation.
 *
 * The actions sit **inside** the box, right-aligned, so the row is exactly as
 * tall as an input (#830). Underneath they cost a whole line each, on two of
 * the member dialog's fields, in a dialog whose *Speichern* button was below
 * the fold. `actionsBelow` keeps the old placement for actions too long to
 * fit — a pending-removal notice, a cancel that names the value it restores.
 *
 * It is deliberately not an `<input readOnly>`: a disabled-looking field still
 * invites typing, and a focusable one lands in the tab order for no reason.
 */

import { ReactNode } from 'react'
import { theme } from '../../styles/design-system'

export interface StoredFieldBoxProps {
  /** The value to show, already in display form. */
  value: ReactNode
  /** Rendered dimmer and in italics — for "will be assigned on save". */
  pending?: boolean
  /** Buttons/links rendered inside the box, right-aligned. */
  actions?: ReactNode
  /** Buttons/links rendered under the box, for text too long to sit inside. */
  actionsBelow?: ReactNode
  monospace?: boolean
  testId?: string
}

export function StoredFieldBox({
  value,
  pending = false,
  actions,
  actionsBelow,
  monospace = true,
  testId,
}: StoredFieldBoxProps) {
  return (
    <>
      <div
        data-testid={testId}
        style={{
          width: '100%',
          padding: `${theme.spacing.md} ${theme.spacing.lg}`,
          background: theme.colors.bg.input,
          border: `1px solid ${theme.colors.border.light}`,
          borderRadius: theme.borderRadius.md,
          color: pending ? theme.colors.text.secondary : theme.colors.text.primary,
          fontStyle: pending ? 'italic' : 'normal',
          fontFamily: monospace && !pending ? 'monospace' : theme.typography.fontFamily.base,
          boxSizing: 'border-box',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: theme.spacing.md,
          // Long values (a 32-character mandate reference) must wrap rather
          // than widen the modal's grid column.
          overflowWrap: 'anywhere',
          minHeight: '3rem',
        }}
      >
        <span
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: theme.spacing.md,
            minWidth: 0,
            overflowWrap: 'anywhere',
          }}
        >
          {value}
        </span>

        {actions && (
          <span
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: theme.spacing.md,
              flex: '0 0 auto',
              fontFamily: theme.typography.fontFamily.base,
              fontStyle: 'normal',
              fontSize: theme.typography.fontSize.sm,
              color: theme.colors.text.secondary,
            }}
          >
            {actions}
          </span>
        )}
      </div>

      {actionsBelow && (
        <div
          style={{
            display: 'flex',
            flexWrap: 'wrap',
            alignItems: 'center',
            gap: theme.spacing.md,
            marginTop: theme.spacing.xs,
            fontSize: theme.typography.fontSize.sm,
            color: theme.colors.text.secondary,
          }}
        >
          {actionsBelow}
        </div>
      )}
    </>
  )
}

/**
 * The underlined text buttons that sit under a `StoredFieldBox`. They are
 * always `type="button"` — inside a form, the default `submit` would make
 * "Change" save the member.
 */
export function StoredFieldAction({
  onClick,
  tone = 'neutral',
  testId,
  children,
}: {
  onClick: () => void
  tone?: 'neutral' | 'danger' | 'primary'
  testId?: string
  children: ReactNode
}) {
  const color =
    tone === 'danger'
      ? theme.colors.semantic.danger
      : tone === 'primary'
        ? theme.colors.semantic.primary
        : theme.colors.text.secondary

  return (
    <button
      type="button"
      data-testid={testId}
      onClick={onClick}
      style={{
        background: 'none',
        border: 'none',
        padding: 0,
        color,
        cursor: 'pointer',
        font: 'inherit',
        textDecoration: 'underline',
      }}
    >
      {children}
    </button>
  )
}
