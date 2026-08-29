/**
 * The terminal half of Security & Credentials (#395, ADR-0036).
 *
 * A terminal's bearer token is a long-lived credential exactly like the IBAN
 * encryption keypair above it: it expires, it has to be replaced before it
 * does, and letting it lapse takes the bar offline. So it is warned about on
 * the same 90/30/7 tiers, computed by the server as the response is built —
 * there is no cron on shared hosting (ADR-0031).
 *
 * Rotation here is *overlap* rotation: the new token is staged alongside the
 * one in the field, and the terminal promotes it the first time it is used. So
 * this page can say two things the Terminals tab never could — that a rotation
 * is prepared and still waiting to be typed in, and when the credential was
 * last used at all, which is how a device somebody quietly took home shows up.
 *
 * Test IDs: `credentials-terminals`, `credentials-terminal-<id>` (with
 * `data-lifecycle-state` / `data-has-pending-token`),
 * `credentials-terminal-rotate-<id>`, `credentials-terminal-last-used-<id>`,
 * `credentials-terminal-expiry-<id>`, `credentials-terminals-empty`.
 */

import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useApiError } from '../../hooks/useApiError'
import { theme, formatDateTime } from '../../styles/design-system'
import { Alert } from '../common/Alert'
import { Badge } from '../common/Badge'
import { Tooltip } from '../common/Tooltip'
import { TerminalLifecycleBadge } from './TerminalLifecycleBadge'
import { TerminalAnomalyPanel } from './TerminalAnomalyPanel'
import { StepUpConfirmDialog, type StepUpCredentials } from '../modals/StepUpConfirmDialog'
import { TokenDisplayModal } from '../modals/TokenDisplayModal'
import { useLatestRequest } from '../../hooks/useLatestRequest'
import { getTerminals } from '../../api/generated/terminals/terminals'
import type { Terminal } from '../../api/generated'

/** Matches the key cards above, so one page speaks with one colour language. */
const LIFECYCLE_COLOR: Record<string, string> = {
  ok: theme.colors.semantic.success,
  info: theme.colors.text.secondary,
  warning: theme.colors.semantic.warning,
  critical: theme.colors.semantic.danger,
  expired: theme.colors.semantic.danger,
}

/**
 * One page of terminals is the whole list here: a club runs a handful of tills,
 * and a credentials board that paginated would hide the one that is about to
 * expire behind a page control nobody clicks.
 */
const PAGE_SIZE = 100

export interface TerminalCredentialsProps {
  callerTotpEnabled: boolean
}

