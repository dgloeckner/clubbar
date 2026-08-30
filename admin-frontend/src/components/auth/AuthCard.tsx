/**
 * The card every pre-login screen is drawn on: logo, title, subtitle, and an
 * optional ⓘ button beside the subtitle.
 *
 * Extracted from `LoginPage` when the invitation accept page (migration 058)
 * became the fourth screen to need it — login, MFA, Authenticator enrolment,
 * and now setting a first password. A copy would have drifted on the first
 * change to the logo or the spacing, and these four are precisely the screens
 * that must look like one another: they are what somebody sees before they
 * have any reason to trust the page.
 */

import { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Card } from '../common/Card'
import { theme } from '../../styles/design-system'

export function AuthCard({
  title,
  subtitle,
  onInfo,
  children,
}: {
  title: string
  subtitle: string
  onInfo?: () => void
  children: ReactNode
}) {
  const { t } = useTranslation()
  return (
    <div
      style={{
        display: 'flex',
        justifyContent: 'center',
        alignItems: 'center',
        minHeight: '100vh',
        background: theme.colors.bg.primary,
        padding: theme.spacing.lg,
      }}
    >
      <Card style={{ width: '100%', maxWidth: '400px' }}>
        <div style={{ textAlign: 'center', marginBottom: theme.spacing['2xl'] }}>
          <div style={{ display: 'flex', justifyContent: 'center', marginBottom: theme.spacing.md }}>
            <img src="/logo.svg" alt="Club Bar Logo" style={{ width: '120px', height: '120px' }} />
          </div>
          <h1
            style={{
              fontSize: theme.typography.fontSize['2xl'],
              fontWeight: theme.typography.fontWeight.bold,
              margin: 0,
              marginBottom: theme.spacing.sm,
              color: theme.colors.text.primary,
            }}
          >
            {title}
          </h1>
          <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'center', gap: theme.spacing.xs }}>
            <p style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.secondary, margin: 0 }}>
              {subtitle}
            </p>
            {onInfo && (
              <button
                type="button"
                onClick={onInfo}
                aria-label={t('auth.setupInfoAriaLabel')}
                style={{
                  flexShrink: 0,
                  marginTop: '1px',
                  background: 'none',
                  border: 'none',
                  cursor: 'pointer',
                  padding: '0 2px',
                  color: theme.colors.semantic.info,
                  fontSize: theme.typography.fontSize.base,
                  lineHeight: 1,
                  opacity: 0.8,
                }}
                onMouseEnter={(e) => { (e.currentTarget as HTMLButtonElement).style.opacity = '1' }}
                onMouseLeave={(e) => { (e.currentTarget as HTMLButtonElement).style.opacity = '0.8' }}
              >
                ⓘ
              </button>
            )}
          </div>
        </div>
        {children}
      </Card>
    </div>
  )
}
