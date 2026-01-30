/**
 * CharacterCounter Component
 * Displays character count with color warning based on limit
 */

import { theme } from '../../styles/design-system'

export interface CharacterCounterProps {
  currentLength: number
  maxLength: number
  testId?: string
}

export function CharacterCounter({ currentLength, maxLength, testId }: CharacterCounterProps) {
  // Determine color based on usage percentage
  const percentage = (currentLength / maxLength) * 100
  let color = theme.colors.text.secondary // Normal

  if (percentage > 80 && percentage < 100) {
    color = theme.colors.semantic.warning // Warning (orange)
  } else if (percentage >= 100) {
    color = theme.colors.semantic.danger // Danger (red)
  }

  return (
    <div
      data-testid={testId}
      style={{
        fontSize: theme.typography.fontSize.xs,
        color: color,
        transition: `color ${theme.transitions.default}`,
      }}
    >
      {currentLength}/{maxLength}
    </div>
  )
}
