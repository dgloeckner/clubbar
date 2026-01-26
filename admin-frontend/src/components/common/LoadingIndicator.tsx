interface LoadingIndicatorProps {
  show: boolean
}

export function LoadingIndicator({ show }: LoadingIndicatorProps) {
  if (!show) return null

  return (
    <>
      <div
        style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          height: '3px',
          background: 'rgba(59, 130, 246, 0.2)',
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
            background: 'linear-gradient(90deg, transparent, #3b82f6, transparent)',
            animation: 'loadingSlide 0.8s ease-in-out infinite',
          }}
        />
      </div>
      <style>{`
        @keyframes loadingSlide {
          0% { transform: translateX(-100%); }
          100% { transform: translateX(400%); }
        }
      `}</style>
    </>
  )
}
