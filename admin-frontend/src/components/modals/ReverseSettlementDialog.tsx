/**
 * The question asked before a collection is booked back (epic #433 §2, §5).
 *
 * One dialog behind two entry points, because the two reasons are one mechanism
 * in the backend and nothing alike to a treasurer:
 *
 * - **`club_error`** is something they decided — re-collection is the point, so
 *   no collection hold is placed. Reached from the settlement row.
 * - **`bank_return`** is something that happened to them — a line on a
 *   statement, usually one member, and it silently freezes that member out of
 *   the next run. Reached from the reference lookup, with the member already
 *   resolved.
 *
 * The reason is therefore never a dropdown in here: it is decided before the
 * dialog opens, because it decides whether a member gets held, which is the
 * single most consequential thing about the operation and invisible if it is
 * picked from a list.
 *
 * **No step-up.** The SEPA export asks for one because it decrypts IBANs;
 * reversal decrypts nothing. Reversal is recovery work, often under time
 * pressure with the bank having already acted, and heavy friction on the remedy
 * is how people avoid recording what happened. The append-only audit trail is
 * the real control.
 *
 * **But it must say that a reversal cannot be taken back.** `UNIQUE
 * (settlement_id, member_id)` means a member is reversed at most once per
 * settlement, the foreign keys are `ON DELETE RESTRICT`, and there is no update
 * and no delete. Correcting a mistaken reversal is not possible, and nothing
 * about the button suggests that — so the dialog says it, every time.
 *
 * Test IDs: the surrounding ConfirmDialog's (`confirm-dialog`, `-ok`,
 * `-cancel`), plus `reverse-settlement-*`.
 */

