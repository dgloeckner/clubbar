import { IconProps } from '../types'

/**
 * Admin Star Icon - VIP/special status variant
 * User with star overlay
 */
export function AdminStarIcon({ size = 20, ...props }: IconProps) {
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
      <path d="M18 3l1 2 2.25 0.25-1.625 1.625 0.375 2.25L18 8l-2 1.125 0.375-2.25-1.625-1.625L17 5l1-2z" />
    </svg>
  )
}
