/**
 * AdminUsersTab Component
 * Admin users list with gradient avatars, status badges, action buttons
 */

import { theme, formatRelativeDate } from '../../styles/design-system'
import { Avatar } from '../common/Avatar'
import { Badge } from '../common/Badge'
import { Tooltip } from '../common/Tooltip'
import { ActionMenu } from '../common/ActionMenu'
import { AdminUser } from '../../types'

export interface AdminUsersTabProps {
  users: AdminUser[]
  loading: boolean
  onCreateUser: () => void
  onEditUser: (admin: AdminUser) => void
  onResetPassword: (id: string) => void
  onDeactivateUser: (id: string) => void
  onReactivateUser: (id: string) => void
}

// Function to determine avatar color variant based on admin index
function getAvatarVariant(index: number): 'blue' | 'green' | 'orange' | 'pink' | 'gray' {
  const variants: ('blue' | 'green' | 'orange' | 'pink' | 'gray')[] = ['blue', 'green', 'orange', 'pink', 'gray']
  return variants[index % variants.length]
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
  if (loading) {
    return (
      <div style={{ textAlign: 'center', padding: theme.spacing.xl }}>
        Loading admin users...
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
          <h2 style={{ margin: 0, fontSize: theme.typography.fontSize.lg }}>Admin Users</h2>
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
          + Create Admin User
        </button>
      </div>

      {/* Admin Users Table */}
      {users.length > 0 ? (
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
                  User
                </th>
                <th
                  style={{
                    padding: theme.spacing.md,
                    textAlign: 'left',
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  Email
                </th>
                <th
                  style={{
                    padding: theme.spacing.md,
                    textAlign: 'left',
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  Status
                </th>
                <th
                  style={{
                    padding: theme.spacing.md,
                    textAlign: 'left',
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  Last Login
                </th>
                <th
                  style={{
                    padding: theme.spacing.md,
                    textAlign: 'center',
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              {users.map((admin, index) => (
                <tr
                  key={admin.id}
                  data-testid={`settings-admin-user-row-${admin.id}`}
                  style={{
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    opacity: admin.is_active ? 1 : 0.5,
                    transition: `opacity ${theme.transitions.default}`,
                  }}
                >
                  {/* User Avatar + Name */}
                  <td
                    style={{
                      padding: theme.spacing.md,
                      display: 'flex',
                      alignItems: 'center',
                      gap: theme.spacing.md,
                    }}
                  >
                    <Avatar
                      name={admin.display_name}
                      variant={getAvatarVariant(index)}
                      size="sm"
                      inactive={!admin.is_active}
                      testId={`settings-admin-user-avatar-${admin.id}`}
                    />
                    <span data-testid={`settings-admin-user-name-${admin.id}`}>{admin.display_name}</span>
                  </td>

                  {/* Email */}
                  <td style={{ padding: theme.spacing.md }} data-testid={`settings-admin-user-email-${admin.id}`}>
                    {admin.email}
                  </td>

                  {/* Status Badge */}
                  <td style={{ padding: theme.spacing.md }} data-testid={`settings-admin-user-status-${admin.id}`}>
                    <Badge
                      label={admin.is_active ? 'Active' : 'Inactive'}
                      variant={admin.is_active ? 'success' : 'neutral'}
                      showDot={true}
                      testId={`settings-admin-user-badge-${admin.id}`}
                    />
                  </td>

                  {/* Last Login (Relative Date) */}
                  <td
                    style={{
                      padding: theme.spacing.md,
                      color: theme.colors.text.secondary,
                      fontSize: theme.typography.fontSize.xs,
                    }}
                  >
                    {admin.last_login_at ? formatRelativeDate(admin.last_login_at) : 'Never'}
                  </td>

                  {/* Actions */}
                  <td style={{ padding: theme.spacing.md, textAlign: 'center' }}>
                    <div style={{ display: 'flex', gap: theme.spacing.sm, justifyContent: 'center', alignItems: 'center' }}>
                      {/* Edit Button */}
                      <Tooltip content="Edit admin user" position="top">
                        <button
                          data-testid={`settings-admin-edit-button-${admin.id}`}
                          onClick={() => onEditUser(admin)}
                          style={{
                            padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                            background: 'transparent',
                            color: theme.colors.semantic.primary,
                            border: `1px solid ${theme.colors.semantic.primary}`,
                            borderRadius: theme.borderRadius.sm,
                            fontSize: theme.typography.fontSize.xs,
                            cursor: 'pointer',
                            transition: `all ${theme.transitions.default}`,
                          }}
                          onMouseEnter={(e) => {
                            e.currentTarget.style.background = 'rgba(59, 130, 246, 0.1)'
                          }}
                          onMouseLeave={(e) => {
                            e.currentTarget.style.background = 'transparent'
                          }}
                        >
                          Edit
                        </button>
                      </Tooltip>

                      {/* Reset Password Button */}
                      <Tooltip content="Reset password" position="top">
                        <button
                          data-testid={`settings-admin-reset-password-button-${admin.id}`}
                          onClick={() => onResetPassword(admin.id)}
                          style={{
                            padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                            background: 'rgba(251, 146, 60, 0.1)',
                            color: 'rgb(234, 88, 12)',
                            border: '1px solid rgba(251, 146, 60, 0.5)',
                            borderRadius: theme.borderRadius.sm,
                            fontSize: theme.typography.fontSize.xs,
                            cursor: 'pointer',
                            transition: `all ${theme.transitions.default}`,
                          }}
                          onMouseEnter={(e) => {
                            e.currentTarget.style.background = 'rgba(251, 146, 60, 0.2)'
                          }}
                          onMouseLeave={(e) => {
                            e.currentTarget.style.background = 'rgba(251, 146, 60, 0.1)'
                          }}
                        >
                          Reset PWD
                        </button>
                      </Tooltip>

                      {/* Action Menu (3-dot) */}
                      <ActionMenu
                        items={
                          admin.is_active
                            ? [
                                {
                                  label: 'Deactivate',
                                  onClick: () => onDeactivateUser(admin.id),
                                  variant: 'danger',
                                },
                              ]
                            : [
                                {
                                  label: 'Reactivate',
                                  onClick: () => onReactivateUser(admin.id),
                                  variant: 'default',
                                },
                              ]
                        }
                        testId={`settings-admin-action-menu-${admin.id}`}
                      />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div style={{ textAlign: 'center', padding: theme.spacing.xl, color: theme.colors.text.secondary }}>
          No admin users found
        </div>
      )}
    </div>
  )
}
