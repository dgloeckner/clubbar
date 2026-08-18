/**
 * AdminUsersTab Component
 * Admin users list with toggle for active/inactive status and action buttons
 */

import { theme } from '../../styles/design-system'
import { Toggle } from '../common/Toggle'
import { Badge, type BadgeProps } from '../common/Badge'
import { Tooltip } from '../common/Tooltip'
import type { AdminUser as GeneratedAdminUser } from '../../api/generated'
import type { AdminRole } from '../../api/generated/adminRole'

// Required fields that are always present in the API response
type AdminUser = GeneratedAdminUser & { id: string; email: string; display_name: string; locale: string; is_active: boolean; created_at: string }
import { useBreakpoint } from '../../hooks/useBreakpoint'
import { useTranslation } from 'react-i18next'
import { useFormatters } from '../../hooks/useFormatters'

// Role names shown verbatim in both locales (CONTEXT.md's precedent for
// Storno/Deckel/Vorabankündigung) — a distinct color per role so the roster
// can be scanned for "who is admin" without reading every row.
const ROLE_BADGE_LABEL: Record<AdminRole, string> = {
  admin: 'Admin',
  kassenwart: 'Kassenwart',
  getraenkewart: 'Getränkewart',
}
const ROLE_BADGE_VARIANT: Record<AdminRole, BadgeProps['variant']> = {
  admin: 'danger',
  kassenwart: 'info',
  getraenkewart: 'success',
}

export interface AdminUsersTabProps {
  users: AdminUser[]
  loading: boolean
  /**
   * Id of the signed-in admin, so their own row can be marked and its
   * active-toggle locked (#382). Null until the profile has loaded — the row
   * is treated as somebody else's until we know otherwise, which is safe:
   * the backend rejects self-deactivation with a 409 regardless.
   */
  currentAdminId?: string | null
  onCreateUser: () => void
  onEditUser: (admin: AdminUser) => void
  onResetPassword: (id: string) => void
  onReset2fa: (id: string) => void
  onDeactivateUser: (id: string) => void
  onReactivateUser: (id: string) => void
}


