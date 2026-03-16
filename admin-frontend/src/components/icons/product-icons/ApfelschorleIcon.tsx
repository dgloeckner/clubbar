import { IconProps } from '../types'

export function ApfelschorleIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M17,43L15,11L33,11L31,43Z" fill="#D1FAE5" stroke="#065F46" strokeWidth="2.5" strokeLinejoin="round"/>
      <line x1="14" y1="11" x2="34" y2="11" stroke="#065F46" strokeWidth="2.5" strokeLinecap="round"/>
      <line x1="16" y1="43" x2="32" y2="43" stroke="#065F46" strokeWidth="2.5" strokeLinecap="round"/>
      <circle cx="37" cy="10" r="6" fill="#86EFAC" stroke="#065F46" strokeWidth="2"/>
      <path d="M35,4Q37,2 40,3" fill="none" stroke="#065F46" strokeWidth="1.5" strokeLinecap="round"/>
      <line x1="37" y1="4" x2="37" y2="8" stroke="#065F46" strokeWidth="1.5" strokeLinecap="round"/>
      <circle cx="20" cy="28" r="1.5" fill="white" opacity=".8"/>
      <circle cx="26" cy="20" r="1.2" fill="white" opacity=".7"/>
      <circle cx="28" cy="36" r="1.5" fill="white" opacity=".8"/>
    </svg>
  )
}
