import { IconProps } from '../types'

export function LemonadeIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M7 20L6 6c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 14c0 1-1 2-5 2s-5-1-5-2z" fill="#fef08a" fillOpacity="0.4"/>
      <rect x="9" y="9" width="3" height="2.5" rx="0.5" fill="white" fillOpacity="0.6" transform="rotate(10, 10, 10)"/>
      <circle cx="17" cy="5" r="3" fill="#fde047" stroke="#facc15"/>
      <circle cx="10" cy="14" r="0.5" fill="currentColor" opacity="0.3"/>
    </svg>
  )
}
