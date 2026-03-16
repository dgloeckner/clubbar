import { IconProps } from '../types'

export function SaunaTokenIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <circle cx="24" cy="24" r="19" fill="#FBBF24" stroke="#92400E" strokeWidth="2.5"/>
      <circle cx="24" cy="24" r="14" fill="none" stroke="#92400E" strokeWidth="1.5"/>
      <path d="M14,24Q17,19 20,24Q23,29 26,24Q29,19 34,24" fill="none" stroke="#92400E" strokeWidth="2" strokeLinecap="round"/>
    </svg>
  )
}
