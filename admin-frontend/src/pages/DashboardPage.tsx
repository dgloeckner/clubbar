import { useState, useEffect, useCallback, useRef } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { PageHeader } from '../components/layout/PageHeader'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useFormatters } from '../hooks/useFormatters'
import { useLatestRequest } from '../hooks/useLatestRequest'
import { getDashboard } from '../api/generated/dashboard/dashboard'
import { getTransactions as getTransactionsApi } from '../api/generated/transactions/transactions'
import type { DashboardResponse } from '../api/generated'

/** One row of the unacknowledged list. Member-free by construction. */
interface JugendschutzViolation {
  id: number
  transaction_id: string
  min_age: number
  recorded_at: string
}
import { StatCard } from '../components/common/StatCard'
import { UsersIcon, ReceiptIcon, BookIcon } from '../components/icons'
import { HomeIcon } from '../components/icons/HomeIcon'
import { getTransactionAmountColor } from '../utils/transactions'
import { encryptionKeyMessage, sepaConfigMessage } from '../utils/dashboardAlerts'

const AUTO_REFRESH_INTERVAL = 10_000 // 10 seconds

export function DashboardPage() {
  const { t } = useTranslation()
  const breakpoint = useBreakpoint()
  const { formatPrice, formatDateTime, formatRelativeDate, intlLocale } = useFormatters()

  const [data, setData] = useState<DashboardResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  // When a refresh fails the page keeps rendering the numbers it already has —
  // which is the right call, but only if it says how old they are (#132).
  const [lastUpdatedAt, setLastUpdatedAt] = useState<Date | null>(null)
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const request = useLatestRequest()

  const fetchDashboard = useCallback(async (showSkeleton = true) => {
    // A slow backend lets tick N land after tick N+1; without this the older
    // answer would overwrite the newer one. The newest tick wins, and the
    // in-flight request dies with the page.
    const signal = request.next()
    try {
      if (showSkeleton) {
        setLoading(true)
      }
      const response = await getDashboard().getDashboardMetrics({ signal })
      if (signal.aborted) return
      setData(response)
      setLastUpdatedAt(new Date())
      setError(null)
    } catch (err) {
      if (signal.aborted) return
      setError(t('errors.generic'))
    } finally {
      if (!signal.aborted) {
        setLoading(false)
      }
    }
  }, [request, t])

  /**
   * The violations behind the Jugendschutz alert (#622).
   *
   * A second stream with its own slot, per the data-fetching pattern: the
   * dashboard's own poll must not abort this list and vice versa. It is fetched
   * only when the alert says there is something to fetch, so an installation
   * that has never had a violation makes no request at all.
   */
  const violationsRequest = useLatestRequest()
  const [violations, setViolations] = useState<JugendschutzViolation[]>([])
  const [acknowledging, setAcknowledging] = useState<number | null>(null)
  const [acknowledgeError, setAcknowledgeError] = useState<string | null>(null)

  const fetchViolations = useCallback(async () => {
    const signal = violationsRequest.next()
    try {
      const response = await getTransactionsApi().getJugendschutzViolations({ signal })
      if (signal.aborted) return
      setViolations((response.data ?? []) as JugendschutzViolation[])
    } catch {
      if (signal.aborted) return
      setViolations([])
    }
  }, [violationsRequest])

  const acknowledgeViolation = useCallback(async (id: number) => {
    setAcknowledging(id)
    setAcknowledgeError(null)
    try {
      await getTransactionsApi().acknowledgeJugendschutzViolation(id, {})
      // Both, and in this order: the list is what the admin is looking at, the
      // dashboard carries the count beside it. Refreshing one and not the other
      // leaves a red badge over an empty list.
      await fetchViolations()
      await fetchDashboard(false)
    } catch {
      setAcknowledgeError(t('dashboard.jugendschutzAcknowledgeFailed'))
    } finally {
      setAcknowledging(null)
    }
  }, [fetchViolations, fetchDashboard, t])

  // Initial load + auto-refresh
  useEffect(() => {
    fetchDashboard()
    intervalRef.current = setInterval(() => fetchDashboard(false), AUTO_REFRESH_INTERVAL)
    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current)
      request.abort()
    }
  }, [fetchDashboard, request])

  // Read off `data` rather than the `alerts` binding below, which is derived
  // after the loading guard and so is not in scope for an effect.
  const unacknowledgedViolations = data?.alerts?.jugendschutz_violation?.count ?? 0

  // Only when there is something to show, so an installation that has never had
  // a violation never makes the request.
  useEffect(() => {
    if (unacknowledgedViolations > 0) {
      void fetchViolations()
    } else {
      setViolations([])
    }
    return () => violationsRequest.abort()
  }, [unacknowledgedViolations, fetchViolations, violationsRequest])

  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'

  if (loading && !data) {
    return (
      <div data-testid="dashboard-page">
        <div data-testid="dashboard-loading" style={{ color: theme.colors.text.secondary }}>
          {t('common.loading')}
        </div>
      </div>
    )
  }

  if (error && !data) {
    return (
      <div data-testid="dashboard-page">
        <div data-testid="dashboard-error" style={{ color: theme.colors.semantic.danger }}>
          {error}
        </div>
      </div>
    )
  }

  if (!data) return null

  const metrics = data.metrics ?? {}
  const recent_transactions = data.recent_transactions ?? []
  const terminal_status = data.terminal_status ?? []
  const system_status = data.system_status ?? {}
  const alerts = data.alerts ?? {}
  const encryptionKeyAlert = alerts.encryption_key
  // An alert whose severity the API left out is treated as nothing to say,
  // rather than as an unstyled banner shouting a blank message.
  const encryptionKeySeverity = encryptionKeyAlert?.severity ?? 'none'
  // Built here rather than shown from `alert.message`: the backend's string is
  // always English, and this page renders in the admin's chosen language.
  const encryptionKeyText = encryptionKeyMessage(encryptionKeyAlert ?? {})
  const sepaConfigAlert = alerts.sepa_config
  const sepaConfigSeverity = sepaConfigAlert?.severity ?? 'none'
  // Same again (#741) — this banner used to read English on a German page.
  const sepaConfigText = sepaConfigMessage(sepaConfigAlert ?? {})
  const jugendschutzAlert = alerts.jugendschutz_violation
  const jugendschutzSeverity = jugendschutzAlert?.severity ?? 'none'
  const jugendschutzCount = jugendschutzAlert?.count ?? 0
  // Built here rather than shown from `alert.message`: the backend's string is
  // always English, and this page renders in the admin's chosen language.
  const jugendschutzMessage =
    jugendschutzCount === 1
      ? t('dashboard.jugendschutzOne')
      : t('dashboard.jugendschutzMany', { count: jugendschutzCount })
  const terminalAnomalyAlert = alerts.terminal_anomaly
  const terminalAnomalySeverity = terminalAnomalyAlert?.severity ?? 'none'
  // Built here rather than shown from `alert.message`: the backend's string is
  // always English, and this page renders in the admin's chosen language.
  const terminalAnomalyTerminalCount = terminalAnomalyAlert?.terminal_count ?? 0
  const terminalAnomalyMessage =
    terminalAnomalyTerminalCount === 1
      ? terminalAnomalyAlert?.terminal_name
        ? t('dashboard.terminalAnomalySingle', { name: terminalAnomalyAlert.terminal_name })
        : t('dashboard.terminalAnomalyUnnamed')
      : t('dashboard.terminalAnomalyMultiple', { count: terminalAnomalyTerminalCount })
  const members_near_limit = data.members_near_limit
  const near_limit_members = members_near_limit?.members ?? []
  // The list is capped by the backend; the total says whether it is the whole
  // story, so a quiet-looking panel is never hiding a queue of blocked members.
  const near_limit_hidden = Math.max(0, (members_near_limit?.total ?? 0) - near_limit_members.length)

  const statusColor = (status: string) => {
    switch (status) {
      case 'online': return theme.colors.semantic.success
      case 'offline': return theme.colors.semantic.warning
      case 'disabled': return theme.colors.text.muted
      default: return theme.colors.text.secondary
    }
  }

  // Past the limit the terminal has already stopped serving them; inside the
  // band it is still only a warning.
  const limitStatusColor = (status: string) =>
    status === 'exceeded' ? theme.colors.semantic.danger : theme.colors.semantic.warning

  const severityColor = (severity: string) => {
    switch (severity) {
      case 'error': return theme.colors.semantic.danger
      case 'warning': return theme.colors.semantic.warning
      default: return theme.colors.semantic.success
    }
  }

  return (
    <div data-testid="dashboard-page" style={{ maxWidth: '1200px' }}>
      {/* The one tab that never named itself. Every other page opens with its
          own name, so arriving here read as having landed mid-page (#375). */}
      <PageHeader title={t('dashboard.title')} />

      {/* A failing auto-refresh below an unchanged page is indistinguishable
          from a quiet club, so say the numbers have stopped moving. */}
      {error && data && (
        <div
          data-testid="dashboard-stale-warning"
          style={{
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            background: 'rgba(249, 115, 22, 0.12)',
            border: `1px solid ${theme.colors.semantic.warning}`,
            borderRadius: theme.borderRadius.md,
            color: theme.colors.semantic.warning,
            fontSize: theme.typography.fontSize.sm,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: theme.spacing.md,
            flexWrap: 'wrap',
          }}
        >
          <span>
            {lastUpdatedAt
              ? t('dashboard.staleSince', { time: lastUpdatedAt.toLocaleTimeString(intlLocale, { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }) })
              : t('dashboard.stale')}
          </span>
          <button
            data-testid="dashboard-stale-retry"
            onClick={() => fetchDashboard(false)}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: `1px solid ${theme.colors.semantic.warning}`,
              background: 'transparent',
              color: theme.colors.semantic.warning,
              fontSize: '13px',
              fontWeight: 600,
              cursor: 'pointer',
            }}
          >
            {t('common.retry')}
          </button>
        </div>
      )}

      {/* The IBAN encryption key's remaining lifetime (#394, ADR-0036).
          Nothing evaluates expiry on a schedule — shared hosting has no cron —
          so the landing page is where the club finds out, while there is still
          time to rotate. Silent while the key is comfortably valid. */}
      {encryptionKeyAlert && encryptionKeySeverity !== 'none' && (
        <div
          data-testid="dashboard-encryption-key-warning"
          data-severity={encryptionKeySeverity}
          data-state={encryptionKeyAlert.state}
          style={{
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            background:
              encryptionKeySeverity === 'error' ? 'rgba(239, 68, 68, 0.12)' : 'rgba(249, 115, 22, 0.12)',
            border: `1px solid ${severityColor(encryptionKeySeverity)}`,
            borderRadius: theme.borderRadius.md,
            color: severityColor(encryptionKeySeverity),
            fontSize: theme.typography.fontSize.sm,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: theme.spacing.md,
            flexWrap: 'wrap',
          }}
        >
          <span>{t(encryptionKeyText.key, encryptionKeyText.values)}</span>
          <Link
            data-testid="dashboard-encryption-key-link"
            to="/settings"
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: `1px solid ${severityColor(encryptionKeySeverity)}`,
              color: severityColor(encryptionKeySeverity),
              fontSize: '13px',
              fontWeight: 600,
              textDecoration: 'none',
            }}
          >
            {t('dashboard.manageKeys')}
          </Link>
        </div>
      )}

      {/* SEPA configuration completeness (#360/#456): the club's creditor
          details and the externally hosted mandate template's URL both have
          to be set before a settlement can be exported to the bank. Nothing
          evaluates this on a schedule, so — same as the encryption key above
          — the landing page is where it surfaces, before an admin hits the
          export-time block instead. Silent once both are configured. */}
      {sepaConfigAlert && sepaConfigSeverity !== 'none' && (
        <div
          data-testid="dashboard-sepa-config-warning"
          data-severity={sepaConfigSeverity}
          style={{
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            background:
              sepaConfigSeverity === 'error' ? 'rgba(239, 68, 68, 0.12)' : 'rgba(249, 115, 22, 0.12)',
            border: `1px solid ${severityColor(sepaConfigSeverity)}`,
            borderRadius: theme.borderRadius.md,
            color: severityColor(sepaConfigSeverity),
            fontSize: theme.typography.fontSize.sm,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: theme.spacing.md,
            flexWrap: 'wrap',
          }}
        >
          <span>{t(sepaConfigText.key, sepaConfigText.values)}</span>
          <Link
            data-testid="dashboard-sepa-config-link"
            to="/settings"
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: `1px solid ${severityColor(sepaConfigSeverity)}`,
              color: severityColor(sepaConfigSeverity),
              fontSize: '13px',
              fontWeight: 600,
              textDecoration: 'none',
            }}
          >
            {t('dashboard.manageSepaConfig')}
          </Link>
        </div>
      )}

      {/* Terminal credential anomalies (ADR-0041): a terminal token that looks
          like it is being used on more than one device. Unlike the two banners
          above, this one is not a "you have not configured something" notice —
          it reports an observation the cron tick made, and nothing has been
          blocked as a result. The link goes to the terminals settings, which is
          where the credential can be rotated or revoked if the admin decides
          that is what this is. Silent while nothing is open. */}
      {/* Jugendschutz (#622, ADR-0045 §3). Above the terminal-anomaly banner:
          a minor having been served alcohol outranks a credential that looks
          odd. Silent while nothing is unacknowledged.

          It names no member. The dashboard reaches the Kassenwart, who may see
          members elsewhere — but this renders on a screen that can be open in
          the clubroom, and rule 6 does not stop at the till. The transaction id
          is the handle into the journal. */}
      {jugendschutzAlert && jugendschutzSeverity !== 'none' && (
        <div
          data-testid="dashboard-jugendschutz-warning"
          data-severity={jugendschutzSeverity}
          data-count={jugendschutzCount}
          style={{
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            background: 'rgba(239, 68, 68, 0.12)',
            border: `1px solid ${severityColor(jugendschutzSeverity)}`,
            borderRadius: theme.borderRadius.md,
            color: severityColor(jugendschutzSeverity),
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          <div style={{ fontWeight: 700, marginBottom: theme.spacing.xs }}>
            {t('dashboard.jugendschutzTitle')}
          </div>
          <div data-testid="dashboard-jugendschutz-message">{jugendschutzMessage}</div>
          <div style={{ marginTop: theme.spacing.xs, opacity: 0.85 }}>
            {t('dashboard.jugendschutzIntro')}
          </div>

          {acknowledgeError && (
            <div data-testid="dashboard-jugendschutz-error" style={{ marginTop: theme.spacing.sm, fontWeight: 600 }}>
              {acknowledgeError}
            </div>
          )}

          <ul
            data-testid="dashboard-jugendschutz-list"
            style={{ listStyle: 'none', margin: `${theme.spacing.md} 0 0`, padding: 0 }}
          >
            {violations.map((violation) => (
              <li
                key={violation.id}
                data-testid="dashboard-jugendschutz-item"
                data-violation-id={violation.id}
                data-transaction-id={violation.transaction_id}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  gap: theme.spacing.md,
                  flexWrap: 'wrap',
                  padding: `${theme.spacing.sm} 0`,
                  borderTop: `1px solid ${severityColor(jugendschutzSeverity)}33`,
                }}
              >
                <span>
                  <strong>{t('dashboard.jugendschutzRequiredAge', { age: violation.min_age })}</strong>
                  {' · '}
                  <code style={{ fontSize: '12px' }}>{violation.transaction_id}</code>
                  {' · '}
                  <span style={{ opacity: 0.85 }}>
                    {t('dashboard.jugendschutzRecordedAt', { at: violation.recorded_at })}
                  </span>
                </span>
                <button
                  type="button"
                  data-testid="dashboard-jugendschutz-acknowledge"
                  disabled={acknowledging === violation.id}
                  onClick={() => void acknowledgeViolation(violation.id)}
                  style={{
                    padding: '6px 14px',
                    borderRadius: '6px',
                    border: `1px solid ${severityColor(jugendschutzSeverity)}`,
                    background: 'transparent',
                    color: severityColor(jugendschutzSeverity),
                    fontSize: '13px',
                    fontWeight: 600,
                    cursor: acknowledging === violation.id ? 'wait' : 'pointer',
                  }}
                >
                  {acknowledging === violation.id
                    ? t('dashboard.jugendschutzAcknowledging')
                    : t('dashboard.jugendschutzAcknowledge')}
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}

      {terminalAnomalyAlert && terminalAnomalySeverity !== 'none' && (
        <div
          data-testid="dashboard-terminal-anomaly-warning"
          data-severity={terminalAnomalySeverity}
          data-kinds={(terminalAnomalyAlert.kinds ?? []).join(',')}
          style={{
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            background:
              terminalAnomalySeverity === 'error'
                ? 'rgba(239, 68, 68, 0.12)'
                : 'rgba(249, 115, 22, 0.12)',
            border: `1px solid ${severityColor(terminalAnomalySeverity)}`,
            borderRadius: theme.borderRadius.md,
            color: severityColor(terminalAnomalySeverity),
            fontSize: theme.typography.fontSize.sm,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: theme.spacing.md,
            flexWrap: 'wrap',
          }}
        >
          <span>{terminalAnomalyMessage}</span>
          <Link
            data-testid="dashboard-terminal-anomaly-link"
            to="/settings"
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: `1px solid ${severityColor(terminalAnomalySeverity)}`,
              color: severityColor(terminalAnomalySeverity),
              fontSize: '13px',
              fontWeight: 600,
              textDecoration: 'none',
            }}
          >
            {t('dashboard.reviewTerminals')}
          </Link>
        </div>
      )}

      {/* Metrics Row */}
      <div data-testid="dashboard-metrics" style={{
        display: 'grid',
        gridTemplateColumns: isMobile ? '1fr' : 'repeat(3, 1fr)',
        gap: theme.spacing.lg,
        marginBottom: theme.spacing['2xl'],
      }}>
        <StatCard
          icon={<UsersIcon />}
          label={t('dashboard.activeMembers')}
          value={metrics.active_members ?? 0}
          color="blue"
        />
        <StatCard
          icon={<ReceiptIcon />}
          label={t('dashboard.outstandingBalance')}
          value={formatPrice(metrics.outstanding_balance_cents ?? 0)}
          color={(metrics.outstanding_balance_cents ?? 0) > 0 ? 'orange' : 'green'}
        />
        <StatCard
          icon={<HomeIcon />}
          label={t('dashboard.terminals')}
          value={`${metrics.active_terminals ?? 0}/${metrics.terminal_count ?? 0}`}
          color="blue"
        />
      </div>

      {/* Revenue Row */}
      <div data-testid="dashboard-revenue" style={{
        display: 'grid',
        gridTemplateColumns: isMobile ? '1fr' : 'repeat(3, 1fr)',
        gap: theme.spacing.lg,
        marginBottom: theme.spacing['2xl'],
      }}>
        <StatCard
          icon={<BookIcon />}
          label={t('dashboard.todaysRevenue')}
          value={formatPrice(metrics.todays_revenue_cents ?? 0)}
          color="green"
        />
        <StatCard
          icon={<BookIcon />}
          label={t('dashboard.wtdRevenue')}
          value={formatPrice(metrics.wtd_revenue_cents ?? 0)}
          color="green"
        />
        <StatCard
          icon={<BookIcon />}
          label={t('dashboard.mtdRevenue')}
          value={formatPrice(metrics.mtd_revenue_cents ?? 0)}
          color="green"
        />
      </div>

      {/* Two-column layout: Transactions + Sidebar */}
      <div style={{
        display: 'grid',
        gridTemplateColumns: isMobile ? 'minmax(0, 1fr)' : '2fr 1fr',
        gap: theme.spacing.xl,
      }}>
        {/* Recent Transactions */}
        <div data-testid="dashboard-recent-transactions" style={{
          background: theme.colors.bg.card,
          border: `1px solid ${theme.colors.border.light}`,
          borderRadius: theme.borderRadius.lg,
          padding: isMobile ? theme.spacing.md : theme.spacing.xl,
        }}>
          <h2 style={{
            fontSize: theme.typography.fontSize.lg,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
            margin: `0 0 ${theme.spacing.lg} 0`,
          }}>
            {t('dashboard.recentTransactions')}
          </h2>

          {recent_transactions.length === 0 ? (
            <div data-testid="dashboard-no-transactions" style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.sm }}>
              {t('dashboard.noRecentTransactions')}
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
              {recent_transactions.map((tx) => (
                <div key={tx.id} data-testid={`dashboard-transaction-${tx.id}`} style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  borderRadius: theme.borderRadius.sm,
                  background: theme.colors.bg.secondary,
                }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.primary, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                      {tx.member_name}
                    </div>
                    <div style={{ fontSize: theme.typography.fontSize.xs, color: theme.colors.text.muted, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                      {tx.product_name || t(`dashboard.${tx.type}`)}{tx.terminal_name ? ` · ${tx.terminal_name}` : ''} · {formatDateTime(tx.timestamp)}
                    </div>
                  </div>
                  <div data-testid={`dashboard-transaction-amount-${tx.id}`} style={{
                    fontSize: theme.typography.fontSize.sm,
                    fontWeight: theme.typography.fontWeight.semibold,
                    fontFamily: 'JetBrains Mono, monospace',
                    color: getTransactionAmountColor(tx.amount_cents ?? 0),
                    flexShrink: 0,
                    whiteSpace: 'nowrap',
                    marginLeft: theme.spacing.sm,
                  }}>
                    {formatPrice(tx.amount_cents)}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Sidebar: Terminals + Alerts + System Status */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.xl }}>
          {/* Terminal Status */}
          <div data-testid="dashboard-terminal-status" style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.lg,
            padding: isMobile ? theme.spacing.md : theme.spacing.xl,
          }}>
            <h2 style={{
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
              margin: `0 0 ${theme.spacing.lg} 0`,
            }}>
              {t('dashboard.terminalStatus')}
            </h2>

            {terminal_status.length === 0 ? (
              <div data-testid="dashboard-no-terminals" style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.sm }}>
                {t('dashboard.noTerminals')}
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
                {terminal_status.map((term) => (
                  <div key={term.id} data-testid={`dashboard-terminal-${term.id}`} style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                    borderRadius: theme.borderRadius.sm,
                    background: theme.colors.bg.secondary,
                  }}>
                    <span style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.primary, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', minWidth: 0 }}>
                      {term.name}
                    </span>
                    <span data-testid={`dashboard-terminal-status-${term.id}`} style={{
                      fontSize: theme.typography.fontSize.xs,
                      fontWeight: theme.typography.fontWeight.semibold,
                      color: statusColor(term.status),
                      textTransform: 'uppercase',
                      flexShrink: 0,
                      whiteSpace: 'nowrap',
                    }}>
                      {t(`dashboard.${term.status}`)}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Members near their credit limit (#385) — who the terminal is about
              to turn away, while there is still time to say so beforehand.
              Ordered by share of *their own* ceiling since ADR-0047, not by
              raw amount: a member €10 past a €50 ceiling is already being
              refused, while one at €170 of €200 is merely being warned. */}
          <div data-testid="dashboard-members-near-limit" style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.lg,
            padding: isMobile ? theme.spacing.md : theme.spacing.xl,
          }}>
            <h2 style={{
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
              margin: 0,
            }}>
              {t('dashboard.membersNearLimit')}
            </h2>
            <div style={{
              fontSize: theme.typography.fontSize.xs,
              color: theme.colors.text.muted,
              margin: `${theme.spacing.xs} 0 ${theme.spacing.lg} 0`,
            }}>
              {t('dashboard.creditLimitClubDefault', {
                amount: formatPrice(members_near_limit?.limit_cents ?? 0),
              })}
            </div>

            {near_limit_members.length === 0 ? (
              <div data-testid="dashboard-no-members-near-limit" style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.sm }}>
                {t('dashboard.noMembersNearLimit')}
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
                {near_limit_members.map((member) => (
                  <div key={member.id} data-testid={`dashboard-member-near-limit-${member.id}`} data-status={member.status} style={{
                    padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                    borderRadius: theme.borderRadius.sm,
                    background: theme.colors.bg.secondary,
                  }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: theme.spacing.sm }}>
                      <span style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.primary, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', minWidth: 0 }}>
                        {member.name}
                      </span>
                      <span data-testid={`dashboard-member-near-limit-balance-${member.id}`} style={{
                        fontSize: theme.typography.fontSize.sm,
                        fontWeight: theme.typography.fontWeight.semibold,
                        fontFamily: 'JetBrains Mono, monospace',
                        color: limitStatusColor(member.status),
                        flexShrink: 0,
                        whiteSpace: 'nowrap',
                      }}>
                        {t('dashboard.balanceOfLimit', {
                          balance: formatPrice(member.balance_cents),
                          limit: formatPrice(member.limit_cents),
                        })}
                      </span>
                    </div>
                    {/* The bar fills, it never overflows: a tab past the limit
                        is already said in words by the status line below. */}
                    <div style={{
                      height: '4px',
                      borderRadius: '2px',
                      background: theme.colors.border.dark,
                      margin: `${theme.spacing.xs} 0`,
                      overflow: 'hidden',
                    }}>
                      <div style={{
                        width: `${Math.min(100, Math.max(0, member.percent_of_limit))}%`,
                        height: '100%',
                        background: limitStatusColor(member.status),
                      }} />
                    </div>
                    <div data-testid={`dashboard-member-near-limit-status-${member.id}`} style={{
                      fontSize: theme.typography.fontSize.xs,
                      color: theme.colors.text.muted,
                    }}>
                      {member.status === 'exceeded'
                        ? t('dashboard.limitExceeded', { percent: member.percent_of_limit })
                        : t('dashboard.limitApproaching', { percent: member.percent_of_limit })}
                    </div>
                  </div>
                ))}
                {near_limit_hidden > 0 && (
                  <div data-testid="dashboard-members-near-limit-more" style={{
                    fontSize: theme.typography.fontSize.xs,
                    color: theme.colors.text.muted,
                  }}>
                    {t('dashboard.membersNearLimitMore', { count: near_limit_hidden })}
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Alerts */}
          <div data-testid="dashboard-alerts" style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.lg,
            padding: isMobile ? theme.spacing.md : theme.spacing.xl,
          }}>
            <h2 style={{
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
              margin: `0 0 ${theme.spacing.lg} 0`,
            }}>
              {t('dashboard.alerts')}
            </h2>

            {/* SEPA Issues Alert */}
            <div data-testid="dashboard-sepa-alert" style={{
              display: 'flex',
              alignItems: 'center',
              gap: theme.spacing.md,
              padding: theme.spacing.md,
              borderRadius: theme.borderRadius.sm,
              background: alerts.sepa_issues?.severity === 'none'
                ? theme.badges.success.bg
                : alerts.sepa_issues?.severity === 'warning'
                  ? 'rgba(249, 115, 22, 0.1)'
                  : theme.badges.danger.bg,
            }}>
              <div style={{
                width: '8px',
                height: '8px',
                borderRadius: '50%',
                background: severityColor(alerts.sepa_issues?.severity ?? 'none'),
                flexShrink: 0,
              }} />
              <span data-testid="dashboard-sepa-alert-message" style={{
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.text.primary,
              }}>
                {(alerts.sepa_issues?.count ?? 0) > 0
                  ? t('dashboard.membersNeedSepaData', { count: alerts.sepa_issues?.count })
                  : t('dashboard.allSepaValid')
                }
              </span>
            </div>
          </div>

          {/* System Status */}
          <div data-testid="dashboard-system-status" style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.lg,
            padding: isMobile ? theme.spacing.md : theme.spacing.xl,
          }}>
            <h2 style={{
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
              margin: `0 0 ${theme.spacing.lg} 0`,
            }}>
              {t('dashboard.systemStatus')}
            </h2>

            <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
              {[
                { label: t('dashboard.lastSettlement'), value: system_status.last_settlement_date ? formatRelativeDate(system_status.last_settlement_date) : '–', testId: 'last-settlement' },
                { label: t('dashboard.pendingSettlements'), value: system_status.pending_settlement_count, testId: 'pending-settlements' },
                { label: t('dashboard.totalMembers'), value: system_status.total_members, testId: 'total-members' },
                { label: t('dashboard.totalTransactions'), value: system_status.total_transactions, testId: 'total-transactions' },
                { label: t('dashboard.databaseHealth'), value: system_status.database_health, testId: 'database-health' },
              ].map(({ label, value, testId }) => (
                <div key={testId} data-testid={`dashboard-system-${testId}`} style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  padding: `${theme.spacing.xs} 0`,
                  borderBottom: `1px solid ${theme.colors.border.dark}`,
                }}>
                  <span style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.secondary, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', minWidth: 0 }}>{label}</span>
                  <span style={{
                    fontSize: theme.typography.fontSize.sm,
                    fontWeight: theme.typography.fontWeight.medium,
                    color: theme.colors.text.primary,
                    fontFamily: 'JetBrains Mono, monospace',
                    flexShrink: 0,
                    whiteSpace: 'nowrap',
                  }}>
                    {value}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