import { useCallback, useEffect, useState, type CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import axios from 'axios'
import { ConfirmDialog } from './ConfirmDialog'
import { theme } from '../../styles/design-system'
import { useFormatters } from '../../hooks/useFormatters'
import { useLatestRequest } from '../../hooks/useLatestRequest'
import { undoDisplayDate } from '../../utils/settlementUndo'
import { allMembersReversed, type SettlementReversalTarget } from '../../utils/settlementReversal'
import { settlementMemberLines, type SettlementMemberLine } from '../../utils/settlementMembers'
import { getSettlements } from '../../api/generated/settlements/settlements'
import type { ReverseSettlementBodyReason } from '../../api/generated'

export interface ReverseSettlementDialogSettlement extends SettlementReversalTarget {
  id?: string
  total_amount_cents?: number
  transaction_count?: number
}

/** What the caller posts to `/reverse` once the question is answered. */
export interface ReversalRequest {
  reason: ReverseSettlementBodyReason
  /** Undefined means every member — the whole-settlement undo, not a select-all. */
  memberIds: string[] | undefined
  bankReference: string | undefined
  notes: string | undefined
}

export interface ReverseSettlementDialogProps {
  /** The settlement in question; null closes the dialog. */
  settlement: ReverseSettlementDialogSettlement | null
  reason: ReverseSettlementBodyReason
  /**
   * Fix the reversal to these members and hide the picker. The bank-return
   * path arrives having already resolved a reference to exactly one member's
   * collection, and re-opening that choice here would undo the lookup.
   */
  lockedMembers?: Array<{ memberId: string; memberName: string | null; amountCents: number }>
  /** Prefilled from the reference the treasurer pasted (§4). */
  initialBankReference?: string
  /** The reversal is in flight, or the backend refused it. */
  submitting?: boolean
  error?: string | null
  onConfirm: (request: ReversalRequest) => void
  onCancel: () => void
}

export function ReverseSettlementDialog({
  settlement,
  reason,
  lockedMembers,
  initialBankReference,
  submitting = false,
  error = null,
  onConfirm,
  onCancel,
}: ReverseSettlementDialogProps) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const request = useLatestRequest()

  // Whole-settlement reversal is the default: the endpoint's own default call
  // omits `member_ids` entirely. The picker is a disclosure, not a select-all.
  const [pickingMembers, setPickingMembers] = useState(false)
  const [selectedMemberIds, setSelectedMemberIds] = useState<string[]>([])
  const [memberLines, setMemberLines] = useState<SettlementMemberLine[] | null>(null)
  const [membersLoading, setMembersLoading] = useState(false)
  const [membersError, setMembersError] = useState<string | null>(null)
  const [bankReference, setBankReference] = useState(initialBankReference ?? '')
  const [notes, setNotes] = useState('')

  const settlementId = settlement?.id

  // A fresh dialog is a fresh question: neither a scope nor a reference ticked
  // for one settlement may carry over to the next one opened.
  useEffect(() => {
    setPickingMembers(false)
    setSelectedMemberIds([])
    setMemberLines(null)
    setMembersError(null)
    setBankReference(initialBankReference ?? '')
    setNotes('')
  }, [settlementId, initialBankReference])

  /**
   * The member list is fetched only when the disclosure opens. The default path
   * — undo the whole run — needs no list at all, and paying for one on every
   * dialog would slow down the common case for the rarer one.
   */
  const loadMembers = useCallback(async () => {
    if (!settlementId) return

    const signal = request.next()
    setMembersLoading(true)
    setMembersError(null)
    try {
      const detail = await getSettlements().getSettlement(settlementId, { signal })
      if (signal.aborted) return
      setMemberLines(settlementMemberLines(detail.items, detail.reversals))
    } catch (err: unknown) {
      if (signal.aborted) return
      setMembersError(
        axios.isAxiosError(err)
          ? (err.response?.data?.message ?? err.message)
          : err instanceof Error
            ? err.message
            : t('settlements.errors.load')
      )
    } finally {
      if (!signal.aborted) setMembersLoading(false)
    }
  }, [settlementId, request, t])

  if (!settlement) return null

  const blockedReason = settlement.reversal_blocked_reason ?? null
  const blocked = settlement.is_reversible === false || allMembersReversed(settlement)
  const isBankReturn = reason === 'bank_return'
  const date = undoDisplayDate(settlement)

  const openPicker = () => {
    setPickingMembers(true)
    if (memberLines === null) void loadMembers()
  }

  const toggleMember = (memberId: string) => {
    setSelectedMemberIds((current) =>
      current.includes(memberId) ? current.filter((id) => id !== memberId) : [...current, memberId]
    )
  }

  const details = (
    <div style={detailGridStyle}>
      <span style={labelStyle}>{t('common.date')}</span>
      <span data-testid="reverse-settlement-detail-date">
        {date ? formatters.formatDate(date) : '—'}
      </span>

      <span style={labelStyle}>{t('settlements.totalAmount')}</span>
      <span data-testid="reverse-settlement-detail-amount" style={amountStyle}>
        {formatters.formatPrice(settlement.total_amount_cents ?? 0)}
      </span>

      <span style={labelStyle}>{t('settlements.memberCount')}</span>
      <span data-testid="reverse-settlement-detail-members">{settlement.member_count ?? 0}</span>
    </div>
  )

  // The gate is shut, or every member already came back. Either way there is
  // nothing to confirm — a permanently disabled confirm button is the dead
  // control this shape exists to replace (#127).
  if (blocked) {
    return (
      <ConfirmDialog
        isOpen
        title={t('settlements.reverseBlockedTitle')}
        onCancel={onCancel}
        onConfirm={onCancel}
        showConfirm={false}
        cancelLabel={t('common.close')}
        message={
          <>
            <p data-testid="reverse-settlement-blocked-reason" style={{ margin: 0 }}>
              {allMembersReversed(settlement) && settlement.is_reversible !== false
                ? t('settlements.reverseAllMembersDone')
                : (blockedReason ?? t('settlements.reverseBlockedFallback'))}
            </p>
            {details}
          </>
        }
      />
    )
  }

  const scopedMemberIds = lockedMembers
    ? lockedMembers.map((m) => m.memberId)
    : pickingMembers
      ? selectedMemberIds
      : undefined

  // Opening the picker and ticking nothing is not "reverse everything" — that
  // would silently turn a narrowing gesture into the widest possible action.
  const confirmDisabled = submitting || (pickingMembers && !lockedMembers && selectedMemberIds.length === 0)

  return (
    <ConfirmDialog
      isOpen
      maxWidth="560px"
      title={isBankReturn ? t('settlements.recordBankReturnTitle') : t('settlements.reverseTitle')}
      onCancel={onCancel}
      onConfirm={() =>
        onConfirm({
          reason,
          memberIds: scopedMemberIds,
          bankReference: bankReference.trim() || undefined,
          notes: notes.trim() || undefined,
        })
      }
      confirmLabel={isBankReturn ? t('settlements.recordBankReturnConfirm') : t('settlements.reverse')}
      confirmDisabled={confirmDisabled}
      message={
        <>
          <p style={{ margin: 0 }}>
            {isBankReturn ? t('settlements.recordBankReturnIntro') : t('settlements.reverseConfirm')}
          </p>

          {details}

          {/* Which members. Locked to one when the reference already named
              them; otherwise the whole run, with a disclosure to narrow it. */}
          {lockedMembers ? (
            <div data-testid="reverse-settlement-locked-member" style={noticeStyle}>
              {lockedMembers.map((member) => (
                <div key={member.memberId} style={memberRowStyle}>
                  <span>{member.memberName ?? member.memberId}</span>
                  <span style={amountStyle}>{formatters.formatPrice(member.amountCents)}</span>
                </div>
              ))}
            </div>
          ) : (
            <>
              <label style={disclosureStyle}>
                <input
                  type="checkbox"
                  data-testid="reverse-settlement-scope-toggle"
                  checked={pickingMembers}
                  onChange={(e) => (e.target.checked ? openPicker() : setPickingMembers(false))}
                  style={{ cursor: 'pointer' }}
                />
                <span>{t('settlements.reverseSelected')}</span>
              </label>

              {pickingMembers && (
                <div data-testid="reverse-settlement-member-list" style={memberListStyle}>
                  {membersLoading && (
                    <div data-testid="reverse-settlement-members-loading" style={mutedStyle}>
                      {t('common.loading')}
                    </div>
                  )}
                  {membersError && (
                    <div data-testid="reverse-settlement-members-error" style={mutedStyle}>
                      {membersError}
                    </div>
                  )}
                  {memberLines?.map((line) => {
                    // Already reversed: shown, disabled, saying so. Hiding the
                    // row would leave the treasurer wondering where a member
                    // they can see in the run went.
                    const done = line.reversal !== null
                    return (
                      <label
                        key={line.memberId}
                        data-testid={`reverse-settlement-member-${line.memberId}`}
                        style={{ ...memberRowStyle, opacity: done ? 0.55 : 1, cursor: done ? 'default' : 'pointer' }}
                      >
                        <span style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.sm }}>
                          <input
                            type="checkbox"
                            data-testid={`reverse-settlement-member-checkbox-${line.memberId}`}
                            disabled={done}
                            checked={selectedMemberIds.includes(line.memberId)}
                            onChange={() => toggleMember(line.memberId)}
                            style={{ cursor: done ? 'not-allowed' : 'pointer' }}
                          />
                          <span>
                            {line.memberName ?? line.memberId}
                            {done && (
                              <span
                                data-testid={`reverse-settlement-member-reversed-${line.memberId}`}
                                style={{ display: 'block', fontSize: theme.typography.fontSize.xs, color: theme.colors.text.muted }}
                              >
                                {t('settlements.reverseAlreadyDone')}
                              </span>
                            )}
                          </span>
                        </span>
                        <span style={amountStyle}>{formatters.formatPrice(line.amountCents)}</span>
                      </label>
                    )
                  })}
                </div>
              )}
            </>
          )}

          {/* §4: the reference a German bank quotes on a return booking *is*
              our EndToEndId echoed back, so on the lookup path it is already
              filled in. Never required — an unrecorded return is strictly
              worse than an unreferenced one. Absent for a club error, where no
              bank was involved. */}
          {isBankReturn && (
            <label style={fieldStyle}>
              <span style={labelStyle}>
                {t('settlements.reverseBankReference')} <span style={mutedStyle}>({t('common.optional')})</span>
              </span>
              <input
                type="text"
                data-testid="reverse-settlement-bank-reference"
                value={bankReference}
                maxLength={35}
                onChange={(e) => setBankReference(e.target.value)}
                style={inputStyle}
              />
            </label>
          )}

          <label style={fieldStyle}>
            <span style={labelStyle}>
              {t('settlements.reverseNotes')} <span style={mutedStyle}>({t('common.optional')})</span>
            </span>
            <textarea
              data-testid="reverse-settlement-notes"
              value={notes}
              maxLength={1000}
              rows={2}
              onChange={(e) => setNotes(e.target.value)}
              style={{ ...inputStyle, resize: 'vertical' }}
            />
          </label>

          {/* Stated where the treasurer acts, because nothing about the button
              suggests it and no later screen can take it back (§5). */}
          <div data-testid="reverse-settlement-irreversible" style={warningStyle}>
            {t('settlements.reverseIrreversible')}
          </div>

          {/* A consequence nobody asked for, so it is said rather than
              discovered. Absent for a club error, which places no hold. */}
          {isBankReturn && (
            <div data-testid="reverse-settlement-hold-warning" style={{ ...warningStyle, marginTop: theme.spacing.sm }}>
              {t('settlements.reverseHoldWarning')}
            </div>
          )}

          {error && (
            <div data-testid="reverse-settlement-error" style={errorStyle}>
              {error}
            </div>
          )}
        </>
      }
    />
  )
}

