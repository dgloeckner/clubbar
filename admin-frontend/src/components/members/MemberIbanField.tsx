/**
 * MemberIbanField — the IBAN row of the member form, in its three states.
 *
 * Since ADR-0036 a stored IBAN is sealed under the club's public key and the
 * server cannot read it back; the API returns only the last four characters.
 * The form is therefore *overwrite-only*: a blank field on save keeps the
 * stored account, and removing it is its own deliberate action (#392).
 *
 * The old rendering stated that in prose under an empty input whose grey
 * placeholder was a made-up example IBAN — which reads exactly like a stored
 * value that has been greyed out. Here the stored account takes the input's
 * place instead, and the input only appears once "Change" is pressed, so the
 * rule is visible rather than explained.
 *
 *   stored   → the box, with Change / Remove
 *   entry    → the input (a new member, or Change was pressed)
 *   removing → the pending-revocation notice, with Undo
 */

import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { validateIban } from '../../utils/iban'
import { useBankName } from '../../hooks/useBankName'
import { ValidationIndicator } from '../forms/ValidationIndicator'
import { StoredFieldAction, StoredFieldBox } from './StoredFieldBox'

export type MemberIbanFieldMode = 'stored' | 'entry' | 'removing'

export interface MemberIbanFieldProps {
  mode: MemberIbanFieldMode
  /** The IBAN typed into the form; always '' outside `entry`. */
  value: string
  onChange: (value: string) => void
  /** `****0101` when the member has bank details on file, else null. */
  storedMasked: string | null
  storedBankName?: string | null
  /** True once "Change" has been pressed, so the input replaces a stored value. */
  isReplacing: boolean
  onBeginChange: () => void
  onCancelChange: () => void
  onRemove: () => void
  onUndoRemove: () => void
  error?: string
}

export function MemberIbanField({
  mode,
  value,
  onChange,
  storedMasked,
  storedBankName,
  isReplacing,
  onBeginChange,
  onCancelChange,
  onRemove,
  onUndoRemove,
  error,
}: MemberIbanFieldProps) {
  const { t } = useTranslation()
  const { bankName, isLoading: isBankNameLoading } = useBankName(value)
  const inputRef = useRef<HTMLInputElement>(null)

  // Revealing the input without moving the caret into it strands keyboard
  // users on the button that just disappeared.
  useEffect(() => {
    if (mode === 'entry' && isReplacing) {
      inputRef.current?.focus()
    }
  }, [mode, isReplacing])

  // "(SEPA, optional)" is only true of a field being filled in for the first
  // time; for a stored account it is the lock, for a replacement it names what
  // is being replaced, and during a pending removal the box below says it all.
  const labelSuffix =
    mode === 'stored'
      ? `🔒 ${t('members.ibanStoredLabel')}`
      : mode === 'removing'
        ? ''
        : isReplacing && storedMasked
          ? t('members.ibanReplaces', { masked: storedMasked })
          : `(${t('common.sepa')}, ${t('common.optional')})`

  return (
    <div>
      <label
        htmlFor="members-form-iban-input"
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: theme.spacing.sm,
          marginBottom: theme.spacing.sm,
          fontSize: theme.typography.fontSize.sm,
          fontWeight: 600,
        }}
      >
        {t('members.iban')}
        {labelSuffix && (
          <span
            style={{
              color: theme.colors.text.secondary,
              marginLeft: theme.spacing.xs,
              fontWeight: 400,
            }}
          >
            {labelSuffix}
          </span>
        )}
        {mode === 'entry' && (
          <ValidationIndicator
            isValid={validateIban(value)}
            show={value.length > 0}
            testId="members-form-iban-validation"
          />
        )}
      </label>

      {mode === 'stored' && storedMasked && (
        <StoredFieldBox
          testId="members-form-iban-stored"
          value={
            <span>
              {storedMasked}
              {storedBankName ? ` · ${storedBankName}` : ''}
            </span>
          }
          actions={
            <>
              <StoredFieldAction
                testId="members-form-iban-change"
                tone="primary"
                onClick={onBeginChange}
              >
                {t('members.ibanChange')}
              </StoredFieldAction>
              <StoredFieldAction
                testId="members-form-iban-remove"
                tone="danger"
                onClick={onRemove}
              >
                {t('members.removeStoredIban')}
              </StoredFieldAction>
            </>
          }
        />
      )}

      {mode === 'entry' && (
        <>
          <input
            id="members-form-iban-input"
            ref={inputRef}
            data-testid="members-form-iban-input"
            type="text"
            value={value}
            onChange={(e) => onChange(e.target.value.replace(/\s/g, '').toUpperCase())}
            placeholder={
              isReplacing
                ? t('members.ibanPlaceholderNew')
                : t('members.ibanPlaceholderExample')
            }
            minLength={15}
            maxLength={34}
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
          {isReplacing && storedMasked && (
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
                testId="members-form-iban-change-cancel"
                onClick={onCancelChange}
              >
                {t('members.ibanCancelChange', { masked: storedMasked })}
              </StoredFieldAction>
            </div>
          )}
        </>
      )}

      {mode === 'removing' && (
        <StoredFieldBox
          testId="members-form-iban-removal-pending"
          pending
          value={
            <span style={{ color: theme.colors.semantic.danger, fontStyle: 'normal' }}>
              {t('members.storedIbanWillBeRemoved')}
            </span>
          }
          actions={
            <StoredFieldAction testId="members-form-iban-remove-undo" onClick={onUndoRemove}>
              {t('common.undo')}
            </StoredFieldAction>
          }
        />
      )}

      {/*
        Only while there is something to say. The previous guard rendered an
        empty paragraph for a DE-prefixed IBAN that is invalid and not being
        looked up, which moved the fields below it for no reason.
      */}
      {mode === 'entry' && (bankName || isBankNameLoading) && (
        <p
          data-testid="members-form-bank-name"
          style={{
            fontSize: theme.typography.fontSize.sm,
            color: bankName ? theme.colors.text.secondary : theme.colors.text.muted,
            marginTop: theme.spacing.xs,
            fontStyle: bankName ? 'normal' : 'italic',
          }}
        >
          {bankName ?? t('members.bankNameLoading')}
        </p>
      )}

      {error && (
        <p
          data-testid="members-form-iban-error"
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
