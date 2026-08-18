/**
 * Role Selector Component
 *
 * Checkbox group for an admin account's role set (ADR-0044). `admin` and the
 * two lesser roles (`kassenwart`, `getraenkewart`) are mutually exclusive —
 * checking one clears the other side, via `toggleRole`, so no click sequence
 * can reach an invalid combination. The backend enforces the same rule
 * independently (`AdminUsersService::applyRoles`); this is the UI half.
 *
 * Role names are shown verbatim in both locales — `Kassenwart` and
 * `Getränkewart` are Vereinsämter, not concepts with an English equivalent,
 * the same precedent CONTEXT.md sets for `Storno` and `Deckel`.
 */

import { theme } from '../../styles/design-system'
import { modalLabelStyle } from '../modals/ModalError'
import { AdminRole } from '../../api/generated/adminRole'
import { toggleRole } from '../../utils/adminRoles'

const ROLE_ORDER: AdminRole[] = ['admin', 'kassenwart', 'getraenkewart']

const ROLE_LABEL: Record<AdminRole, string> = {
  admin: 'Admin',
  kassenwart: 'Kassenwart',
  getraenkewart: 'Getränkewart',
}

function CheckIcon() {
  return (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="20 6 9 17 4 12" />
    </svg>
  )
}

export interface RoleSelectorProps {
  value: AdminRole[]
  onChange: (roles: AdminRole[]) => void
  /** Locked when editing your own account — nobody can change their own roles. */
  disabled?: boolean
  /** Shown under the group when `disabled`, explaining why. */
  disabledReason?: string
  testId?: string
}

export function RoleSelector({
  value,
  onChange,
  disabled = false,
  disabledReason,
  testId = 'role-selector',
}: RoleSelectorProps) {
  return (
    <div data-testid={testId} style={{ marginBottom: theme.spacing.lg }}>
      <span style={modalLabelStyle()}>Role</span>
      <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.xs }}>
        {ROLE_ORDER.map((role) => {
          const checked = value.includes(role)

          return (
            <label
              key={role}
              data-testid={`${testId}-option-${role}`}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: theme.spacing.sm,
                padding: '10px 12px',
                background: checked ? theme.activeTint.primary : theme.colors.bg.input,
                border: `1px solid ${checked ? theme.activeTint.primaryBorder : theme.colors.border.input}`,
                borderRadius: theme.borderRadius.md,
                fontSize: theme.typography.fontSize.sm,
                color: disabled ? theme.colors.text.muted : theme.colors.text.primary,
                cursor: disabled ? 'not-allowed' : 'pointer',
                opacity: disabled ? 0.6 : 1,
                transition: `all ${theme.transitions.default}`,
              }}
            >
              {/* Native checkbox for a11y and keyboard support, visually
                  hidden behind the custom box drawn beside it. */}
              <input
                type="checkbox"
                data-testid={`${testId}-checkbox-${role}`}
                checked={checked}
                disabled={disabled}
                onChange={() => onChange(toggleRole(value, role))}
                style={{
                  position: 'absolute',
                  opacity: 0,
                  width: 0,
                  height: 0,
                }}
              />
              <span
                aria-hidden="true"
                style={{
                  flexShrink: 0,
                  width: 18,
                  height: 18,
                  borderRadius: 5,
                  border: `1.5px solid ${checked ? theme.colors.semantic.primary : theme.colors.border.input}`,
                  background: checked ? theme.colors.semantic.primary : 'transparent',
                  color: 'white',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  transition: `all ${theme.transitions.default}`,
                }}
              >
                {checked && <CheckIcon />}
              </span>
              <span style={{ fontWeight: checked ? theme.typography.fontWeight.semibold : theme.typography.fontWeight.normal }}>
                {ROLE_LABEL[role]}
              </span>
            </label>
          )
        })}
      </div>
      {disabled && disabledReason && (
        <p
          data-testid={`${testId}-disabled-reason`}
          style={{
            margin: `${theme.spacing.xs} 0 0 0`,
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.secondary,
          }}
        >
          {disabledReason}
        </p>
      )}
    </div>
  )
}
