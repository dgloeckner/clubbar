import { IconProps } from '../types'

export function SaunaAufgussIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M10,44L8,24L40,24L38,44Z" fill="#9CA3AF" stroke="#374151" strokeWidth="2.5" strokeLinejoin="round"/>
      <line x1="7" y1="24" x2="41" y2="24" stroke="#374151" strokeWidth="2.5" strokeLinecap="round"/>
      <path d="M8,24Q8,16 24,13Q40,16 40,24" fill="none" stroke="#374151" strokeWidth="2.5" strokeLinecap="round"/>
      <ellipse cx="24" cy="34" rx="11" ry="4" fill="#78350F" opacity=".5"/>
      <path d="M15,22Q17,17 15,12" fill="none" stroke="#E5E7EB" strokeWidth="2" strokeLinecap="round"/>
      <path d="M22,21Q24,16 22,11" fill="none" stroke="#E5E7EB" strokeWidth="2" strokeLinecap="round"/>
      <path d="M29,21Q31,16 29,11" fill="none" stroke="#E5E7EB" strokeWidth="2" strokeLinecap="round"/>
      <path d="M36,22Q38,17 36,12" fill="none" stroke="#E5E7EB" strokeWidth="2" strokeLinecap="round"/>
    </svg>
  )
}