export function TerminalCredentials({ callerTotpEnabled }: TerminalCredentialsProps) {
  const { t } = useTranslation()
  const { apiErrorMessage } = useApiError()
  const request = useLatestRequest()

  const [terminals, setTerminals] = useState<Terminal[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)

  const [rotateTarget, setRotateTarget] = useState<Terminal | null>(null)
  const [dialogError, setDialogError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [issuedToken, setIssuedToken] = useState<string | null>(null)
  const [anomalyTarget, setAnomalyTarget] = useState<Terminal | null>(null)

  const load = useCallback(async () => {
    const signal = request.next()
    try {
      setLoading(true)
      const result = await getTerminals().listTerminals({ per_page: PAGE_SIZE }, { signal })
      if (signal.aborted) return
      setTerminals(result.data ?? [])
      setError(null)
    } catch (err: unknown) {
      if (signal.aborted) return
      setError(apiErrorMessage(err, t('settings.credentials.terminals.errors.load')))
    } finally {
      if (!signal.aborted) setLoading(false)
    }
  }, [request, t, apiErrorMessage])

  useEffect(() => {
    void load()
    return () => request.abort()
  }, [load, request])

  const handleRotate = async (credentials: StepUpCredentials) => {
    if (!rotateTarget?.id) return
    setBusy(true)
    try {
      const result = await getTerminals().rotateTerminalToken(rotateTarget.id, credentials)
      setRotateTarget(null)
      setDialogError(null)
      setError(null)
      setSuccess(t('settings.credentials.terminals.rotated'))
      // Shown exactly once — the server keeps only a hash, so a token closed
      // away unread is a rotation that has to be done again.
      setIssuedToken(result.api_token ?? null)
      await load()
    } catch (err: unknown) {
      setDialogError(apiErrorMessage(err, t('settings.credentials.terminals.errors.rotate')))
    } finally {
      setBusy(false)
    }
  }

  // Loudest first: a credential that already stopped working outranks one that
  // is merely close, and both outrank the tills nobody has to think about.
  const ordered = [...terminals].sort(
    (a, b) => (a.days_until_expiry ?? Number.MAX_SAFE_INTEGER) - (b.days_until_expiry ?? Number.MAX_SAFE_INTEGER),
  )

  return (
    <section data-testid="credentials-terminals" style={{ marginTop: theme.spacing.xl }}>
      <h2 style={{ margin: 0, fontSize: theme.typography.fontSize.lg }}>
        {t('settings.credentials.terminals.title')}
      </h2>
      <p
        style={{
          margin: `${theme.spacing.sm} 0 ${theme.spacing.lg}`,
          color: theme.colors.text.secondary,
          fontSize: theme.typography.fontSize.sm,
          maxWidth: '640px',
        }}
      >
        {t('settings.credentials.terminals.intro')}
      </p>

      {error && <Alert variant="danger" message={error} testId="credentials-terminals-error" />}
      {success && (
        <Alert variant="success" message={success} dismissible testId="credentials-terminals-success" />
      )}

      {loading && terminals.length === 0 && (
        <div data-testid="credentials-terminals-loading" style={{ color: theme.colors.text.secondary }}>
          {t('common.loading')}
        </div>
      )}

      {!loading && terminals.length === 0 && (
        <div
          data-testid="credentials-terminals-empty"
          style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}
        >
          {t('settings.credentials.terminals.empty')}
        </div>
      )}

      <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.md }}>
        {ordered.map((terminal) => (
          <TerminalCard
            key={terminal.id}
            terminal={terminal}
            onRotate={() => {
              setDialogError(null)
              setRotateTarget(terminal)
            }}
            onSelectAnomaly={() => setAnomalyTarget(terminal)}
          />
        ))}
      </div>

      <StepUpConfirmDialog
        isOpen={rotateTarget !== null}
        title={t('settings.credentials.terminals.rotate')}
        message={t('settings.credentials.terminals.rotateHint', { name: rotateTarget?.name ?? '' })}
        confirmLabel={t('settings.credentials.terminals.rotate')}
        requiresTotp={callerTotpEnabled}
        error={dialogError}
        confirmDisabled={busy}
        onConfirm={handleRotate}
        onCancel={() => {
          setRotateTarget(null)
          setDialogError(null)
        }}
      />

      <TokenDisplayModal
        isOpen={issuedToken !== null}
        token={issuedToken}
        onClose={() => setIssuedToken(null)}
      />

      <TerminalAnomalyPanel
        isOpen={anomalyTarget !== null}
        terminalId={anomalyTarget?.id ?? ''}
        terminalName={anomalyTarget?.name ?? ''}
        onClose={() => setAnomalyTarget(null)}
        onAcknowledged={() => void load()}
      />
    </section>
  )
}

