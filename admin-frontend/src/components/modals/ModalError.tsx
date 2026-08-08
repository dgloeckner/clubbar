/**
 * Modal error display
 *
 * A failure raised from an open modal is shown inside that modal: the
 * page-level banner sits behind the overlay, so it would only be read later,
 * on whichever tab happens to be open by then (#91).
 */

import { theme } from '../../styles/design-system'

export interface ModalErrorProps {
  message: string | null | undefined
  testId: string
}

/** Banner across the top of the modal body, above the inputs. */
export function ModalError({ message, testId }: ModalErrorProps) {
  if (!message) {
    return null
  }

  return (
    <div
      data-testid={testId}
      role="alert"
      style={{
        marginBottom: theme.spacing.lg,
        padding: theme.spacing.md,
        background: theme.badges.danger.bg,
        borderLeft: `3px solid ${theme.badges.danger.dot}`,
        borderRadius: theme.borderRadius.md,
        color: theme.badges.danger.text,
        fontSize: theme.typography.fontSize.sm,
      }}
    >
      {message}
    </div>
  )
}

/** Message under the input that was rejected. */
export function FieldError({ message, testId }: ModalErrorProps) {
  if (!message) {
    return null
  }

  return (
    <p
      data-testid={testId}
      style={{
        margin: `0 0 ${theme.spacing.md} 0`,
        fontSize: theme.typography.fontSize.xs,
        color: theme.colors.semantic.danger,
      }}
    >
      {message}
    </p>
  )
}

/** Input styling that marks a rejected field. */
export function modalInputStyle(hasError: boolean): React.CSSProperties {
  return {
    width: '100%',
    padding: theme.spacing.md,
    marginBottom: hasError ? theme.spacing.xs : theme.spacing.md,
    border: `1px solid ${hasError ? theme.colors.semantic.danger : theme.colors.border.light}`,
    borderRadius: theme.borderRadius.md,
    boxSizing: 'border-box',
    background: theme.colors.bg.secondary,
    color: theme.colors.text.primary,
  }
}
