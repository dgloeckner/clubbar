
import { IconProps } from './index'

interface ToggleIconProps extends IconProps {
  active?: boolean
}

export function ToggleIcon({ size = 18, active = false, ...props }: ToggleIconProps) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      {...props}
    >
      <rect x="1" y="5" width="22" height="14" rx="7" ry="7" />
      {active ? (
        <circle cx="16" cy="12" r="3" fill="currentColor" />
      ) : (
        <circle cx="8" cy="12" r="3" />
      )}
    </svg>
  )
}
