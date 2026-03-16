import { IconProps } from '../types'

export function FishSandwichIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M7,42Q7,46 24,46Q41,46 41,42L41,38L7,38Z" fill="#FDE68A" stroke="#92400E" strokeWidth="2.5" strokeLinejoin="round"/>
      <path d="M14,38Q16,35 18,38" fill="none" stroke="white" strokeWidth="2" strokeLinecap="round"/>
      <path d="M28,38Q30,35 32,38" fill="none" stroke="white" strokeWidth="2" strokeLinecap="round"/>
      <path d="M6,38Q10,34 16,36Q20,32 24,35Q28,32 32,36Q38,34 42,38Z" fill="#86EFAC" stroke="#166534" strokeWidth="1.5" strokeLinejoin="round"/>
      <rect x="8" y="25" width="32" height="11" rx="5" fill="#FDE68A" stroke="#92400E" strokeWidth="2.5"/>
      <rect x="11" y="27.5" width="26" height="6" rx="3" fill="white" stroke="#BAE6FD" strokeWidth="1.5"/>
      <circle cx="15" cy="29" r="1" fill="#FBBF24" opacity=".7"/>
      <circle cx="21" cy="28" r="1" fill="#FBBF24" opacity=".7"/>
      <circle cx="27" cy="29.5" r="1" fill="#FBBF24" opacity=".7"/>
      <circle cx="33" cy="28.5" r="1" fill="#FBBF24" opacity=".7"/>
      <path d="M40,28Q46,26 46,31Q46,36 40,34Z" fill="#FBBF24" stroke="#92400E" strokeWidth="2" strokeLinejoin="round"/>
      <path d="M7,25Q7,15 24,13Q41,15 41,25Z" fill="#FBBF24" stroke="#92400E" strokeWidth="2.5" strokeLinejoin="round"/>
      <circle cx="19" cy="18.5" r="1.3" fill="#92400E" opacity=".5"/>
      <circle cx="26" cy="17" r="1.3" fill="#92400E" opacity=".5"/>
      <circle cx="32" cy="19.5" r="1.3" fill="#92400E" opacity=".5"/>
    </svg>
  )
}
