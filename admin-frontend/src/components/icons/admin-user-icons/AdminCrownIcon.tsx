import { IconProps } from '../types'

/**
 * Admin Crown Icon - Royalty/authority variant
 * User with crown overlay
 */
export function AdminCrownIcon({ size = 20, ...props }: IconProps) {
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
      <path d="M12 19c-3 0-6 1.5-6 3v2h12v-2c0-1.5-3-3-6-3z" />
      <circle cx="12" cy="13" r="4" />
      <path d="M6 4l2 4 4-2 4 2 2-4v6H6V4z" />
    </svg>
  )
}
