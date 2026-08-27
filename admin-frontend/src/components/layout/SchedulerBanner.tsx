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
 *
 * ### Two jobs, one box (#693)
 *
 * An installation needs two scheduled jobs and they are not equals. The drain
 * is a prerequisite — the API refuses a finalize without it. The nightly backup
 * blocks nothing, and that is precisely why it needs saying here: nothing else
 * will ever remind a club about it, and the epic's risk table names "the backup
 * cron is never added" as the thing most likely to go wrong. The installer
 * prints both commands side by side and then tells the operator this panel
 * "shows the same instructions until a run has been seen"; until now that was
 * true of one job.
 *
 * So each missing job gets its own notice, with its own heading, wording and
 * command — the backup one does not borrow the drain's "collections are
 * blocked", because nothing is. They share one container rather than stacking
 * two coloured bars: a fresh installation has neither job scheduled, which is
 * the common case in the hour after an install.
 *
 * The backup half is `admin`-only and simply absent for any other office, so
 * the second notice cannot render for a Kassenwart — no role check of its own
 * is needed here, and adding one would put the rule in a second place.
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

  const drainMissing = status?.verified === false
  // `configured` false means backups are deliberately off, which is a
  // legitimate state (ADR-0049 decision 2) and not something to nag about on
  // every page. An absent `backup` key means this reader is not the operator,
  // or the caller assembled no backup half at all.
  const backupMissing = status?.backup?.configured === true && status.backup.verified === false

  // Nothing while unknown, and nothing once both jobs have been seen — which is
  // the state every healthy installation is in forever after its first ticks.
  if (!status || (!drainMissing && !backupMissing)) {
    return null
  }

  const badge = theme.badges.warning
  const minutes = status.setup?.recommended_interval_minutes ?? 15
  // The command is what tells the two bodies apart. Its absence is the
  // server's answer to "who is asking", not a field that failed to load, so
  // it is also the honest signal that this reader cannot run the fix.
  const command = status.setup?.cli_command
  const heading = {
    fontWeight: theme.typography.fontWeight.semibold,
    marginBottom: theme.spacing.xs,
  }
  const commandStyle = {
    display: 'block' as const,
    marginTop: theme.spacing.sm,
    padding: `${theme.spacing.xs} ${theme.spacing.sm}`,
    background: theme.colors.bg.secondary,
    borderRadius: theme.borderRadius.sm,
    // The point of printing it is that it can be selected and pasted
    // whole; a long document-root path must wrap rather than hide.
    wordBreak: 'break-all' as const,
  }

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
      {drainMissing && (
        <div data-testid="scheduler-banner-drain">
          <div style={heading}>{t('scheduler.banner.title')}</div>
          <div data-testid="scheduler-banner-body">
            {command ? t('scheduler.banner.body', { minutes }) : t('scheduler.banner.bodyForOffice', { minutes })}
          </div>
          {command && (
            <code data-testid="scheduler-banner-command" style={commandStyle}>
              {command}
            </code>
          )}
          {status.setup?.drain_url && (
            <div style={{ marginTop: theme.spacing.xs }}>
              {t('scheduler.banner.urlFallback', { url: status.setup.drain_url })}
            </div>
          )}
        </div>
      )}
      {backupMissing && (
        <div
          data-testid="scheduler-banner-backup"
          style={
            // Separated only when there is something to separate it from. Two
            // notices run together read as one long paragraph about the drain.
            drainMissing
              ? {
                  marginTop: theme.spacing.md,
                  paddingTop: theme.spacing.md,
                  borderTop: `1px solid ${badge.dot}`,
                }
              : undefined
          }
        >
          <div style={heading}>{t('scheduler.banner.backupTitle')}</div>
          <div data-testid="scheduler-banner-backup-body">{t('scheduler.banner.backupBody')}</div>
          {status.backup?.cli_command && (
            <code data-testid="scheduler-banner-backup-command" style={commandStyle}>
              {status.backup.cli_command}
            </code>
          )}
          {status.backup?.trigger_url && (
            <div style={{ marginTop: theme.spacing.xs }}>
              {t('scheduler.banner.backupUrlFallback', { url: status.backup.trigger_url })}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
