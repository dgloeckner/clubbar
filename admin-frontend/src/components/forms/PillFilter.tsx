/**
 * Pill Filter
 *
 * One filter for every "pick one of a handful of values" control on a list page.
 * It replaces StatusFilter, SettlementStatusFilter, StatusFilterPills and
 * TypeFilter (#122), which differed only in their option lists and — for the
 * last two — in whether the selected pill is tinted around a coloured dot or
 * filled solid. That is what `variant` selects:
 *
 * - `dot`   — rounded pill, coloured dot, border and background tinted in the
 *             option's own colour. Used where each option carries a meaning of
 *             its own (open vs. settled, active vs. cancelled).
 * - `solid` — square-ish pill filled blue when selected. Used where the options
 *             are just a tri-state (all / active / inactive).
 *
 * Test IDs follow the established convention: the group carries `testId`, each
 * pill carries `${testId}-${option.value}`.
 */

import { theme } from '../../styles/design-system'

export interface PillFilterOption<T extends string> {
  value: T
  label: string
  /** Dot and tint colour. Required by the `dot` variant, ignored by `solid`. */
  color?: string
}

export interface PillFilterProps<T extends string> {
  value: T
  onChange: (value: T) => void
  options: ReadonlyArray<PillFilterOption<T>>
  variant?: 'dot' | 'solid'
  testId?: string
}

const NEUTRAL = theme.colors.semantic.neutral

export function PillFilter<T extends string>({
  value,
  onChange,
  options,
  variant = 'dot',
  testId = 'pill-filter',
}: PillFilterProps<T>) {
  return (
    <div
      data-testid={testId}
      style={{
        display: variant === 'dot' ? 'flex' : 'inline-flex',
        gap: '8px',
      }}
    >
      {options.map((option) => {
        const selected = value === option.value
        const color = option.color ?? NEUTRAL

        // Idle border/text colours, restored by onMouseLeave. Kept in one place
        // so the hover handlers below cannot drift from the inline style.
        const idleBorder = variant === 'dot' ? theme.colors.border.muted : theme.colors.border.slate
        const idleText = variant === 'dot' ? theme.colors.text.subtle : theme.colors.text.secondary
        const hoverBorder = variant === 'dot' ? color : 'rgba(71, 85, 105, 0.6)'
        const hoverText = variant === 'dot' ? color : theme.colors.text.primary

        return (
          <button
            key={option.value}
            data-testid={`${testId}-${option.value}`}
            onClick={() => onChange(option.value)}
            style={
              variant === 'dot'
                ? {
                    padding: '6px 12px',
                    borderRadius: '20px',
                    border: selected ? `2px solid ${color}` : `1px solid ${idleBorder}`,
                    backgroundColor: selected ? `${color}20` : 'transparent',
                    color: selected ? color : idleText,
                    fontSize: '13px',
                    fontWeight: selected ? 600 : 500,
                    cursor: 'pointer',
                    transition: 'all 0.15s',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '6px',
                  }
                : {
                    display: 'flex',
                    alignItems: 'center',
                    gap: '8px',
                    padding: '8px 14px',
                    borderRadius: '8px',
                    background: selected ? theme.colors.semantic.primary : 'transparent',
                    border: `1px solid ${selected ? theme.colors.semantic.primary : idleBorder}`,
                    color: selected ? 'white' : idleText,
                    fontSize: '14px',
                    fontWeight: 500,
                    cursor: 'pointer',
                    transition: 'all 0.15s',
                  }
            }
            onMouseEnter={(e) => {
              if (selected) return
              e.currentTarget.style.borderColor = hoverBorder
              e.currentTarget.style.color = hoverText
            }}
            onMouseLeave={(e) => {
              if (selected) return
              e.currentTarget.style.borderColor = idleBorder
              e.currentTarget.style.color = idleText
            }}
          >
            {variant === 'dot' && (
              <span
                style={{
                  display: 'inline-block',
                  width: '8px',
                  height: '8px',
                  borderRadius: '50%',
                  backgroundColor: color,
                  opacity: selected ? 1 : 0.5,
                }}
              />
            )}
            {option.label}
          </button>
        )
      })}
    </div>
  )
}
