/**
 * Toggle Component
 * ON/OFF switch for enabling/disabling items
 */

import { theme } from '../../styles/design-system'

export interface ToggleProps {
  isEnabled: boolean
  onChange: (enabled: boolean) => void
  disabled?: boolean
  testId?: string
}

export function Toggle({ isEnabled, onChange, disabled = false, testId }: ToggleProps) {
  return (
    <button
      data-testid={testId}
      onClick={() => {
        if (!disabled) {
          onChange(!isEnabled)
        }
      }}
      disabled={disabled}
      style={{
        position: 'relative',
        width: '44px',
        height: '24px',
        borderRadius: '12px',
        border: 'none',
        background: isEnabled ? theme.colors.semantic.success : 'rgba(71,85,105,0.3)',
        cursor: disabled ? 'not-allowed' : 'pointer',
        transition: `all ${theme.transitions.default}`,
        padding: 0,
        display: 'flex',
        alignItems: 'center',
        flexShrink: 0,
      }}
      onMouseEnter={(e) => {
        if (!disabled) {
          e.currentTarget.style.opacity = '0.9'
        }
      }}
      onMouseLeave={(e) => {
        e.currentTarget.style.opacity = '1'
      }}
    >
      {/* Thumb (the moving circle) */}
      <div
        style={{
          position: 'absolute',
          width: '20px',
          height: '20px',
          borderRadius: '50%',
          background: 'white',
          left: isEnabled ? '22px' : '2px',
          transition: `left ${theme.transitions.default}`,
          boxShadow: '0 2px 4px rgba(0,0,0,0.2)',
        }}
      />
    </button>
  )
}
