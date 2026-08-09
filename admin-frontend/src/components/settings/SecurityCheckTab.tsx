/**
 * SecurityCheckTab Component
 *
 * The security self-check as an admin reads it (#247, ADR-0031 decision 3).
 * The installer answers "is this host safe to install on?" once; this answers
 * the question that outlives it — *did a host change break something since?* —
 * without a re-install.
 *
 * Every row is a measurement the backend just took, so the labels, the observed
 * values and the remedies are rendered exactly as the API delivered them rather
 * than translated here. That is deliberate: the installer's report, this page
 * and the CI smoke test are one engine with one set of words, and a second copy
 * of those words in a locale file would drift from what was actually measured.
 * Only the page's own chrome is translated.
 */

import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { Alert } from '../common/Alert'
import { useLatestRequest } from '../../hooks/useLatestRequest'
import { getSecurity } from '../../api/generated/security/security'
import { getApiErrorMessage } from '../../utils/apiErrors'
import type { SecurityFinding, SecurityReport } from '../../api/generated'

const CATEGORY_ORDER = ['exposure', 'data', 'transport', 'session', 'runtime'] as const

const STATUS_COLOR: Record<string, string> = {
  pass: theme.colors.semantic.success,
  warn: theme.colors.semantic.warning,
  fail: theme.colors.semantic.danger,
  unknown: theme.colors.text.muted,
}

const STATUS_SYMBOL: Record<string, string> = {
  pass: '✓',
  warn: '!',
  fail: '✕',
  unknown: '?',
}

export function SecurityCheckTab() {
  const { t } = useTranslation()
  const request = useLatestRequest()
  const [report, setReport] = useState<SecurityReport | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    const signal = request.next()
    try {
      setLoading(true)
      setError(null)
      const result = await getSecurity().getSecurityCheck({ signal })
      if (signal.aborted) return
      setReport(result)
    } catch (err: unknown) {
      if (signal.aborted) return
      setError(getApiErrorMessage(err, t('settings.security.loadFailed')))
    } finally {
      // A superseded request must not clear the spinner the newer one raised.
      if (!signal.aborted) setLoading(false)
    }
  }, [request, t])

  useEffect(() => {
    void load()
    return () => request.abort()
  }, [load, request])

  const findings = report?.findings ?? []
  const categories = CATEGORY_ORDER.filter((category) =>
    findings.some((finding) => finding.category === category)
  )

  return (
    <div data-testid="security-check-tab">
      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'flex-start',
          gap: theme.spacing.lg,
          marginBottom: theme.spacing.lg,
          flexWrap: 'wrap',
        }}
      >
        <div style={{ maxWidth: '640px' }}>
          <h2 style={{ margin: 0, fontSize: theme.typography.fontSize.lg }}>
            {t('settings.security.title')}
          </h2>
          <p style={{ margin: `${theme.spacing.sm} 0 0`, color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}>
            {t('settings.security.intro')}
          </p>
        </div>
        <button
          data-testid="security-check-refresh"
          onClick={() => void load()}
          disabled={loading}
          style={{
            padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
            borderRadius: theme.borderRadius.md,
            border: `1px solid ${theme.colors.border.light}`,
            background: 'transparent',
            color: theme.colors.text.primary,
            cursor: loading ? 'not-allowed' : 'pointer',
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          {t('settings.security.recheck')}
        </button>
      </div>

      {error && <Alert variant="danger" message={error} testId="security-check-error" />}

      {loading && !report && (
        <div data-testid="security-check-loading" style={{ color: theme.colors.text.secondary }}>
          {t('settings.security.measuring')}
        </div>
      )}

      {report && (
        <>
          <div data-testid="security-check-summary" style={{ marginBottom: theme.spacing.lg }}>
            <Alert
              variant={report.status === 'fail' ? 'danger' : report.status === 'warn' ? 'warning' : 'success'}
              title={t(`settings.security.verdict.${report.status}`)}
              message={t('settings.security.counts', {
                pass: report.summary?.pass ?? 0,
                warn: report.summary?.warn ?? 0,
                fail: report.summary?.fail ?? 0,
                unknown: report.summary?.unknown ?? 0,
              })}
              testId={`security-check-status-${report.status}`}
            />
          </div>

          {categories.map((category) => (
            <section key={category} style={{ marginBottom: theme.spacing.xl }}>
              <h3
                style={{
                  margin: `0 0 ${theme.spacing.md}`,
                  fontSize: theme.typography.fontSize.sm,
                  textTransform: 'uppercase',
                  letterSpacing: '0.06em',
                  color: theme.colors.text.muted,
                }}
              >
                {t(`settings.security.categories.${category}`)}
              </h3>
              <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
                {findings
                  .filter((finding) => finding.category === category)
                  .map((finding) => (
                    <FindingRow key={finding.id} finding={finding} />
                  ))}
              </div>
            </section>
          ))}

          <p style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.xs }}>
            {t('settings.security.footnote')}
          </p>
        </>
      )}
    </div>
  )
}

function FindingRow({ finding }: { finding: SecurityFinding }) {
  const color = STATUS_COLOR[finding.status] ?? theme.colors.text.muted

  return (
    <div
      data-testid={`security-check-finding-${finding.id}`}
      data-status={finding.status}
      style={{
        display: 'flex',
        gap: theme.spacing.md,
        padding: theme.spacing.md,
        background: theme.colors.bg.card,
        border: `1px solid ${theme.colors.border.light}`,
        borderLeft: `3px solid ${color}`,
        borderRadius: theme.borderRadius.md,
      }}
    >
      <div aria-hidden style={{ color, fontWeight: theme.typography.fontWeight.semibold, width: '16px', flexShrink: 0 }}>
        {STATUS_SYMBOL[finding.status] ?? '?'}
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div
          style={{
            display: 'flex',
            gap: theme.spacing.md,
            justifyContent: 'space-between',
            flexWrap: 'wrap',
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          <span>{finding.label}</span>
          <span
            data-testid={`security-check-observed-${finding.id}`}
            style={{ color, fontFamily: theme.typography.fontFamily.mono, wordBreak: 'break-all' }}
          >
            {finding.observed}
          </span>
        </div>
        {finding.remedy && (
          <p
            data-testid={`security-check-remedy-${finding.id}`}
            style={{
              margin: `${theme.spacing.sm} 0 0`,
              color: theme.colors.text.secondary,
              fontSize: theme.typography.fontSize.xs,
            }}
          >
            {finding.remedy}
          </p>
        )}
      </div>
    </div>
  )
}
