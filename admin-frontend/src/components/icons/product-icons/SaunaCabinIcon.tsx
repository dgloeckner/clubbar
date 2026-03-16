import { IconProps } from '../types'

export function SaunaCabinIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M4,28L24,8L44,28Z" fill="#7C3F1A" stroke="#78350F" strokeWidth="2.5" strokeLinejoin="round"/>
      <rect x="8" y="26" width="32" height="18" rx="2" fill="#C4934F" stroke="#78350F" strokeWidth="2.5"/>
      <rect x="19" y="30" width="10" height="14" rx="2" fill="#78350F"/>
      <rect x="10" y="29" width="7" height="7" rx="1.5" fill="#BAE6FD" stroke="#78350F" strokeWidth="1.5"/>
      <rect x="30" y="14" width="5" height="12" rx="1" fill="#9CA3AF" stroke="#374151" strokeWidth="2"/>
      <path d="M32,13Q34,9 32,6" fill="none" stroke="#9CA3AF" strokeWidth="1.5" strokeLinecap="round"/>
    </svg>
  )
}
