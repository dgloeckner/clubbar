/**
 * Accepting the visible inbox in one go (UC-A17; ADR-0052 amended 2026-11-28).
 *
 * Two pieces, both presentational — the page owns the request and the list:
 *
 * - {@link AcceptAllDialog} asks. It states the three things an admin has to
 *   know *before* confirming: how many registrations become members, that
 *   those members have no signature and therefore cannot buy yet, and that a
 *   possible duplicate is left where it is.
 * - {@link AcceptAllResult} answers. A batch never fails for one row, so the
 *   outcome is two numbers rather than "done": how many were accepted, how
 *   many were not, and why.
 *
 * ## "All" means all that are on screen
 *
 * The request carries explicit ids, and the ids are the rows this page is
 * showing — never "everything pending". With more registrations than fit on
 * one page the dialog says so in words, because an admin who reads "all" and
 * has sixty waiting must not discover afterwards that forty are still there —
 * or, worse, the reverse.
 *
 * Test IDs: `accept-all-count`, `accept-all-page-only`, `accept-all-unsigned`,
 * `accept-all-duplicates` (inside the shared `confirm-dialog`), and
 * `registrations-batch-result`, `batch-result-accepted`, `batch-result-skipped`,
 * `batch-result-reason-<code>`, `batch-result-dismiss`.
 */

import { useTranslation } from 'react-i18next'

import type { RegistrationBatchApprovalResult } from '../../api/generated/model/registrationBatchApprovalResult'
import { ConfirmDialog } from '../modals/ConfirmDialog'
import { ModalError } from '../modals/ModalError'
import { useApiError } from '../../hooks/useApiError'
import { theme } from '../../styles/design-system'

export interface AcceptAllDialogProps {
  isOpen: boolean
  /** Registrations on screen — what the request will name. */
  shownCount: number
  /** Registrations pending in total, across every page. */
  totalCount: number
  /** How many of the shown ones carry a duplicate flag and will be skipped. */
  flaggedCount: number
  busy: boolean
  error: string | null
  onConfirm: () => void
  onCancel: () => void
}

export function AcceptAllDialog({
  isOpen,
  shownCount,
  totalCount,
  flaggedCount,
  busy,
  error,
  onConfirm,
  onCancel,
}: AcceptAllDialogProps) {
  const { t } = useTranslation()

  // What will actually become members, as far as this page can tell: the
  // server skips a flagged row, so counting it here would promise one more
  // member than the answer delivers.
  const acceptCount = Math.max(0, shownCount - flaggedCount)

  return (
    <ConfirmDialog
      isOpen={isOpen}
      title={t('registrations.acceptAll.title')}
      variant="primary"
      maxWidth="520px"
      confirmLabel={busy ? t('registrations.acceptAll.working') : t('registrations.acceptAll.confirm')}
      // Nothing to accept is not a question worth a button: every row shown is
      // a possible duplicate and each needs a person.
      confirmDisabled={busy || acceptCount === 0}
      onConfirm={onConfirm}
      onCancel={onCancel}
      message={
        <>
          <ModalError message={error} testId="accept-all-error" />

          <p data-testid="accept-all-count" style={{ ...paragraphStyle, fontWeight: 600 }}>
            {t('registrations.acceptAll.count', { count: acceptCount })}
          </p>

          {totalCount > shownCount && (
            <p data-testid="accept-all-page-only" style={paragraphStyle}>
              {t('registrations.acceptAll.pageOnly', { count: shownCount, total: totalCount })}
            </p>
          )}

          <p data-testid="accept-all-unsigned" style={paragraphStyle}>
            {t('registrations.acceptAll.unsigned')}
          </p>

          <p data-testid="accept-all-duplicates" style={{ ...paragraphStyle, marginBottom: 0 }}>
            {flaggedCount > 0
              ? t('registrations.acceptAll.duplicatesShown', { count: flaggedCount })
              : t('registrations.acceptAll.duplicates')}
          </p>
        </>
      }
    />
  )
}

export interface AcceptAllResultProps {
  result: RegistrationBatchApprovalResult
  onDismiss: () => void
}

export function AcceptAllResult({ result, onDismiss }: AcceptAllResultProps) {
  const { t } = useTranslation()
  const { reasonText } = useApiError()

  const accepted = result.accepted.length
  const skipped = result.skipped.length

  // One line per reason, not one per registration: forty rows skipped for the
  // same cause are one thing to know, and the rows themselves are still in the
  // list underneath this notice.
  const byReason = new Map<string, number>()
  for (const entry of result.skipped) {
    byReason.set(entry.reason, (byReason.get(entry.reason) ?? 0) + 1)
  }

  // Amber as soon as anything was left behind: "38 accepted" in green above a
  // list that still holds two rows reads as a finished job.
  const badge = skipped > 0 ? theme.badges.warning : theme.badges.success

  return (
    <div
      data-testid="registrations-batch-result"
      role="status"
      style={{
        background: badge.bg,
        border: `1px solid ${badge.border}`,
        color: badge.text,
        borderRadius: theme.borderRadius.md,
        padding: theme.spacing.md,
        marginBottom: theme.spacing.lg,
        display: 'flex',
        gap: theme.spacing.md,
        alignItems: 'flex-start',
        justifyContent: 'space-between',
      }}
    >
      <div style={{ minWidth: 0 }}>
        <p data-testid="batch-result-accepted" style={{ margin: 0, fontWeight: 600 }}>
          {accepted > 0
            ? t('registrations.acceptAll.resultAccepted', { count: accepted })
            : t('registrations.acceptAll.resultNone')}
        </p>

        {skipped > 0 && (
          <>
            <p data-testid="batch-result-skipped" style={{ margin: `${theme.spacing.sm} 0 0` }}>
              {t('registrations.acceptAll.resultSkipped', { count: skipped })}
            </p>
            <ul style={{ margin: `${theme.spacing.xs} 0 0`, paddingLeft: theme.spacing.lg }}>
              {[...byReason.entries()].map(([reason, count]) => (
                <li key={reason} data-testid={`batch-result-reason-${reason}`}>
                  {t('registrations.acceptAll.resultReason', {
                    count,
                    // The code is the fallback: an unknown reason still says
                    // *something* an admin can quote, rather than nothing.
                    reason: reasonText(reason, undefined, reason),
                  })}
                </li>
              ))}
            </ul>
          </>
        )}

        {accepted > 0 && (
          <p data-testid="batch-result-next" style={{ margin: `${theme.spacing.sm} 0 0` }}>
            {t('registrations.acceptAll.resultNext')}
          </p>
        )}
      </div>

      <button
        type="button"
        data-testid="batch-result-dismiss"
        onClick={onDismiss}
        style={{
          flexShrink: 0,
          minHeight: 44,
          padding: `0 ${theme.spacing.md}`,
          background: 'transparent',
          border: `1px solid ${badge.border}`,
          borderRadius: theme.borderRadius.md,
          color: 'inherit',
          fontWeight: 600,
          cursor: 'pointer',
        }}
      >
        {t('registrations.acceptAll.dismiss')}
      </button>
    </div>
  )
}

const paragraphStyle: React.CSSProperties = {
  margin: `0 0 ${theme.spacing.md} 0`,
  lineHeight: 1.5,
}
