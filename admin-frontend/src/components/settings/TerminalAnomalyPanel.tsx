/**
 * What a click on the anomaly marker opens (ADR-0041 §4).
 *
 * The marker on the terminals list and the credentials board only carries a
 * count — `Terminal.open_anomaly_count` — because deciding whether a badge
 * should show at all does not need the detail behind it. This is that detail:
 * every open row for one terminal, fetched on open, with an Acknowledge
 * button per row. Before this existed an open anomaly could only be cleared
 * by calling the API directly, which made the marker permanent in practice —
 * a banner nobody can dismiss is a banner that gets ignored.
 *
 * Acknowledging removes the row from this list immediately rather than
 * waiting on a refetch, and calls `onAcknowledged` so the caller can refresh
 * its own terminal list — the badge this panel was opened from lives there,
 * not here.
 *
 * Test IDs: `terminal-anomaly-panel`, `terminal-anomaly-panel-content`,
 * `terminal-anomaly-panel-loading`, `terminal-anomaly-panel-empty`,
 * `terminal-anomaly-panel-error`, `terminal-anomaly-row-<id>`,
 * `terminal-anomaly-acknowledge-<id>`, `terminal-anomaly-panel-close`.
 */

import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme, formatDateTime } from '../../styles/design-system'
import { useModalDialog } from '../../hooks/useModalDialog'
import { getTerminals } from '../../api/generated/terminals/terminals'
import { getApiErrorMessage } from '../../utils/apiErrors'
import type { TerminalAnomaly } from '../../api/generated'

const KIND_LABEL_KEY: Record<string, string> = {
  concurrent_ip: 'settings.terminalAnomalyKindConcurrentIp',
  cursor_regression: 'settings.terminalAnomalyKindCursorRegression',
  cursor_reset: 'settings.terminalAnomalyKindCursorReset',
}

export interface TerminalAnomalyPanelProps {
  isOpen: boolean
  terminalId: string
  terminalName: string
  onClose: () => void
  /** Fired once per successful acknowledgement, so a caller with its own terminal list can refresh it. */
  onAcknowledged: () => void
}

