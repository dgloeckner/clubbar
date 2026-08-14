/**
 * Recording a return from the bank starts as a lookup, not a form
 * (epic #433 §3, ADR-0032 §8).
 *
 * The treasurer arrives holding a statement and does *not* know which run the
 * return came out of — which is why this is not a row action and why there is
 * no member picker here. They paste the reference the bank quoted; the lookup
 * resolves it; they confirm who it named.
 *
 * **It matches references only — never member names or amounts.** That boundary
 * is the whole of §8: a field that resolves a reference and has the treasurer
 * confirm it is a lookup; one that matches names becomes the free-hand picker
 * the ADR forbids. This is the single place where being forgiving costs money,
 * because selecting the wrong member reverses the wrong person's debt, holds
 * them, and cannot be undone.
 *
 * Being forgiving about *shape* is fine and deliberate — whitespace, case and
 * an `E2E-` label are stripped server-side, and a partial reference matches.
 *
 * Every candidate carries member, amount, execution date and status: the four
 * facts that let a treasurer match a row against the line on their statement,
 * and enough to tell apart two members owing the same amount. **A single match
 * still renders as a candidate to confirm** rather than jumping into the
 * dialog — with an irreversible operation at the end, one glance at *who* earns
 * its click.
 *
 * Candidates that cannot be acted on are shown disabled with the backend's own
 * reason, never filtered out. Filtering produces the worst outcome available:
 * the treasurer pasted a reference that genuinely exists, the UI claims nothing
 * matches, and they eventually record the return against the wrong thing.
 *
 * Test IDs: `record-bank-return-*`.
 */

import { useCallback, useEffect, useRef, useState, type CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import axios from 'axios'
import { ConfirmDialog } from './ConfirmDialog'
import { theme } from '../../styles/design-system'
import { useFormatters } from '../../hooks/useFormatters'
import { useLatestRequest } from '../../hooks/useLatestRequest'
import { settlementStatusColor, settlementStatus } from '../../utils/settlementStatus'
import { getSettlements } from '../../api/generated/settlements/settlements'
import type { ReversalCandidate } from '../../api/generated'

/** Below this the server refuses: a two-character substring is a list, not a lookup. */
const MIN_REFERENCE_LENGTH = 3

/** Long enough that a pasted reference is one request, short enough to feel live. */
const LOOKUP_DEBOUNCE_MS = 250

export interface RecordBankReturnDialogProps {
  isOpen: boolean
  /** The treasurer picked a collection — carry the reference they typed with it. */
  onSelect: (candidate: ReversalCandidate, reference: string) => void
  onCancel: () => void
}

export function RecordBankReturnDialog({ isOpen, onSelect, onCancel }: RecordBankReturnDialogProps) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const request = useLatestRequest()

  const [reference, setReference] = useState('')
  const [candidates, setCandidates] = useState<ReversalCandidate[] | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const lookup = useCallback(
    async (value: string, signal: AbortSignal) => {
      if (signal.aborted) return
      setError(null)
      try {
        const response = await getSettlements().lookupReversalCandidates({ reference: value }, { signal })
        if (signal.aborted) return
        setCandidates(response.data ?? [])
      } catch (err: unknown) {
        if (signal.aborted) return
        setCandidates(null)
        setError(
          axios.isAxiosError(err)
            ? (err.response?.data?.message ?? err.message)
            : err instanceof Error
              ? err.message
              : t('settlements.errors.lookup')
        )
      } finally {
        if (!signal.aborted) setLoading(false)
      }
    },
    [t]
  )

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)

    const trimmed = reference.trim()
    if (trimmed.length < MIN_REFERENCE_LENGTH) {
      request.abort()
      setCandidates(null)
      setError(null)
      setLoading(false)
      return
    }

    // Claimed here rather than inside the debounce: a keystroke that supersedes
    // an in-flight lookup has to abort it now, not in 250ms.
    const signal = request.next()
    setLoading(true)
    debounceRef.current = setTimeout(() => void lookup(trimmed, signal), LOOKUP_DEBOUNCE_MS)

    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current)
    }
  }, [reference, lookup, request])

  // A fresh dialog is a fresh statement line.
  useEffect(() => {
    if (!isOpen) {
      setReference('')
      setCandidates(null)
      setError(null)
      setLoading(false)
    }
  }, [isOpen])

  if (!isOpen) return null

  return (
    <ConfirmDialog
      isOpen
      maxWidth="620px"
      title={t('settlements.recordBankReturn')}
      showConfirm={false}
      cancelLabel={t('common.close')}
      onConfirm={onCancel}
      onCancel={onCancel}
      message={
        <div data-testid="record-bank-return-dialog">
          <p style={{ margin: 0 }}>{t('settlements.lookupIntro')}</p>

          <input
            type="text"
            autoFocus
            data-testid="record-bank-return-input"
            value={reference}
            onChange={(e) => setReference(e.target.value)}
            placeholder={t('settlements.lookupPlaceholder')}
            aria-label={t('settlements.lookupPlaceholder')}
            style={inputStyle}
          />

          {loading && (
            <div data-testid="record-bank-return-loading" style={mutedBlockStyle}>
              {t('common.loading')}
            </div>
          )}

          {error && (
            <div data-testid="record-bank-return-error" style={errorStyle}>
              {error}
            </div>
          )}

          {/* Zero matches names the two likely causes rather than shrugging.
              There is deliberately no "search by name instead" escape hatch. */}
          {!loading && !error && candidates !== null && candidates.length === 0 && (
            <div data-testid="record-bank-return-no-match" style={mutedBlockStyle}>
              {t('settlements.lookupNoMatch')}
            </div>
          )}

          {!loading && candidates !== null && candidates.length > 0 && (
            <div data-testid="record-bank-return-candidates" style={candidateListStyle}>
              {candidates.map((candidate) => (
                <CandidateRow
                  key={`${candidate.settlement_id}-${candidate.member_id}`}
                  candidate={candidate}
                  formatPrice={formatters.formatPrice}
                  formatDate={formatters.formatDate}
                  onSelect={() => onSelect(candidate, reference.trim())}
                />
              ))}
            </div>
          )}
        </div>
      }
    />
  )
}

