import { IconProps } from '../types'

export function SteakIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <ellipse cx="24" cy="40" rx="19" ry="6" fill="#F3F4F6" stroke="#D1D5DB" strokeWidth="2"/>
      <ellipse cx="24" cy="38" rx="15" ry="4" fill="#E5E7EB" opacity=".7"/>
      <path d="M8,30Q6,20 14,14Q20,10 28,12Q36,10 42,18Q46,26 40,34Q34,40 24,38Q12,38 8,30Z" fill="#92400E" stroke="#451A03" strokeWidth="2.5" strokeLinejoin="round"/>
      <path d="M14,22Q18,18 22,22Q26,26 30,20" fill="none" stroke="#FDE68A" strokeWidth="2" strokeLinecap="round" opacity=".6"/>
      <path d="M20,30Q24,26 28,30" fill="none" stroke="#FDE68A" strokeWidth="1.5" strokeLinecap="round" opacity=".5"/>
      <line x1="16" y1="16" x2="12" y2="28" stroke="#451A03" strokeWidth="3" strokeLinecap="round" opacity=".5"/>
      <line x1="24" y1="14" x2="20" y2="28" stroke="#451A03" strokeWidth="3" strokeLinecap="round" opacity=".5"/>
      <line x1="32" y1="14" x2="28" y2="28" stroke="#451A03" strokeWidth="3" strokeLinecap="round" opacity=".5"/>
      <rect x="36" y="32" width="10" height="5" rx="2.5" fill="#F3F4F6" stroke="#D1D5DB" strokeWidth="2"/>
      <circle cx="36" cy="34.5" r="3" fill="#F3F4F6" stroke="#D1D5DB" strokeWidth="2"/>
      <circle cx="46" cy="34.5" r="3" fill="#F3F4F6" stroke="#D1D5DB" strokeWidth="2"/>
    </svg>
  )
}
