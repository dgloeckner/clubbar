/**
 * CreateAdminModal Component
 * Modal for creating new admin users
 */

import { theme } from '../../styles/design-system'

export interface CreateAdminModalProps {
  isOpen: boolean
  formData: {
    email: string
    display_name: string
    locale: string
  }
  onFormChange: (field: string, value: string) => void
  onSubmit: () => void
  onClose: () => void
}

export function CreateAdminModal({ isOpen, formData, onFormChange, onSubmit, onClose }: CreateAdminModalProps) {
  if (!isOpen) {
    return null
  }

  return (
    <div
      data-testid="settings-admin-create-modal"
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0, 0, 0, 0.5)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 1000,
      }}
      onClick={onClose}
    >
      <div
        style={{
          background: theme.colors.bg.primary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '400px',
          width: '90%',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <h2 style={{ margin: 0, marginBottom: theme.spacing.lg }}>Create Admin User</h2>
        <input
          data-testid="settings-admin-create-email"
          type="email"
          placeholder="Email"
          value={formData.email}
          onChange={(e) => onFormChange('email', e.target.value)}
          style={{
            width: '100%',
            padding: theme.spacing.md,
            marginBottom: theme.spacing.md,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
            boxSizing: 'border-box',
            background: theme.colors.bg.secondary,
            color: theme.colors.text.primary,
          }}
        />
        <input
          data-testid="settings-admin-create-display-name"
          type="text"
          placeholder="Display Name"
          value={formData.display_name}
          onChange={(e) => onFormChange('display_name', e.target.value)}
          style={{
            width: '100%',
            padding: theme.spacing.md,
            marginBottom: theme.spacing.md,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
            boxSizing: 'border-box',
            background: theme.colors.bg.secondary,
            color: theme.colors.text.primary,
          }}
        />
        <select
          data-testid="settings-admin-create-locale"
          value={formData.locale}
          onChange={(e) => onFormChange('locale', e.target.value)}
          style={{
            width: '100%',
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
            boxSizing: 'border-box',
            background: theme.colors.bg.secondary,
            color: theme.colors.text.primary,
          }}
        >
          <option value="de">German (de)</option>
          <option value="en">English (en)</option>
        </select>
        <div style={{ display: 'flex', gap: theme.spacing.md }}>
          <button
            data-testid="settings-admin-create-confirm-button"
            onClick={onSubmit}
            style={{
              flex: 1,
              padding: theme.spacing.md,
              background: theme.colors.semantic.primary,
              color: 'white',
              border: 'none',
              borderRadius: theme.borderRadius.md,
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
            Create
          </button>
          <button
            data-testid="settings-admin-create-cancel-button"
            onClick={onClose}
            style={{
              flex: 1,
              padding: theme.spacing.md,
              background: 'transparent',
              color: theme.colors.text.secondary,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              cursor: 'pointer',
              transition: `all ${theme.transitions.default}`,
            }}
            onMouseEnter={(e) => {
              e.currentTarget.style.backgroundColor = theme.colors.bg.tertiary
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.backgroundColor = 'transparent'
            }}
          >
            Cancel
          </button>
        </div>
      </div>
    </div>
  )
}