interface CandidateRowProps {
  candidate: ReversalCandidate
  formatPrice: (cents: number) => string
  formatDate: (value: string) => string
  onSelect: () => void
}

function CandidateRow({ candidate, formatPrice, formatDate, onSelect }: CandidateRowProps) {
  const { t } = useTranslation()
  const testId = `record-bank-return-candidate-${candidate.settlement_id}-${candidate.member_id}`
  const actionable = candidate.is_actionable === true
  const status = settlementStatus(candidate)

  // Whichever refusal applies, in the backend's own words. Re-deriving a gate
  // client-side is the #81 bug, so nothing is recomputed here.
  const blockedReason = candidate.already_reversed
    ? t('settlements.lookupAlreadyReversed', {
        when: candidate.reversed_at ? formatDate(candidate.reversed_at) : '—',
        who: candidate.reversed_by_admin_name ?? t('settlements.lookupUnknownAdmin'),
      })
    : (candidate.reversal_blocked_reason ?? null)

  return (
    <button
      type="button"
      data-testid={testId}
      disabled={!actionable}
      onClick={onSelect}
      style={{
        ...candidateRowStyle,
        cursor: actionable ? 'pointer' : 'not-allowed',
        opacity: actionable ? 1 : 0.6,
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: theme.spacing.md, width: '100%' }}>
        <span data-testid={`${testId}-name`} style={{ fontWeight: theme.typography.fontWeight.semibold }}>
          {candidate.member_name ?? candidate.member_id}
        </span>
        <span data-testid={`${testId}-amount`} style={amountStyle}>
          {formatPrice(candidate.amount_cents ?? 0)}
        </span>
      </div>

      <div style={{ display: 'flex', gap: theme.spacing.md, alignItems: 'center', flexWrap: 'wrap' }}>
        <span data-testid={`${testId}-date`} style={mutedStyle}>
          {candidate.execution_date ? formatDate(candidate.execution_date) : '—'}
        </span>
        <span
          data-testid={`${testId}-status`}
          style={{
            fontSize: theme.typography.fontSize.xs,
            padding: '2px 8px',
            borderRadius: '999px',
            background: settlementStatusColor(status),
            color: 'white',
          }}
        >
          {status ? t(`settlements.statusLabels.${status}`) : (candidate.status_label ?? '—')}
        </span>
        {candidate.end_to_end_id && (
          <span data-testid={`${testId}-reference`} style={{ ...mutedStyle, fontFamily: 'JetBrains Mono, monospace' }}>
            {candidate.end_to_end_id}
          </span>
        )}
      </div>

      {blockedReason && (
        <div data-testid={`${testId}-blocked`} style={{ ...mutedStyle, textAlign: 'left' }}>
          {blockedReason}
        </div>
      )}
    </button>
  )
}

const inputStyle: CSSProperties = {
  marginTop: theme.spacing.md,
  padding: theme.spacing.md,
  borderRadius: theme.borderRadius.md,
  border: `1px solid ${theme.colors.border.light}`,
  background: theme.colors.bg.primary,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.base,
  fontFamily: 'JetBrains Mono, monospace',
  width: '100%',
  boxSizing: 'border-box',
}

const candidateListStyle: CSSProperties = {
  marginTop: theme.spacing.lg,
  display: 'flex',
  flexDirection: 'column',
  gap: theme.spacing.sm,
  maxHeight: '340px',
  overflowY: 'auto',
}

const candidateRowStyle: CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: theme.spacing.xs,
  padding: theme.spacing.md,
  borderRadius: theme.borderRadius.md,
  border: `1px solid ${theme.colors.border.light}`,
  background: theme.colors.bg.tertiary,
  color: theme.colors.text.primary,
  textAlign: 'left',
  fontSize: theme.typography.fontSize.sm,
  width: '100%',
}

const mutedStyle: CSSProperties = {
  color: theme.colors.text.muted,
  fontSize: theme.typography.fontSize.xs,
}

const mutedBlockStyle: CSSProperties = {
  marginTop: theme.spacing.lg,
  padding: theme.spacing.md,
  borderRadius: theme.borderRadius.md,
  background: theme.colors.bg.tertiary,
  color: theme.colors.text.secondary,
  fontSize: theme.typography.fontSize.sm,
}

const amountStyle: CSSProperties = {
  fontFamily: 'JetBrains Mono, monospace',
  fontWeight: theme.typography.fontWeight.bold,
  whiteSpace: 'nowrap',
}

const errorStyle: CSSProperties = {
  marginTop: theme.spacing.lg,
  padding: theme.spacing.md,
  borderRadius: theme.borderRadius.md,
  background: theme.colors.banner.dangerBg,
  color: theme.colors.banner.dangerText,
  fontSize: theme.typography.fontSize.sm,
}
