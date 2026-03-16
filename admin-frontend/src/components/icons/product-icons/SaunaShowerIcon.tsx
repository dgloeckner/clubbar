import { IconProps } from '../types'

export function SaunaShowerIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <line x1="24" y1="10" x2="34" y2="4" stroke="#6B7280" strokeWidth="3" strokeLinecap="round"/>
      <rect x="12" y="8" width="24" height="10" rx="5" fill="#9CA3AF" stroke="#374151" strokeWidth="2.5"/>
      <line x1="16" y1="20" x2="14" y2="29" stroke="#BAE6FD" strokeWidth="2" strokeLinecap="round"/>
      <line x1="20" y1="20" x2="18" y2="29" stroke="#BAE6FD" strokeWidth="2" strokeLinecap="round"/>
      <line x1="24" y1="20" x2="23" y2="30" stroke="#BAE6FD" strokeWidth="2" strokeLinecap="round"/>
      <line x1="28" y1="20" x2="26" y2="29" stroke="#BAE6FD" strokeWidth="2" strokeLinecap="round"/>
      <line x1="32" y1="20" x2="30" y2="29" stroke="#BAE6FD" strokeWidth="2" strokeLinecap="round"/>
      <circle cx="14" cy="31" r="1.5" fill="#7DD3FC"/>
      <circle cx="18" cy="31" r="1.5" fill="#7DD3FC"/>
      <circle cx="23" cy="32" r="1.5" fill="#7DD3FC"/>
      <circle cx="26" cy="31" r="1.5" fill="#7DD3FC"/>
      <circle cx="30" cy="31" r="1.5" fill="#7DD3FC"/>
    </svg>
  )
}
