import { IconProps } from '../types'

export function SaladIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M7,28Q7,44 24,44Q41,44 41,28Z" fill="#F3F4F6" stroke="#D1D5DB" strokeWidth="2.5" strokeLinejoin="round"/>
      <line x1="7" y1="28" x2="41" y2="28" stroke="#D1D5DB" strokeWidth="2.5" strokeLinecap="round"/>
      <path d="M9,28Q12,20 16,22Q14,16 20,16Q22,12 28,14Q34,10 36,18Q40,20 40,28Z" fill="#86EFAC" stroke="#166534" strokeWidth="2" strokeLinejoin="round"/>
      <path d="M14,28Q16,22 20,24Q20,18 24,18Q28,18 28,24Q32,22 34,28Z" fill="#D1FAE5" stroke="#166534" strokeWidth="1.5" strokeLinejoin="round"/>
      <circle cx="17" cy="20" r="4" fill="#F87171" stroke="#991B1B" strokeWidth="1.5"/>
      <path d="M17,16Q18,14 19,15" fill="none" stroke="#166534" strokeWidth="1.5" strokeLinecap="round"/>
      <circle cx="30" cy="18" r="4" fill="#A7F3D0" stroke="#065F46" strokeWidth="1.5"/>
      <circle cx="30" cy="18" r="2" fill="#D1FAE5" stroke="#065F46" strokeWidth="1" opacity=".7"/>
      <rect x="22" y="22" width="6" height="6" rx="1.5" fill="#FBBF24" stroke="#92400E" strokeWidth="1.5"/>
    </svg>
  )
}