export function TerminalAnomalyPanel({
  isOpen,
  terminalId,
  terminalName,
  onClose,
  onAcknowledged,
}: TerminalAnomalyPanelProps) {
  const { t } = useTranslation()
  const contentRef = useModalDialog(isOpen, onClose)

  const [anomalies, setAnomalies] = useState<TerminalAnomaly[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [acknowledgingId, setAcknowledgingId] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const result = await getTerminals().listTerminalAnomalies(terminalId)
      setAnomalies(result.anomalies ?? [])
    } catch (err: unknown) {
      setError(getApiErrorMessage(err, t('settings.terminalAnomalyLoadError')))
    } finally {
      setLoading(false)
    }
  }, [terminalId, t])

  useEffect(() => {
    if (isOpen) void load()
  }, [isOpen, load])

  const handleAcknowledge = async (anomalyId: string) => {
    setAcknowledgingId(anomalyId)
    setError(null)
    try {
      await getTerminals().acknowledgeTerminalAnomaly(terminalId, anomalyId)
      setAnomalies((current) => current.filter((anomaly) => anomaly.id !== anomalyId))
      onAcknowledged()
    } catch (err: unknown) {
      setError(getApiErrorMessage(err, t('settings.terminalAnomalyAckError')))
    } finally {
      setAcknowledgingId(null)
    }
  }

  if (!isOpen) return null

  return (
    <div
      data-testid="terminal-anomaly-panel"
      tabIndex={-1}
      style={{
        position: 'fixed',
        inset: 0,
        background: theme.overlay.backdrop,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 2000,
      }}
      onClick={onClose}
    >
      <div
        ref={contentRef}
        data-testid="terminal-anomaly-panel-content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="terminal-anomaly-panel-title"
        tabIndex={-1}
        style={{
          background: theme.colors.bg.secondary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '560px',
          width: '90%',
          maxHeight: '80vh',
          overflowY: 'auto',
          boxShadow: theme.shadows.modalStrong,
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <h2
          id="terminal-anomaly-panel-title"
          style={{
            margin: 0,
            marginBottom: theme.spacing.sm,
            fontSize: theme.typography.fontSize.lg,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
          }}
        >
          {t('settings.terminalAnomalyPanelTitle', { name: terminalName })}
        </h2>
        <p
          style={{
            margin: 0,
            marginBottom: theme.spacing.lg,
            color: theme.colors.text.secondary,
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          {t('settings.terminalAnomalyPanelIntro')}
        </p>

        {error && (
          <div
            data-testid="terminal-anomaly-panel-error"
            style={{
              marginBottom: theme.spacing.md,
              color: theme.colors.semantic.danger,
              fontSize: theme.typography.fontSize.sm,
            }}
          >
            {error}
          </div>
        )}

        {loading && (
          <div
            data-testid="terminal-anomaly-panel-loading"
            style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}
          >
            {t('common.loading')}
          </div>
        )}

        {!loading && anomalies.length === 0 && (
          <div
            data-testid="terminal-anomaly-panel-empty"
            style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}
          >
            {t('settings.terminalAnomalyEmpty')}
          </div>
        )}

        <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.md }}>
          {anomalies.map((anomaly) => (
            <div
              key={anomaly.id}
              data-testid={`terminal-anomaly-row-${anomaly.id}`}
              style={{
                padding: theme.spacing.md,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.md,
                display: 'flex',
                flexWrap: 'wrap',
                gap: theme.spacing.md,
                justifyContent: 'space-between',
                alignItems: 'center',
              }}
            >
              <div style={{ minWidth: 0, flex: 1 }}>
                <div style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.primary }}>
                  {anomaly.kind && KIND_LABEL_KEY[anomaly.kind] ? t(KIND_LABEL_KEY[anomaly.kind]) : anomaly.kind}
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
                  <span>
                    {t('settings.terminalAnomalyFirstDetected')}:{' '}
                    {anomaly.first_detected_at ? formatDateTime(anomaly.first_detected_at) : ''}
                  </span>
                  <span>
                    {t('settings.terminalAnomalyLastDetected')}:{' '}
                    {anomaly.last_detected_at ? formatDateTime(anomaly.last_detected_at) : ''}
                  </span>
                  <span>{t('settings.terminalAnomalyOccurrences', { count: anomaly.occurrence_count ?? 1 })}</span>
                </div>
              </div>
              <button
                data-testid={`terminal-anomaly-acknowledge-${anomaly.id}`}
                onClick={() => anomaly.id && handleAcknowledge(anomaly.id)}
                disabled={acknowledgingId === anomaly.id}
                style={{
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  borderRadius: theme.borderRadius.md,
                  border: `1px solid ${theme.colors.border.light}`,
                  background: 'transparent',
                  color: theme.colors.text.primary,
                  fontSize: theme.typography.fontSize.sm,
                  cursor: acknowledgingId === anomaly.id ? 'not-allowed' : 'pointer',
                  opacity: acknowledgingId === anomaly.id ? 0.6 : 1,
                  whiteSpace: 'nowrap',
                }}
              >
                {t('settings.terminalAnomalyAcknowledge')}
              </button>
            </div>
          ))}
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: theme.spacing.lg }}>
          <button
            data-testid="terminal-anomaly-panel-close"
            onClick={onClose}
            style={{
              padding: `${theme.spacing.md} ${theme.spacing.lg}`,
              background: 'transparent',
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.text.primary,
              cursor: 'pointer',
              fontSize: theme.typography.fontSize.sm,
              fontWeight: theme.typography.fontWeight.semibold,
            }}
          >
            {t('common.close')}
          </button>
        </div>
      </div>
    </div>
  )
}
