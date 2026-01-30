/**
 * Tooltip Component
 * Displays tooltip on hover with configurable position
 */

import { ReactNode, useState } from 'react'
import { theme } from '../../styles/design-system'

export interface TooltipProps {
  content: string
  position?: 'top' | 'bottom' | 'left' | 'right'
  children: ReactNode
  testId?: string
}

export function Tooltip({ content, position = 'top', children, testId }: TooltipProps) {
  const [isVisible, setIsVisible] = useState(false)

  // Position styles
  const positionStyles: Record<string, React.CSSProperties> = {
    top: {
      bottom: '100%',
      left: '50%',
      transform: 'translateX(-50%)',
      marginBottom: theme.spacing.sm,
    },
    bottom: {
      top: '100%',
      left: '50%',
      transform: 'translateX(-50%)',
      marginTop: theme.spacing.sm,
    },
    left: {
      right: '100%',
      top: '50%',
      transform: 'translateY(-50%)',
      marginRight: theme.spacing.sm,
    },
    right: {
      left: '100%',
      top: '50%',
      transform: 'translateY(-50%)',
      marginLeft: theme.spacing.sm,
    },
  }

  // Arrow styles
  const arrowStyles: Record<string, React.CSSProperties> = {
    top: {
      bottom: '-4px',
      left: '50%',
      transform: 'translateX(-50%)',
      borderLeft: '4px solid transparent',
      borderRight: '4px solid transparent',
      borderTop: '4px solid #1f2937',
    },
    bottom: {
      top: '-4px',
      left: '50%',
      transform: 'translateX(-50%)',
      borderLeft: '4px solid transparent',
      borderRight: '4px solid transparent',
      borderBottom: '4px solid #1f2937',
    },
    left: {
      right: '-4px',
      top: '50%',
      transform: 'translateY(-50%)',
      borderTop: '4px solid transparent',
      borderBottom: '4px solid transparent',
      borderLeft: '4px solid #1f2937',
    },
    right: {
      left: '-4px',
      top: '50%',
      transform: 'translateY(-50%)',
      borderTop: '4px solid transparent',
      borderBottom: '4px solid transparent',
      borderRight: '4px solid #1f2937',
    },
  }

  return (
    <div
      style={{
        position: 'relative',
        display: 'inline-block',
      }}
      onMouseEnter={() => setIsVisible(true)}
      onMouseLeave={() => setIsVisible(false)}
      data-testid={testId}
    >
      {children}

      {isVisible && (
        <div
          style={{
            position: 'absolute',
            ...positionStyles[position],
            background: '#1f2937',
            color: 'white',
            padding: `${theme.spacing.sm} ${theme.spacing.md}`,
            borderRadius: theme.borderRadius.sm,
            fontSize: theme.typography.fontSize.xs,
            whiteSpace: 'nowrap',
            zIndex: 1000,
            boxShadow: theme.shadows.lg,
            pointerEvents: 'none',
          }}
        >
          {content}
          <div style={arrowStyles[position]} />
        </div>
      )}
    </div>
  )
}
