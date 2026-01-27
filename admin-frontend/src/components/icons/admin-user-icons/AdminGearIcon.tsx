import { IconProps } from '../types'

/**
 * Admin Gear Icon - Settings/control variant
 * User with gear/settings overlay
 */
export function AdminGearIcon({ size = 20, ...props }: IconProps) {
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
      <circle cx="18" cy="8" r="2" />
      <path d="M18 4v1m0 6v1m-3-4.5h1m6 0h1m-6.25-2.75l.75.75m3.5 3.5l.75.75m-5 0l.75-.75m3.5-3.5l.75-.75" />
    </svg>
  )
}
