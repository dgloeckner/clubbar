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
 *
 * ### Who it renders for, and who is not asked (#677)
 *
 * `SCHEDULER_BANNER_ROLES` decides, and it decides *before the fetch* — a
 * session outside it makes no request at all. Until #677 this component asked
 * unconditionally against an `admin`-only route, which meant the two lesser
 * offices fired a guaranteed 403 on every page they opened, and the one office
 * the warning exists for could not load it: every settlement route is the
 * Kassenwart's, so the Kassenwart is exactly who the gate refuses.
 *
 * The Kassenwart now reads it. Their response is the redacted half — the
 * `verified` flag and the recommended interval, no `setup.cli_command` —
 * because the command names the server's document root and scheduling it is
 * not their job. So the banner has two bodies: the operator's, which is the
 * command to paste, and the office's, which says what is missing and that
 * whoever holds the server has to add it. Same warning, addressed to the
 * person who can act on it.
 *
 * The Getränkewart is left out deliberately: the gate blocks nothing they can
 * do, so the banner would be a warning about a refusal they will never meet.
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { getScheduler } from '../../api/generated/scheduler/scheduler'
import type { SchedulerStatus } from '../../api/generated'
import { useAuth } from '../../context/AuthContext'
import { useLatestRequest } from '../../hooks/useLatestRequest'
import { holdsAnyRole, SCHEDULER_BANNER_ROLES } from '../../utils/adminRoles'
import { theme } from '../../styles/design-system'

export function SchedulerBanner() {
  const { t } = useTranslation()
  const { roles } = useAuth()
  const [status, setStatus] = useState<SchedulerStatus | null>(null)
  const request = useLatestRequest()

  const mayRead = holdsAnyRole(roles, SCHEDULER_BANNER_ROLES)

  useEffect(() => {
    if (!mayRead) {
      // Not "fetch and hide the result": an office outside the grant would get
      // a 403 whatever we did with it, and a request whose only possible
      // outcome is a refusal does not belong on every page load.
      setStatus(null)
      return
    }

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
  }, [mayRead])

  // Nothing while unknown, and nothing once verified — which is the state
  // every healthy installation is in forever after its first cron tick.
  if (!status || status.verified !== false) {
    return null
  }

  const badge = theme.badges.warning
  const minutes = status.setup?.recommended_interval_minutes ?? 15
  // The command is what tells the two bodies apart. Its absence is the
  // server's answer to "who is asking", not a field that failed to load, so
  // it is also the honest signal that this reader cannot run the fix.
  const command = status.setup?.cli_command

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
      <div data-testid="scheduler-banner-body">
        {command ? t('scheduler.banner.body', { minutes }) : t('scheduler.banner.bodyForOffice', { minutes })}
      </div>
      {command && (
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
          {command}
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