function TerminalCard({
  terminal,
  onRotate,
  onSelectAnomaly,
}: {
  terminal: Terminal
  onRotate: () => void
  onSelectAnomaly: () => void
}) {
  const { t } = useTranslation()

  // No expiry means no credential at all — a revoked terminal, or one whose
  // access was never issued. The lifecycle tiers have nothing to say about
  // that, so it gets its own label rather than a reassuring green "ok".
  const hasToken = !!terminal.token_expires_at
  const lifecycle = hasToken ? (terminal.lifecycle_state ?? 'ok') : 'none'
  const pending = terminal.has_pending_token === true

  return (
    <div
      data-testid={`credentials-terminal-${terminal.id}`}
      data-lifecycle-state={lifecycle}
      data-has-pending-token={pending}
      style={{
        padding: theme.spacing.lg,
        background: theme.colors.bg.card,
        border: `1px solid ${theme.colors.border.light}`,
        borderLeft: `3px solid ${LIFECYCLE_COLOR[lifecycle] ?? theme.colors.border.light}`,
        borderRadius: theme.borderRadius.md,
        display: 'flex',
        flexWrap: 'wrap',
        gap: theme.spacing.lg,
        justifyContent: 'space-between',
        opacity: terminal.is_active ? 1 : 0.6,
      }}
    >
      <div style={{ minWidth: 0, flex: 1 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.md, flexWrap: 'wrap' }}>
          <strong style={{ fontSize: theme.typography.fontSize.base }}>{terminal.name}</strong>
          <TerminalLifecycleBadge lifecycle={lifecycle} testId={`credentials-terminal-state-${terminal.id}`} />
          {pending && (
            <Badge
              label={t('settings.credentials.terminals.pending')}
              variant="info"
              showDot={false}
              testId={`credentials-terminal-pending-${terminal.id}`}
            />
          )}
          {/* Same marker as the Terminals tab (ADR-0041 §4) — a credential in
              doubt is exactly what this board exists to surface. */}
          {terminal.has_open_anomaly && (
            <Tooltip content={t('settings.terminalAnomalyHint')}>
              <button
                type="button"
                onClick={onSelectAnomaly}
                data-testid={`credentials-terminal-anomaly-${terminal.id}`}
                data-anomaly-count={terminal.open_anomaly_count ?? 0}
                style={{ background: 'none', border: 'none', padding: 0, margin: 0, cursor: 'pointer' }}
              >
                <Badge label={t('settings.terminalAnomaly')} variant="danger" />
              </button>
            </Tooltip>
          )}
        </div>

        <div
          style={{
            marginTop: theme.spacing.sm,
            fontFamily: theme.typography.fontFamily.mono,
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.muted,
            wordBreak: 'break-all',
          }}
        >
          {terminal.device_id}
        </div>

        <div
          style={{
            marginTop: theme.spacing.sm,
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.secondary,
            display: 'flex',
            gap: theme.spacing.lg,
            flexWrap: 'wrap',
          }}
        >
          {hasToken && (
            <span data-testid={`credentials-terminal-expiry-${terminal.id}`}>
              {t('settings.credentials.expiresAt')}: {formatDateTime(terminal.token_expires_at!)}
              {terminal.days_until_expiry !== null && terminal.days_until_expiry !== undefined && (
                <span style={{ color: LIFECYCLE_COLOR[lifecycle], marginLeft: theme.spacing.sm }}>
                  ({t('settings.credentials.daysLeft', { count: terminal.days_until_expiry })})
                </span>
              )}
            </span>
          )}
          {/* Every authenticated request refreshes this, so it is the
              credential's last-used stamp, not just the last delta sync. */}
          <span data-testid={`credentials-terminal-last-used-${terminal.id}`}>
            {t('settings.credentials.terminals.lastUsed')}:{' '}
            {terminal.last_sync_at ? formatDateTime(terminal.last_sync_at) : t('dates.never')}
          </span>
        </div>

        {pending && (
          <p
            data-testid={`credentials-terminal-pending-hint-${terminal.id}`}
            style={{
              margin: `${theme.spacing.sm} 0 0`,
              fontSize: theme.typography.fontSize.xs,
              color: theme.colors.text.secondary,
            }}
          >
            {t('settings.credentials.terminals.pendingHint')}
          </p>
        )}
      </div>

      <div style={{ display: 'flex', gap: theme.spacing.sm, alignItems: 'flex-start' }}>
        <button
          data-testid={`credentials-terminal-rotate-${terminal.id}`}
          onClick={onRotate}
          disabled={!terminal.is_active}
          style={{
            padding: `${theme.spacing.sm} ${theme.spacing.md}`,
            borderRadius: theme.borderRadius.md,
            border: `1px solid ${theme.colors.border.light}`,
            background: lifecycle === 'ok' || !terminal.is_active ? 'transparent' : theme.colors.semantic.primary,
            color: lifecycle === 'ok' || !terminal.is_active ? theme.colors.text.primary : 'white',
            fontSize: theme.typography.fontSize.sm,
            cursor: terminal.is_active ? 'pointer' : 'not-allowed',
            opacity: terminal.is_active ? 1 : 0.5,
            whiteSpace: 'nowrap',
          }}
        >
          {t('settings.credentials.terminals.rotate')}
        </button>
      </div>
    </div>
  )
}
