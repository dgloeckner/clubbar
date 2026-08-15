/**
 * The scheduler is a prerequisite, not a suggestion (#405).
 *
 * The mail drain is the only thing that sends announcement emails, and until a
 * scheduled run has been observed, finalizing a direct debit is refused by the
 * API. A refusal at the moment the treasurer presses the button is a bad place
 * to learn that — so the same instructions ride along on every page from first
 * login until the first run lands.
 *
 * Read once per mount and never polled: the answer changes at most once in the
 * lifetime of an installation, and a banner that re-fetches on an interval
 * would put a request on every page for a state that is permanent afterwards.
 * A navigation remounts the layout, which is refresh enough.
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { getScheduler } from '../../api/generated/scheduler/scheduler'
import type { SchedulerStatus } from '../../api/generated'
import { useLatestRequest } from '../../hooks/useLatestRequest'
import { theme } from '../../styles/design-system'

export function SchedulerBanner() {
  const { t } = useTranslation()
  const [status, setStatus] = useState<SchedulerStatus | null>(null)
  const request = useLatestRequest()

  useEffect(() => {
    const signal = request.next()
    getScheduler()
      .getSchedulerStatus({ signal })
      .then((result) => {
        if (signal.aborted) return
        setStatus(result)
      })
      .catch(() => {
        // Silent. This banner reports a configuration gap; it must never
        // become one itself by showing an error of its own on every page.
      })
    return () => request.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Nothing while unknown, and nothing once verified — which is the state
  // every healthy installation is in forever after its first cron tick.
  if (!status || status.verified !== false) {
    return null
  }

  const badge = theme.badges.warning

  return (
    <div
      data-testid="scheduler-banner"
      style={{
        background: badge.bg,
        borderBottom: `1px solid ${badge.dot}`,
        color: badge.text,
        padding: `${theme.spacing.md} ${theme.spacing.lg}`,
        fontSize: theme.typography.fontSize.sm,
      }}
    >
      <div style={{ fontWeight: theme.typography.fontWeight.semibold, marginBottom: theme.spacing.xs }}>
        {t('scheduler.banner.title')}
      </div>
      <div>{t('scheduler.banner.body', { minutes: status.setup?.recommended_interval_minutes ?? 15 })}</div>
      {status.setup?.cli_command && (
        <code
          data-testid="scheduler-banner-command"
          style={{
            display: 'block',
            marginTop: theme.spacing.sm,
            padding: `${theme.spacing.xs} ${theme.spacing.sm}`,
            background: theme.colors.bg.secondary,
            borderRadius: theme.borderRadius.sm,
            // The point of printing it is that it can be selected and pasted
            // whole; a long document-root path must wrap rather than hide.
            wordBreak: 'break-all',
          }}
        >
          {status.setup.cli_command}
        </code>
      )}
      {status.setup?.drain_url && (
        <div style={{ marginTop: theme.spacing.xs }}>
          {t('scheduler.banner.urlFallback', { url: status.setup.drain_url })}
        </div>
      )}
    </div>
  )
}
