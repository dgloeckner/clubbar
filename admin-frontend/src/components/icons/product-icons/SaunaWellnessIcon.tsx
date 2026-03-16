import { IconProps } from '../types'

export function SaunaWellnessIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M24,38Q20,28 24,18Q28,28 24,38Z" fill="#F9A8D4" stroke="#831843" strokeWidth="2"/>
      <path d="M24,38Q13,34 11,22Q19,24 24,38Z" fill="#F9A8D4" stroke="#831843" strokeWidth="2"/>
      <path d="M24,38Q35,34 37,22Q29,24 24,38Z" fill="#F9A8D4" stroke="#831843" strokeWidth="2"/>
      <path d="M24,38Q8,36 7,26Q15,30 24,38Z" fill="#FBCFE8" stroke="#831843" strokeWidth="1.5"/>
      <path d="M24,38Q40,36 41,26Q33,30 24,38Z" fill="#FBCFE8" stroke="#831843" strokeWidth="1.5"/>
      <line x1="4" y1="40" x2="44" y2="40" stroke="#7DD3FC" strokeWidth="2" strokeLinecap="round"/>
      <path d="M4,44Q8,42 12,44Q16,46 20,44Q24,42 28,44Q32,46 36,44Q40,42 44,44" fill="none" stroke="#7DD3FC" strokeWidth="1.5" strokeLinecap="round"/>
    </svg>
  )
}
