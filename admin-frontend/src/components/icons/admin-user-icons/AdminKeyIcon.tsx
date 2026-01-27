import { IconProps } from '../types'

/**
 * Admin Key Icon - Access/permissions variant
 * User with key overlay
 */
export function AdminKeyIcon({ size = 20, ...props }: IconProps) {
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
      <circle cx="19" cy="5" r="2.5" />
      <path d="M17.25 6.75L13 11l1 1-1 1 1 1 3-3" />
    </svg>
  )
}
