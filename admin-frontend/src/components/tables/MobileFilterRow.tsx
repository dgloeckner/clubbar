/**
 * MobileFilterRow — a labelled row of filter pills for the mobile filter panel.
 *
 * Members, Products and Categories each carried a near-verbatim copy of this
 * component defined *inside* the page function (#121). Besides the duplication,
 * a component declared inside a render is a new component type on every render,
 * so React unmounted and remounted the whole row whenever the page re-rendered.
 *
 * Selecting a pill only reports the new value; resetting the page belongs to
 * `useListQuery`, which resets it for every filter change rather than only the
 * ones a call site remembered to handle.
 */

import { theme } from '../../styles/design-system'

export interface MobileFilterOption {
  value: string
  label: string
}

interface MobileFilterRowProps {
  label: string
  options: MobileFilterOption[]
  value: string
  onChange: (value: string) => void
  /** Prefix for each pill's test id: `${testId}-${option.value}`. */
  testId: string
}

export function MobileFilterRow({ label, options, value, onChange, testId }: MobileFilterRowProps) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
      <span
        style={{
          fontSize: '12px',
          color: theme.colors.text.label,
          fontWeight: 500,
          textTransform: 'uppercase',
          minWidth: '50px',
        }}
      >
        {label}
      </span>
      {options.map((option) => {
        const selected = value === option.value
        return (
          <button
            key={option.value}
            data-testid={`${testId}-${option.value}`}
            onClick={() => onChange(option.value)}
            style={{
              padding: '4px 10px',
              borderRadius: '6px',
              border: 'none',
              background: selected ? 'rgba(59,130,246,0.2)' : 'rgba(255,255,255,0.04)',
              color: selected ? theme.colors.semantic.primary : 'rgba(255,255,255,0.5)',
              fontSize: '12px',
              fontWeight: selected ? 600 : 400,
              cursor: 'pointer',
            }}
          >
            {option.label}
          </button>
        )
      })}
    </div>
  )
}
