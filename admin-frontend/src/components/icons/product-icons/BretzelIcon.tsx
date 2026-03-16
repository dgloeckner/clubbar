import { IconProps } from '../types'

export function BretzelIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M27,14Q36,10 38,18Q40,26 32,28Q28,29 26,26" fill="none" stroke="#92400E" strokeWidth="7" strokeLinecap="round"/>
      <path d="M27,14Q36,10 38,18Q40,26 32,28Q28,29 26,26" fill="none" stroke="#C4934F" strokeWidth="4.5" strokeLinecap="round"/>
      <path d="M21,14Q12,10 10,18Q8,26 16,28Q20,29 22,26" fill="none" stroke="#92400E" strokeWidth="7" strokeLinecap="round"/>
      <path d="M21,14Q12,10 10,18Q8,26 16,28Q20,29 22,26" fill="none" stroke="#C4934F" strokeWidth="4.5" strokeLinecap="round"/>
      <path d="M22,26Q20,32 18,38Q22,42 24,43Q26,42 30,38Q28,32 26,26" fill="#92400E" stroke="#92400E" strokeWidth="2" strokeLinejoin="round"/>
      <path d="M22,26Q20,32 18,38Q22,42 24,43Q26,42 30,38Q28,32 26,26" fill="#C4934F" stroke="#92400E" strokeWidth="1.5" strokeLinejoin="round"/>
      <path d="M21,14L27,14" stroke="#92400E" strokeWidth="7" strokeLinecap="round"/>
      <path d="M21,14L27,14" stroke="#C4934F" strokeWidth="4.5" strokeLinecap="round"/>
      <rect x="13" y="20" width="3.5" height="3.5" rx="1" fill="white" opacity=".85" transform="rotate(15 13 20)"/>
      <rect x="30" y="19" width="3.5" height="3.5" rx="1" fill="white" opacity=".85" transform="rotate(-10 30 19)"/>
      <rect x="21" y="37" width="3" height="3" rx="1" fill="white" opacity=".85" transform="rotate(20 21 37)"/>
      <rect x="27" y="24" width="3" height="3" rx="1" fill="white" opacity=".85" transform="rotate(-15 27 24)"/>
    </svg>
  )
}