export function AdminUsersTab({
  users,
  loading,
  currentAdminId = null,
  onCreateUser,
  onEditUser,
  onResetPassword,
  onReset2fa,
  onDeactivateUser,
  onReactivateUser,
}: AdminUsersTabProps) {
  const { t } = useTranslation()
  const { formatRelativeDate } = useFormatters()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'mobile' || breakpoint === 'smallMobile'

  /**
   * Deactivating your own account signs you out on the very next request and
   * leaves no way back in — UC-A61 forbids it and the backend enforces it with
   * a 409. Offering the switch anyway turned that rule into an error banner
   * after the fact; here it is simply not offered (#382).
   */
  const isSelf = (adminId: string) => currentAdminId !== null && adminId === currentAdminId

  const renderActiveToggle = (admin: AdminUser) => {
    const self = isSelf(admin.id)

    return (
      <Toggle
        isEnabled={admin.is_active}
        disabled={self}
        onChange={(enabled) => {
          if (self) return
          if (enabled) {
            onReactivateUser(admin.id)
          } else {
            onDeactivateUser(admin.id)
          }
        }}
        testId={`settings-admin-user-toggle-${admin.id}`}
      />
    )
  }

  /**
   * Marks the caller's own row and carries the reason its switch is locked.
   * The tooltip hangs off the badge rather than off the switch: a disabled
   * button dispatches no mouse events, so a tooltip wrapped around one never
   * opens.
   */
  const renderRoleBadges = (admin: AdminUser) => (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: theme.spacing.xs }}>
      {(admin.roles ?? []).map((role) => (
        <Badge
          key={role}
          label={ROLE_BADGE_LABEL[role]}
          variant={ROLE_BADGE_VARIANT[role]}
          showDot={false}
          testId={`settings-admin-user-role-badge-${admin.id}-${role}`}
        />
      ))}
    </div>
  )

  const renderSelfBadge = (admin: AdminUser) =>
    isSelf(admin.id) ? (
      <Tooltip content={t('settings.cannotDeactivateOwnAccount')} position="top">
        <Badge
          label={t('settings.ownAccount')}
          variant="info"
          showDot={false}
          testId={`settings-admin-user-self-badge-${admin.id}`}
        />
      </Tooltip>
    ) : null

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
                  background: theme.mobileCard.bg,
                  border: `1px solid ${theme.mobileCard.border}`,
                  borderRadius: '10px',
                  padding: '14px 16px',
                  opacity: admin.is_active ? 1 : 0.5,
                  transition: `opacity ${theme.transitions.default}`,
                }}
              >
                {/* Row 1: Toggle + Name. minWidth: 0 lets a long, unbroken
                    display name shrink and ellipsize instead of pushing the
                    "Sie" badge off the card — flex items default to
                    min-width: auto, which refuses to shrink text content. */}
                <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.md, marginBottom: theme.spacing.sm }}>
                  {renderActiveToggle(admin)}
                  <span
                    data-testid={`settings-admin-user-name-${admin.id}`}
                    style={{
                      minWidth: 0,
                      flex: 1,
                      overflow: 'hidden',
                      textOverflow: 'ellipsis',
                      whiteSpace: 'nowrap',
                      fontWeight: theme.typography.fontWeight.semibold,
                      fontSize: theme.typography.fontSize.sm,
                    }}
                  >
                    {admin.display_name}
                  </span>
                  {renderSelfBadge(admin)}
                </div>

                {/* Row 2: Email. wordBreak so a long, unbroken address wraps
                    instead of bleeding past the card edge. */}
                <div
                  data-testid={`settings-admin-user-email-${admin.id}`}
                  style={{
                    fontSize: theme.typography.fontSize.xs,
                    color: theme.colors.text.secondary,
                    marginBottom: theme.spacing.sm,
                    paddingLeft: '44px',
                    wordBreak: 'break-word',
                  }}
                >
                  {admin.email}
                </div>

                {/* Row 2b: Role badges */}
                <div style={{ paddingLeft: '44px', marginBottom: theme.spacing.sm }}>
                  {renderRoleBadges(admin)}
                </div>

                {/* Row 3: Last Login + Action Buttons */}
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', paddingLeft: '44px' }}>
                  <span style={{ fontSize: theme.typography.fontSize.xs, color: theme.colors.text.secondary }}>
                    {formatRelativeDate(admin.last_login_at ?? '')}
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

                    {/* Reset 2FA Button */}
                    <button
                      data-testid={`settings-admin-reset-2fa-button-${admin.id}`}
                      onClick={() => onReset2fa(admin.id)}
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
                        <rect x="5" y="11" width="14" height="10" rx="2" ry="2" />
                        <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                        <line x1="12" y1="15" x2="12" y2="17" />
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
                    Role
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
                      {renderActiveToggle(admin)}
                      <span data-testid={`settings-admin-user-name-${admin.id}`}>{admin.display_name}</span>
                      {renderSelfBadge(admin)}
                    </td>

                    {/* Email */}
                    <td style={{ padding: theme.spacing.md }} data-testid={`settings-admin-user-email-${admin.id}`}>
                      {admin.email}
                    </td>

                    {/* Role badges */}
                    <td style={{ padding: theme.spacing.md }} data-testid={`settings-admin-user-roles-${admin.id}`}>
                      {renderRoleBadges(admin)}
                    </td>

                    {/* Last Login (Relative Date) */}
                    <td
                      style={{
                        padding: theme.spacing.md,
                        color: theme.colors.text.secondary,
                        fontSize: theme.typography.fontSize.xs,
                      }}
                    >
                      {formatRelativeDate(admin.last_login_at ?? '')}
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
                              e.currentTarget.style.background = theme.activeTint.primaryStrong
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

                        {/* Reset 2FA Button */}
                        <Tooltip content={t('settings.reset2fa')} position="top">
                          <button
                            data-testid={`settings-admin-reset-2fa-button-${admin.id}`}
                            onClick={() => onReset2fa(admin.id)}
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
                              e.currentTarget.style.background = 'rgba(139, 92, 246, 0.2)'
                              e.currentTarget.style.color = 'rgb(139, 92, 246)'
                            }}
                            onMouseLeave={(e) => {
                              e.currentTarget.style.background = 'transparent'
                              e.currentTarget.style.color = theme.colors.text.secondary
                            }}
                          >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                              <rect x="5" y="11" width="14" height="10" rx="2" ry="2" />
                              <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                              <line x1="12" y1="15" x2="12" y2="17" />
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
