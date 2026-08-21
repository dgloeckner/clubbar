/**
 * MemberFormRequirements — the member form's opening line: what it still needs,
 * and what saving it would take away.
 *
 * Two questions an admin has that a `*` on five labels cannot answer (#629):
 *
 *   "What is still missing?"  — the progress line, plus the missing fields as
 *                               buttons that put the caret in the field. The
 *                               whole point is that the answer is complete:
 *                               every gap at once, not the one the browser's
 *                               native validation happens to reach first.
 *   "What did I just delete?" — an edit that empties a stored optional value
 *                               sends `null` to the API (#131). The count is
 *                               stated here; the value itself, and the undo,
 *                               sit inline at the field.
 *
 * The panel is `role="status"` rather than `role="alert"` until a submit has
 * actually been refused — announcing "3 of 5" on every keystroke is noise, and
 * the one moment the message is genuinely urgent is the one where it changes.
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'

export interface MissingRequirement {
  /** Form field key, handed back to `onJumpTo`. */
  field: string
  /** Translated field name, as the label above the input reads. */
  label: string
}

export interface MemberFormRequirementsProps {
  satisfied: number
  total: number
  missing: MissingRequirement[]
  /** How many stored values this submit would delete (edit only). */
  clearingCount: number
  /** True once a submit was refused for missing fields — raises the tone. */
  blocked: boolean
  onJumpTo: (field: string) => void
}

export function MemberFormRequirements({
  satisfied,
  total,
  missing,
  clearingCount,
  blocked,
  onJumpTo,
}: MemberFormRequirementsProps) {
  const { t } = useTranslation()

  const complete = missing.length === 0
  const tone = blocked ? 'danger' : complete ? 'success' : 'warning'
  const badge = theme.badges[tone]

  return (
    <div
      data-testid="members-form-requirements"
      data-state={blocked ? 'blocked' : complete ? 'complete' : 'incomplete'}
      role={blocked ? 'alert' : 'status'}
      aria-live="polite"
      style={{
        display: 'flex',
        flexDirection: 'column',
        gap: theme.spacing.sm,
        padding: `${theme.spacing.md} ${theme.spacing.lg}`,
        marginBottom: theme.spacing.lg,
        background: badge.bg,
        border: `1px solid ${badge.border}`,
        borderRadius: theme.borderRadius.md,
        fontSize: theme.typography.fontSize.sm,
      }}
    >
      <div
        style={{
          display: 'flex',
          flexWrap: 'wrap',
          alignItems: 'center',
          gap: theme.spacing.md,
        }}
      >
        <span aria-hidden="true" style={{ color: badge.text, fontWeight: theme.typography.fontWeight.bold }}>
          {complete ? '✓' : '!'}
        </span>
        <span
          data-testid="members-form-requirements-progress"
          style={{ color: badge.text, fontWeight: theme.typography.fontWeight.semibold }}
        >
          {complete
            ? t('members.requirements.complete')
            : t('members.requirements.progress', { done: satisfied, total })}
        </span>

        {/* A bar rather than a second sentence: the same fact, readable
            without being read. */}
        <span
          aria-hidden="true"
          style={{
            flex: '0 0 auto',
            width: '96px',
            height: '4px',
            borderRadius: theme.borderRadius.full,
            background: theme.colors.border.dark,
            overflow: 'hidden',
          }}
        >
          <span
            style={{
              display: 'block',
              width: `${total === 0 ? 0 : Math.round((satisfied / total) * 100)}%`,
              height: '100%',
              background: badge.dot,
              transition: `width ${theme.transitions.default}`,
            }}
          />
        </span>
      </div>

      {!complete && (
        <div
          style={{
            display: 'flex',
            flexWrap: 'wrap',
            alignItems: 'center',
            gap: theme.spacing.sm,
            color: theme.colors.text.secondary,
          }}
        >
          <span>{t('members.requirements.missingLabel')}</span>
          {missing.map((item) => (
            <button
              key={item.field}
              type="button"
              data-testid={`members-form-requirements-missing-${item.field}`}
              onClick={() => onJumpTo(item.field)}
              title={t('members.requirements.jumpTo', { field: item.label })}
              style={{
                padding: `2px ${theme.spacing.sm}`,
                borderRadius: theme.borderRadius.full,
                background: theme.colors.bg.input,
                border: `1px solid ${badge.border}`,
                color: badge.text,
                fontSize: theme.typography.fontSize.xs,
                fontWeight: theme.typography.fontWeight.semibold,
                cursor: 'pointer',
              }}
            >
              {item.label}
            </button>
          ))}
        </div>
      )}

      {clearingCount > 0 && (
        <div
          data-testid="members-form-requirements-clearing"
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: theme.spacing.sm,
            color: theme.colors.semantic.warningLight,
          }}
        >
          <span aria-hidden="true">🗑</span>
          {t('members.requirements.clearing', { count: clearingCount })}
        </div>
      )}
    </div>
  )
}
