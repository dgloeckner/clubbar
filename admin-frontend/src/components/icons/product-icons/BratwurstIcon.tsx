import { IconProps } from '../types'

export function BratwurstIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <ellipse cx="24" cy="43" rx="16" ry="3" fill="#E5E7EB" opacity=".7"/>
      <path d="M8,38Q8,44 24,44Q40,44 40,38L40,34L8,34Z" fill="#FDE68A" stroke="#92400E" strokeWidth="2.5" strokeLinejoin="round"/>
      <rect x="7" y="27" width="34" height="9" rx="4.5" fill="#C2410C" stroke="#7C2D12" strokeWidth="2.5"/>
      <line x1="17" y1="28" x2="15" y2="35" stroke="#7C2D12" strokeWidth="2" strokeLinecap="round" opacity=".6"/>
      <line x1="24" y1="27.5" x2="22" y2="34.5" stroke="#7C2D12" strokeWidth="2" strokeLinecap="round" opacity=".6"/>
      <line x1="31" y1="28" x2="29" y2="35" stroke="#7C2D12" strokeWidth="2" strokeLinecap="round" opacity=".6"/>
      <path d="M11,30Q14,27 17,30Q20,33 23,30Q26,27 29,30Q32,33 35,30" fill="none" stroke="#FDE047" strokeWidth="2" strokeLinecap="round"/>
      <path d="M8,34Q8,24 24,22Q40,24 40,34Z" fill="#FBBF24" stroke="#92400E" strokeWidth="2.5" strokeLinejoin="round"/>
      <circle cx="18" cy="27" r="1.3" fill="#92400E" opacity=".5"/>
      <circle cx="24" cy="25.5" r="1.3" fill="#92400E" opacity=".5"/>
      <circle cx="30" cy="27" r="1.3" fill="#92400E" opacity=".5"/>
    </svg>
  )
}
