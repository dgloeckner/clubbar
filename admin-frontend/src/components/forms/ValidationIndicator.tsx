/**
 * ValidationIndicator Component
 * Shows checkmark for valid fields, X for invalid fields
 */

import { theme } from '../../styles/design-system'

export interface ValidationIndicatorProps {
  isValid: boolean
  show: boolean
  testId?: string
}

export function ValidationIndicator({ isValid, show, testId }: ValidationIndicatorProps) {
  if (!show) {
    return null
  }

  return (
    <div
      data-testid={testId}
      style={{
        fontSize: theme.typography.fontSize.lg,
        color: isValid ? theme.colors.semantic.success : theme.colors.semantic.danger,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        width: '20px',
        height: '20px',
      }}
    >
      {isValid ? '✓' : '✗'}
    </div>
  )
}
