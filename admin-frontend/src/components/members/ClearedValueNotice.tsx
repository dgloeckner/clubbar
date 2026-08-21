/**
 * ClearedValueNotice — "this save deletes what is stored here", said at the
 * field rather than after the fact.
 *
 * Since #131 a blank optional input on an edit reaches the API as an explicit
 * `null`, so emptying the card UID revokes a member's terminal access and
 * emptying the mandate date deletes it. Both are real deletions and neither
 * said anything: the field simply looked empty, the way an unfilled field
 * looks empty.
 *
 * The banking fields already got the honest treatment in #392 — the stored
 * value shown in place of the input, removal as its own action with an undo.
 * This is the same bargain for the fields that are ordinary inputs: name the
 * value that is about to go, and offer it back in one click.
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'

export interface ClearedValueNoticeProps {
  /** The stored value this save would delete, already in display form. */
  previous: string
  onRestore: () => void
  testId: string
}

export function ClearedValueNotice({ previous, onRestore, testId }: ClearedValueNoticeProps) {
  const { t } = useTranslation()

  return (
    <p
      data-testid={testId}
      style={{
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        gap: theme.spacing.sm,
        margin: 0,
        marginTop: theme.spacing.xs,
        color: theme.colors.semantic.warningLight,
        fontSize: theme.typography.fontSize.sm,
      }}
    >
      <span aria-hidden="true">🗑</span>
      {t('members.requirements.willBeDeleted', { value: previous })}
      <button
        type="button"
        data-testid={`${testId}-restore`}
        onClick={onRestore}
        style={{
          background: 'none',
          border: 'none',
          padding: 0,
          color: theme.colors.semantic.primary,
          cursor: 'pointer',
          font: 'inherit',
          textDecoration: 'underline',
        }}
      >
        {t('members.requirements.restore')}
      </button>
    </p>
  )
}
