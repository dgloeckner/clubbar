/**
 * LoadingIndicator Component
 * 
 * Displays a fixed 3px bar at the top of the viewport with a sliding blue gradient animation.
 * Non-blocking indicator that shows during async operations (API calls, page transitions, etc.)
 * 
 * Usage:
 *   <LoadingIndicator show={isLoading} />
 */

import { theme } from '../../styles/design-system'

interface LoadingIndicatorProps {
  show: boolean
}

export function LoadingIndicator({ show }: LoadingIndicatorProps) {
  if (!show) return null

  return (
    <div
      data-testid="loading-indicator"
      style={{
        position: 'fixed',
        top: 0,
        left: 0,
        right: 0,
        height: '3px',
        background: theme.activeTint.primaryStrong,
        zIndex: 9999,
        overflow: 'hidden',
      }}
    >
      <div
        style={{
          position: 'absolute',
          top: 0,
          left: 0,
          width: '25%',
          height: '100%',
          background: `linear-gradient(90deg, transparent, ${theme.colors.semantic.primary}, transparent)`,
          animation: 'loadingSlide 0.8s ease-in-out infinite',
        }}
      />
      <style>{`
        @keyframes loadingSlide {
          0% { transform: translateX(-100%); }
          100% { transform: translateX(400%); }
        }
      `}</style>
    </div>
  )
}
