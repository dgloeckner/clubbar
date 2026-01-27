import { IconProps } from '../types'

/**
 * Admin User Icon - Shield variant (security/protection feel)
 * User with shield and checkmark overlay
 */
export function AdminUserIcon({ size = 20, ...props }: IconProps) {
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
      <path d="M8 18c-3 0-6 1.5-6 3v2h12v-2c0-1.5-3-3-6-3z" />
      <circle cx="8" cy="12" r="4" />
      <path d="M16 4l4 2v4c0 3-2 5.5-4 6.5-2-1-4-3.5-4-6.5V6l4-2z" />
      <path d="M16 9l-1.5 1.5 3 3" strokeWidth="1.5" />
    </svg>
  )
}
