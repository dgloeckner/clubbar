/**
 * The named refusal screen (ADR-0044, #516).
 *
 * Hiding navigation is not enforcement, so a legitimate user reaches a page
 * their roles do not cover through entirely ordinary means: a bookmark from
 * when they held more, a tab left open while somebody changed their roles, a
 * link a colleague pasted into the group chat. All three are a *refusal*, not
 * a fault — the panel says which office the page belongs to and offers the way
 * back, rather than showing an error that reads like something broke.
 *
 * It deliberately does not list what the caller may do instead: the navigation
 * beside it already does, and enumerating grants on a refusal screen is how a
 * refusal turns into a map of the system.
 */

import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { theme } from '../styles/design-system'
import { useAuth } from '../context/AuthContext'
import { landingPath } from '../utils/adminRoles'

export function InsufficientRolePage() {
  const { t } = useTranslation()
  const { roles } = useAuth()
  const home = landingPath(roles)

  return (
    <div
      data-testid="insufficient-role-page"
      style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        gap: theme.spacing.md,
        textAlign: 'center',
        padding: `${theme.spacing['2xl']} ${theme.spacing.lg}`,
      }}
    >
      <h1
        data-testid="insufficient-role-title"
        style={{
          margin: 0,
          fontSize: theme.typography.fontSize['3xl'],
          fontWeight: theme.typography.fontWeight.bold,
          color: theme.colors.text.primary,
          letterSpacing: '-0.02em',
        }}
      >
        {t('errors.insufficientRoleTitle')}
      </h1>

      <p
        data-testid="insufficient-role-message"
        style={{
          margin: 0,
          maxWidth: '60ch',
          fontSize: theme.typography.fontSize.base,
          color: theme.colors.text.secondary,
        }}
      >
        {t('errors.insufficientRoleMessage')}
      </p>

      {/*
        Only offered when the caller can actually open it. An account holding no
        role has nowhere to go, and a button that lands on this same screen is
        worse than none.
      */}
      {roles.length > 0 && (
        <Link
          to={home}
          data-testid="insufficient-role-home-link"
          style={{
            padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
            borderRadius: theme.borderRadius.md,
            background: theme.activeTint.primary,
            border: `1px solid rgba(59, 130, 246, 0.3)`,
            color: theme.colors.semantic.primary,
            textDecoration: 'none',
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.medium,
          }}
        >
          {t('errors.insufficientRoleHome')}
        </Link>
      )}
    </div>
  )
}