const labelStyle: CSSProperties = {
  color: theme.colors.text.secondary,
}

const mutedStyle: CSSProperties = {
  color: theme.colors.text.muted,
  fontSize: theme.typography.fontSize.xs,
}

const amountStyle: CSSProperties = {
  fontFamily: 'JetBrains Mono, monospace',
  fontWeight: theme.typography.fontWeight.bold,
  whiteSpace: 'nowrap',
}

const detailGridStyle: CSSProperties = {
  display: 'grid',
  gridTemplateColumns: 'auto 1fr',
  gap: `${theme.spacing.xs} ${theme.spacing.lg}`,
  margin: `${theme.spacing.lg} 0`,
  padding: theme.spacing.md,
  background: theme.colors.bg.tertiary,
  borderRadius: theme.borderRadius.md,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
}

const disclosureStyle: CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: theme.spacing.sm,
  color: theme.colors.text.primary,
  cursor: 'pointer',
}

const memberListStyle: CSSProperties = {
  marginTop: theme.spacing.sm,
  maxHeight: '220px',
  overflowY: 'auto',
  border: `1px solid ${theme.colors.border.light}`,
  borderRadius: theme.borderRadius.md,
  padding: theme.spacing.sm,
}

const memberRowStyle: CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'space-between',
  gap: theme.spacing.md,
  padding: `${theme.spacing.xs} ${theme.spacing.sm}`,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
}

const noticeStyle: CSSProperties = {
  padding: theme.spacing.md,
  borderRadius: theme.borderRadius.md,
  background: theme.colors.bg.tertiary,
  color: theme.colors.text.primary,
}

const fieldStyle: CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: theme.spacing.xs,
  marginTop: theme.spacing.md,
  fontSize: theme.typography.fontSize.sm,
}

const inputStyle: CSSProperties = {
  padding: theme.spacing.sm,
  borderRadius: theme.borderRadius.md,
  border: `1px solid ${theme.colors.border.light}`,
  background: theme.colors.bg.primary,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
  width: '100%',
  boxSizing: 'border-box',
}

const warningStyle: CSSProperties = {
  marginTop: theme.spacing.lg,
  padding: theme.spacing.md,
  borderRadius: theme.borderRadius.md,
  background: 'rgba(251, 146, 60, 0.12)',
  border: '1px solid rgba(251, 146, 60, 0.4)',
  color: theme.colors.banner.warningText,
}

const errorStyle: CSSProperties = {
  marginTop: theme.spacing.md,
  padding: theme.spacing.md,
  borderRadius: theme.borderRadius.md,
  background: theme.colors.banner.dangerBg,
  color: theme.colors.banner.dangerText,
}
