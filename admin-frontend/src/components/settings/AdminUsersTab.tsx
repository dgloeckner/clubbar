/**
 * AdminUsersTab Component
 * Admin users list with toggle for active/inactive status and action buttons
 */

import { theme, formatRelativeDate } from '../../styles/design-system'
import { Toggle } from '../common/Toggle'
import { Badge } from '../common/Badge'
import { Tooltip } from '../common/Tooltip'
import { AdminUser } from '../../types'
import { useBreakpoint } from '../../hooks/useBreakpoint'
import { useTranslation } from 'react-i18next'

export interface AdminUsersTabProps {
  users: AdminUser[]
  loading: boolean
  onCreateUser: () => void
  onEditUser: (admin: AdminUser) => void
  onResetPassword: (id: string) => void
  onDeactivateUser: (id: string) => void
  onReactivateUser: (id: string) => void
}


export function AdminUsersTab({
  users,
  loading,
  onCreateUser,
  onEditUser,
  onResetPassword,
  onDeactivateUser,
  onReactivateUser,
}: AdminUsersTabProps) {
  const { t } = useTranslation()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'mobile' || breakpoint === 'smallMobile'

  if (loading) {
    return (
      <div style={{ textAlign: 'center', padding: theme.spacing.xl }}>
        {t('settings.loadingAdminUsers')}
      </div>
    )
  }

  return (
    <div>
      {/* Section Header with Count Badge */}
      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          marginBottom: theme.spacing.lg,
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.md }}>
          <h2 style={{ margin: 0, fontSize: theme.typography.fontSize.lg }}>{t('settings.adminUsers')}</h2>
          <Badge label={`${users.length}`} variant="info" showDot={false} testId="settings-admin-users-count-badge" />
        </div>

        {/* Create User Button */}
        <button
          data-testid="settings-admin-create-button"
          onClick={onCreateUser}
          style={{
            padding: `${theme.spacing.md} ${theme.spacing.lg}`,
            background: theme.colors.semantic.primary,
            color: 'white',
            border: 'none',
            borderRadius: theme.borderRadius.md,
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.semibold,
            cursor: 'pointer',
            transition: `all ${theme.transitions.default}`,
          }}
          onMouseEnter={(e) => {
            e.currentTarget.style.background = 'rgb(37, 99, 235)'
          }}
          onMouseLeave={(e) => {
            e.currentTarget.style.background = theme.colors.semantic.primary
          }}
        >
          + {t('settings.createAdminUser')}
        </button>
      </div>

      {/* Admin Users List */}
      {users.length > 0 ? (
        isMobile ? (
          /* Mobile Card View */
          <div
            data-testid="settings-admin-users-mobile-cards"
            style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.md }}
          >
            {users.map((admin) => (
              <div
                key={admin.id}
                data-testid={`admin-user-card-${admin.id}`}
                style={{
                  background: 'rgba(255,255,255,0.03)',
                  border: '1px solid rgba(255,255,255,0.06)',
                  borderRadius: '10px',
                  padding: '14px 16px',
                  opacity: admin.is_active ? 1 : 0.5,
                  transition: `opacity ${theme.transitions.default}`,
                }}
              >
                {/* Row 1: Toggle + Name */}
                <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.md, marginBottom: theme.spacing.sm }}>
                  <Toggle
                    isEnabled={admin.is_active}
                    onChange={(enabled) => {
                      if (enabled) {
                        onReactivateUser(admin.id)
                      } else {
                        onDeactivateUser(admin.id)
                      }
                    }}
                    testId={`settings-admin-user-toggle-${admin.id}`}
                  />
                  <span
                    data-testid={`settings-admin-user-name-${admin.id}`}
                    style={{ fontWeight: theme.typography.fontWeight.semibold, fontSize: theme.typography.fontSize.sm }}
                  >
                    {admin.display_name}
                  </span>
                </div>

                {/* Row 2: Email */}
                <div
                  data-testid={`settings-admin-user-email-${admin.id}`}
                  style={{
                    fontSize: theme.typography.fontSize.xs,
                    color: theme.colors.text.secondary,
                    marginBottom: theme.spacing.sm,
                    paddingLeft: '44px',
                  }}
                >
                  {admin.email}
                </div>

                {/* Row 3: Last Login + Action Buttons */}
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', paddingLeft: '44px' }}>
                  <span style={{ fontSize: theme.typography.fontSize.xs, color: theme.colors.text.secondary }}>
                    {admin.last_login_at ? formatRelativeDate(admin.last_login_at) : t('dates.never')}
                  </span>
                  <div style={{ display: 'flex', gap: theme.spacing.sm }}>
                    {/* Edit Button */}
                    <button
                      data-testid={`settings-admin-edit-button-${admin.id}`}
                      onClick={() => onEditUser(admin)}
                      style={{
                        width: '32px',
                        height: '32px',
                        borderRadius: '8px',
                        border: 'none',
                        background: 'transparent',
                        color: theme.colors.text.secondary,
                        cursor: 'pointer',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        transition: `all ${theme.transitions.default}`,
                      }}
                    >
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                      </svg>
                    </button>

                    {/* Reset Password Button */}
                    <button
                      data-testid={`settings-admin-reset-password-button-${admin.id}`}
                      onClick={() => onResetPassword(admin.id)}
                      style={{
                        width: '32px',
                        height: '32px',
                        borderRadius: '8px',
                        border: 'none',
                        background: 'transparent',
                        color: theme.colors.text.secondary,
                        cursor: 'pointer',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        transition: `all ${theme.transitions.default}`,
                      }}
                    >
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4" />
                      </svg>
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        ) : (
          /* Desktop Table View */
          <div
            style={{
              overflowX: 'auto',
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
            }}
          >
            <table
              data-testid="settings-admin-users-table"
              style={{
                width: '100%',
                borderCollapse: 'collapse',
                fontSize: theme.typography.fontSize.sm,
              }}
            >
              <thead>
                <tr style={{ background: theme.colors.bg.tertiary }}>
                  <th
                    style={{
                      padding: theme.spacing.md,
                      textAlign: 'left',
                      borderBottom: `1px solid ${theme.colors.border.light}`,
                      fontWeight: theme.typography.fontWeight.semibold,
                    }}
                  >
                    {t('settings.user')}
                  </th>
                  <th
                    style={{
                      padding: theme.spacing.md,
                      textAlign: 'left',
                      borderBottom: `1px solid ${theme.colors.border.light}`,
                      fontWeight: theme.typography.fontWeight.semibold,
                    }}
                  >
                    {t('auth.email')}
                  </th>
                  <th
                    style={{
                      padding: theme.spacing.md,
                      textAlign: 'left',
                      borderBottom: `1px solid ${theme.colors.border.light}`,
                      fontWeight: theme.typography.fontWeight.semibold,
                    }}
                  >
                    {t('profile.lastLogin')}
                  </th>
                  <th
                    style={{
                      padding: theme.spacing.md,
                      textAlign: 'center',
                      borderBottom: `1px solid ${theme.colors.border.light}`,
                      fontWeight: theme.typography.fontWeight.semibold,
                    }}
                  >
                    {t('common.actions')}
                  </th>
                </tr>
              </thead>
              <tbody>
                {users.map((admin) => (
                  <tr
                    key={admin.id}
                    data-testid={`settings-admin-user-row-${admin.id}`}
                    style={{
                      borderBottom: `1px solid ${theme.colors.border.light}`,
                      opacity: admin.is_active ? 1 : 0.5,
                      transition: `opacity ${theme.transitions.default}`,
                    }}
                  >
                    {/* User Toggle + Name */}
                    <td
                      style={{
                        padding: theme.spacing.md,
                        display: 'flex',
                        alignItems: 'center',
                        gap: theme.spacing.md,
                      }}
                    >
                      <Toggle
                        isEnabled={admin.is_active}
                        onChange={(enabled) => {
                          if (enabled) {
                            onReactivateUser(admin.id)
                          } else {
                            onDeactivateUser(admin.id)
                          }
                        }}
                        testId={`settings-admin-user-toggle-${admin.id}`}
                      />
                      <span data-testid={`settings-admin-user-name-${admin.id}`}>{admin.display_name}</span>
                    </td>

                    {/* Email */}
                    <td style={{ padding: theme.spacing.md }} data-testid={`settings-admin-user-email-${admin.id}`}>
                      {admin.email}
                    </td>

                    {/* Last Login (Relative Date) */}
                    <td
                      style={{
                        padding: theme.spacing.md,
                        color: theme.colors.text.secondary,
                        fontSize: theme.typography.fontSize.xs,
                      }}
                    >
                      {admin.last_login_at ? formatRelativeDate(admin.last_login_at) : t('dates.never')}
                    </td>

                    {/* Actions */}
                    <td style={{ padding: theme.spacing.md, textAlign: 'center' }}>
                      <div style={{ display: 'flex', gap: theme.spacing.sm, justifyContent: 'center', alignItems: 'center' }}>
                        {/* Edit Button */}
                        <Tooltip content={t('common.edit')} position="top">
                          <button
                            data-testid={`settings-admin-edit-button-${admin.id}`}
                            onClick={() => onEditUser(admin)}
                            style={{
                              width: '32px',
                              height: '32px',
                              borderRadius: '8px',
                              border: 'none',
                              background: 'transparent',
                              color: theme.colors.text.secondary,
                              cursor: 'pointer',
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                              transition: `all ${theme.transitions.default}`,
                            }}
                            onMouseEnter={(e) => {
                              e.currentTarget.style.background = 'rgba(59, 130, 246, 0.2)'
                              e.currentTarget.style.color = theme.colors.semantic.primary
                            }}
                            onMouseLeave={(e) => {
                              e.currentTarget.style.background = 'transparent'
                              e.currentTarget.style.color = theme.colors.text.secondary
                            }}
                          >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                            </svg>
                          </button>
                        </Tooltip>

                        {/* Reset Password Button */}
                        <Tooltip content={t('settings.resetPassword')} position="top">
                          <button
                            data-testid={`settings-admin-reset-password-button-${admin.id}`}
                            onClick={() => onResetPassword(admin.id)}
                            style={{
                              width: '32px',
                              height: '32px',
                              borderRadius: '8px',
                              border: 'none',
                              background: 'transparent',
                              color: theme.colors.text.secondary,
                              cursor: 'pointer',
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                              transition: `all ${theme.transitions.default}`,
                            }}
                            onMouseEnter={(e) => {
                              e.currentTarget.style.background = 'rgba(249, 115, 22, 0.2)'
                              e.currentTarget.style.color = 'rgb(249, 115, 22)'
                            }}
                            onMouseLeave={(e) => {
                              e.currentTarget.style.background = 'transparent'
                              e.currentTarget.style.color = theme.colors.text.secondary
                            }}
                          >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                              <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4" />
                            </svg>
                          </button>
                        </Tooltip>

                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      ) : (
        <div style={{ textAlign: 'center', padding: theme.spacing.xl, color: theme.colors.text.secondary }}>
          {t('settings.noAdminUsers')}
        </div>
      )}
    </div>
  )
}
