import { IconProps } from '../types'

export function SaunaTowelIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <rect x="5" y="18" width="38" height="14" rx="7" fill="#FEF3C7" stroke="#92400E" strokeWidth="2.5"/>
      <ellipse cx="5" cy="25" rx="4" ry="7" fill="#FDE68A" stroke="#92400E" strokeWidth="2"/>
      <ellipse cx="43" cy="25" rx="4" ry="7" fill="#FDE68A" stroke="#92400E" strokeWidth="2"/>
      <line x1="13" y1="18" x2="13" y2="32" stroke="#FBBF24" strokeWidth="3"/>
      <line x1="24" y1="18" x2="24" y2="32" stroke="#FBBF24" strokeWidth="3"/>
      <line x1="35" y1="18" x2="35" y2="32" stroke="#FBBF24" strokeWidth="3"/>
    </svg>
  )
}
