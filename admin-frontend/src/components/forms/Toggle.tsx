/**
 * Toggle Component
 * Status toggle switch for activate/deactivate
 * Based on prototypes/Toggle.jsx
 *
 * Props:
 * - enabled: Current state
 * - onChange: Callback when toggled
 * - size: 'small' | 'default' | 'large'
 * - showLabel: Show Aktiv/Inaktiv text
 * - disabled: Disable interaction
 * - testId: Test ID for the button
 */

interface ToggleProps {
  enabled: boolean
  onChange: (value: boolean) => void
  size?: 'small' | 'default' | 'large'
  showLabel?: boolean
  disabled?: boolean
  testId?: string
}

export function Toggle({
  enabled,
  onChange,
  size = 'default',
  showLabel = false,
  disabled = false,
  testId = 'toggle',
}: ToggleProps) {
  const sizes = {
    small: { width: 36, height: 20, knob: 14, translate: 16 },
    default: { width: 44, height: 24, knob: 18, translate: 20 },
    large: { width: 52, height: 28, knob: 22, translate: 24 },
  }

  const s = sizes[size]

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
      <button
        data-testid={testId}
        role="switch"
        aria-checked={enabled}
        onClick={() => !disabled && onChange(!enabled)}
        disabled={disabled}
        style={{
          position: 'relative',
          width: s.width,
          height: s.height,
          borderRadius: s.height / 2,
          border: 'none',
          background: enabled ? '#22c55e' : 'rgba(71, 85, 105, 0.5)',
          cursor: disabled ? 'not-allowed' : 'pointer',
          transition: 'background 0.2s',
          opacity: disabled ? 0.5 : 1,
          padding: 0,
          outline: 'none',
        }}
      >
        <span
          style={{
            position: 'absolute',
            top: (s.height - s.knob) / 2,
            left: enabled ? s.translate : (s.height - s.knob) / 2,
            width: s.knob,
            height: s.knob,
            borderRadius: '50%',
            background: enabled ? 'white' : '#94a3b8',
            transition: 'left 0.2s, background 0.2s',
            boxShadow: '0 2px 4px rgba(0,0,0,0.2)',
          }}
        />
      </button>

      {showLabel && (
        <span
          style={{
            fontSize: size === 'small' ? 12 : 13,
            fontWeight: 500,
            color: enabled ? '#22c55e' : '#94a3b8',
            minWidth: 50,
          }}
        >
          {enabled ? 'Aktiv' : 'Inaktiv'}
        </span>
      )}
    </div>
  )
}
