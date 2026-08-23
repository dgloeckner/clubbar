/**
 * The canonical name of a settlement, as the Kassenwart sees it.
 *
 * One string identifies a run everywhere it appears: the pain.008 `MsgId` and
 * `PmtInfId`, the Verwendungszweck a member reads on their bank statement, the
 * Vorabankündigung, the CSV, the download's filename — and here. That is the
 * whole point of it: what a member quotes in an email is what the treasurer
 * pastes into the bank-return lookup, character for character.
 *
 * Which is why the copy button is not decoration. Thirty-two hex digits is not
 * something anybody retypes correctly, and a reference transcribed with one
 * digit wrong resolves to nothing — indistinguishable, to the person searching,
 * from a collection that was never made.
 *
 * `short` renders the head of the value with the full string in the tooltip and
 * on the clipboard; the expanded detail row shows all of it.
 */

import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'

import { useClipboardCopy } from '../../hooks/useClipboardCopy'
import { theme } from '../../styles/design-system'

/** Enough to be unambiguous in a list of a club's runs, short enough to sit in a table cell. */
const SHORT_LENGTH = 12

interface SettlementReferenceTagProps {
  reference: string
  /** Test id root; the element carrying the full value is `${testId}-value`. */
  testId: string
  short?: boolean
}

export function SettlementReferenceTag({ reference, testId, short = false }: SettlementReferenceTagProps) {
  const { t } = useTranslation()
  const { status, copy, reset } = useClipboardCopy()

  // The "copied" acknowledgement is transient; without this it sticks until the
  // row is re-rendered for some unrelated reason.
  useEffect(() => {
    if (status === 'idle') return
    const timer = setTimeout(reset, 2000)
    return () => clearTimeout(timer)
  }, [status, reset])

  const display = short && reference.length > SHORT_LENGTH ? `${reference.slice(0, SHORT_LENGTH)}…` : reference

  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
      <span
        // The full value, always — E2E asserts on this rather than on the
        // abbreviated text, and a screen reader reads the real reference.
        data-testid={`${testId}-value`}
        title={reference}
        style={{
          fontFamily: theme.typography.fontFamily.mono,
          fontSize: 12,
          color: theme.colors.text.muted,
          letterSpacing: '0.02em',
        }}
      >
        {display}
      </span>
      <button
        type="button"
        data-testid={`${testId}-copy`}
        onClick={() => void copy(reference)}
        aria-label={t('settlements.referenceCopy')}
        title={status === 'copied' ? t('settlements.referenceCopied') : t('settlements.referenceCopy')}
        style={{
          border: 'none',
          background: 'transparent',
          cursor: 'pointer',
          padding: 0,
          lineHeight: 1,
          fontSize: 12,
          color: status === 'copied' ? theme.colors.semantic.success : theme.colors.text.muted,
        }}
      >
        {status === 'copied' ? '✓' : '⧉'}
      </button>
    </span>
  )
}
