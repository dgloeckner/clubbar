import { IconProps } from '../types'

export function CrispsIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M13,44L10,22L38,22L35,44Z" fill="#EF4444" stroke="#991B1B" strokeWidth="2.5" strokeLinejoin="round"/>
      <path d="M15,44L13,28L20,28L21,44Z" fill="#F87171" opacity=".5"/>
      <path d="M10,22Q12,16 18,14L30,14Q36,16 38,22Z" fill="#EF4444" stroke="#991B1B" strokeWidth="2.5" strokeLinejoin="round"/>
      <path d="M18,14Q21,10 24,9Q27,10 30,14" fill="none" stroke="#991B1B" strokeWidth="2.5" strokeLinecap="round"/>
      <path d="M16,18Q18,14 22,16Q20,20 16,18Z" fill="#FDE68A" stroke="#92400E" strokeWidth="1.5" strokeLinejoin="round"/>
      <path d="M22,16Q26,12 30,15Q28,19 22,16Z" fill="#FDE68A" stroke="#92400E" strokeWidth="1.5" strokeLinejoin="round"/>
      <path d="M30,15Q34,13 37,17Q34,20 30,15Z" fill="#FDE68A" stroke="#92400E" strokeWidth="1.5" strokeLinejoin="round"/>
      <path d="M11,32L37,32" stroke="#FDE68A" strokeWidth="3" strokeLinecap="round" opacity=".4"/>
    </svg>
  )
}
