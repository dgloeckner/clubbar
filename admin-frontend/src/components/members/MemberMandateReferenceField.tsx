/**
 * MemberMandateReferenceField — the mandate reference row of the member form.
 *
 * The reference is minted by the server when a mandate is opened: a UUID
 * without hyphens, unless one was supplied (ADR-0006,
 * `MembersRepository::openMandate`). Supplying one is the exception — it
 * exists so a mandate that already has a reference on paper or in a previous
 * system keeps it.
 *
 * The old rendering made the exception the default: a free-text input with a
 * grey example reference in it and two sentences underneath explaining that
 * leaving it empty is normal. Here the standard case *is* the default state
 * ("assigned on save"), typing one is an opt-in action, and once assigned the
 * reference is shown as the value it is — with a copy button, because it is
 * the identifier a bank quotes back on a returned collection and the one the
 * settlement lookup searches for.
 */

import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { useClipboardCopy } from '../../hooks/useClipboardCopy'
import { StoredFieldAction, StoredFieldBox } from './StoredFieldBox'
import { FieldInfo } from '../forms/FieldInfo'

export type MandateReferenceMode = 'assigned' | 'auto' | 'entry'

export interface MemberMandateReferenceFieldProps {
  mode: MandateReferenceMode
  /** The reference typed into the form; always '' outside `entry`. */
  value: string
  onChange: (value: string) => void
  /** The reference as stored, when the member already has a mandate. */
  assignedReference: string | null
  onBeginEntry: () => void
  onCancelEntry: () => void
  error?: string
}

export function MemberMandateReferenceField({
  mode,
  value,
  onChange,
  assignedReference,
  onBeginEntry,
  onCancelEntry,
  error,
}: MemberMandateReferenceFieldProps) {
  const { t } = useTranslation()
  const { status: copyStatus, copy } = useClipboardCopy()
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (mode === 'entry') {
      inputRef.current?.focus()
    }
  }, [mode])

  return (
    <div>
      <label
        htmlFor="members-form-mandate-reference-input"
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: theme.spacing.sm,
          marginBottom: theme.spacing.sm,
          fontSize: theme.typography.fontSize.sm,
          fontWeight: 600,
        }}
      >
        {t('members.mandateReference')}
        {/* "(SEPA, optional)" repeated what the section divider above already
            says, on a field whose real question — why it is usually left blank —
            it never answered. That answer is behind the icon now (#830). */}
        <FieldInfo
          content={
            mode === 'assigned'
              ? t('members.mandateReferenceAssignedNote')
              : t('members.mandateReferenceHint')
          }
          testId="members-form-mandate-reference-info"
        />
      </label>

      {mode === 'assigned' && assignedReference && (
        <StoredFieldBox
          testId="members-form-mandate-reference-assigned"
          value={
            <>
              <span data-testid="members-form-mandate-reference-value">{assignedReference}</span>
              <button
                type="button"
                data-testid="members-form-mandate-reference-copy"
                onClick={() => void copy(assignedReference)}
                aria-label={t('members.mandateReferenceCopy')}
                title={t('members.mandateReferenceCopy')}
                style={{
                  background: 'none',
                  border: 'none',
                  padding: 0,
                  color:
                    copyStatus === 'failed'
                      ? theme.colors.semantic.danger
                      : copyStatus === 'copied'
                        ? theme.colors.semantic.success
                        : theme.colors.text.secondary,
                  cursor: 'pointer',
                  fontSize: theme.typography.fontSize.base,
                  flexShrink: 0,
                }}
              >
                {copyStatus === 'copied' ? '✓' : '⧉'}
              </button>
            </>
          }
          // "Erscheint als Mandatsreferenz auf dem Kontoauszug" moved into the
          // label's info popover: it is background, and background does not
          // need a permanent line of its own beside the value (#830).
          actions={
            <StoredFieldAction
              testId="members-form-mandate-reference-change"
              tone="primary"
              onClick={onBeginEntry}
            >
              {t('members.mandateReferenceChange')}
            </StoredFieldAction>
          }
        />
      )}

      {mode === 'auto' && (
        <StoredFieldBox
          testId="members-form-mandate-reference-auto"
          pending
          value={<span>⚙ {t('members.mandateReferenceWillBeAssigned')}</span>}
          actions={
            <StoredFieldAction
              testId="members-form-mandate-reference-enter"
              onClick={onBeginEntry}
            >
              {t('members.mandateReferenceEnterExisting')}
            </StoredFieldAction>
          }
        />
      )}

      {mode === 'entry' && (
        <>
          <input
            id="members-form-mandate-reference-input"
            ref={inputRef}
            data-testid="members-form-mandate-reference-input"
            type="text"
            value={value}
            onChange={(e) => onChange(e.target.value.toUpperCase())}
            placeholder={t('members.mandateReferencePlaceholder')}
            maxLength={35}
            style={{
              width: '100%',
              padding: `${theme.spacing.md} ${theme.spacing.lg}`,
              background: theme.colors.bg.input,
              border: `1px solid ${error ? theme.colors.semantic.danger : theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.text.primary,
              boxSizing: 'border-box',
              fontFamily: 'monospace',
            }}
          />
          <div
            style={{
              display: 'flex',
              flexWrap: 'wrap',
              gap: theme.spacing.md,
              marginTop: theme.spacing.xs,
              fontSize: theme.typography.fontSize.sm,
              color: theme.colors.text.secondary,
            }}
          >
            <StoredFieldAction
              testId="members-form-mandate-reference-cancel"
              onClick={onCancelEntry}
            >
              {assignedReference
                ? t('members.mandateReferenceCancelChange', { reference: assignedReference })
                : t('members.mandateReferenceCancelEntry')}
            </StoredFieldAction>
          </div>
        </>
      )}

      {error && (
        <p
          data-testid="members-form-mandate-reference-error"
          style={{
            color: theme.colors.semantic.danger,
            fontSize: theme.typography.fontSize.sm,
            marginTop: theme.spacing.xs,
          }}
        >
          {error}
        </p>
      )}
    </div>
  )
}
