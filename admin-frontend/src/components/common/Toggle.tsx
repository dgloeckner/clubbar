/**
 * Toggle Component
 * ON/OFF switch for enabling/disabling items.
 *
 * The single toggle in the app. It replaces the second, near-identical
 * `forms/Toggle` (#122), which took `enabled` instead of `isEnabled`, hardcoded
 * its colours instead of reading design tokens, and rendered a hardcoded German
 * "Aktiv"/"Inaktiv" label that no call site ever asked for. The `size` prop it
 * did carry is genuinely used by the list pages and is kept here.
 */

import { theme } from '../../styles/design-system'

export type ToggleSize = 'small' | 'default' | 'large'

export interface ToggleProps {
  isEnabled: boolean
  onChange: (enabled: boolean) => void
  size?: ToggleSize
  disabled?: boolean
  testId?: string
}

/**
 * Track and knob geometry per size. The knob sits at `inset` from whichever end
 * it is parked against, so both rest positions are symmetric — the old
 * `forms/Toggle` hardcoded a translate distance that left the "on" knob 6px
 * from the right edge and 3px from the left.
 */
const SIZES: Record<ToggleSize, { width: number; height: number; knob: number }> = {
  small: { width: 36, height: 20, knob: 14 },
  default: { width: 44, height: 24, knob: 20 },
  large: { width: 52, height: 28, knob: 24 },
}

export function Toggle({
  isEnabled,
  onChange,
  size = 'default',
  disabled = false,
  testId,
}: ToggleProps) {
  const { width, height, knob } = SIZES[size]
  const inset = (height - knob) / 2

  return (
    <button
      data-testid={testId}
      role="switch"
      aria-checked={isEnabled}
      onClick={() => {
        if (!disabled) {
          onChange(!isEnabled)
        }
      }}
      disabled={disabled}
      style={{
        position: 'relative',
        width: `${width}px`,
        height: `${height}px`,
        borderRadius: `${height / 2}px`,
        border: 'none',
        background: isEnabled ? theme.colors.semantic.success : 'rgba(71,85,105,0.3)',
        cursor: disabled ? 'not-allowed' : 'pointer',
        transition: `all ${theme.transitions.default}`,
        opacity: disabled ? 0.5 : 1,
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
        e.currentTarget.style.opacity = disabled ? '0.5' : '1'
      }}
    >
      {/* Thumb (the moving circle) */}
      <div
        style={{
          position: 'absolute',
          width: `${knob}px`,
          height: `${knob}px`,
          borderRadius: '50%',
          background: 'white',
          left: isEnabled ? `${width - knob - inset}px` : `${inset}px`,
          transition: `left ${theme.transitions.default}`,
          boxShadow: '0 2px 4px rgba(0,0,0,0.2)',
        }}
      />
    </button>
  )
}
