import { IconProps } from '../types'

export function CoffeeMugIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <rect x="8" y="16" width="28" height="24" rx="5" fill="#451A03" stroke="#78350F" strokeWidth="2.5"/>
      <path d="M36,22Q44,22 44,28Q44,34 36,34" fill="none" stroke="#78350F" strokeWidth="2.5" strokeLinecap="round"/>
      <ellipse cx="22" cy="19" rx="11" ry="2.5" fill="#78350F" opacity=".6"/>
      <path d="M14,13Q16,9 14,5" fill="none" stroke="#9CA3AF" strokeWidth="2" strokeLinecap="round"/>
      <path d="M22,12Q24,8 22,4" fill="none" stroke="#9CA3AF" strokeWidth="2" strokeLinecap="round"/>
      <path d="M30,13Q32,9 30,5" fill="none" stroke="#9CA3AF" strokeWidth="2" strokeLinecap="round"/>
    </svg>
  )
}
