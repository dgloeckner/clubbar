import { IconProps } from '../types'

export function SaunaTimeIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <circle cx="24" cy="26" r="18" fill="#FDE68A" stroke="#92400E" strokeWidth="2.5"/>
      <circle cx="24" cy="26" r="13" fill="none" stroke="#92400E" strokeWidth="1" opacity=".3"/>
      <line x1="24" y1="26" x2="24" y2="14" stroke="#92400E" strokeWidth="2.5" strokeLinecap="round"/>
      <line x1="24" y1="26" x2="33" y2="26" stroke="#92400E" strokeWidth="2" strokeLinecap="round"/>
      <circle cx="24" cy="26" r="2" fill="#92400E"/>
      <path d="M20,8Q24,5 28,8" fill="none" stroke="#92400E" strokeWidth="2" strokeLinecap="round"/>
    </svg>
  )
}
