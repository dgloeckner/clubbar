import { IconProps } from '../types'

export function WeissweinIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M13,10Q13,26 24,30Q35,26 35,10Z" fill="#FDE68A" stroke="#78350F" strokeWidth="2.5" strokeLinejoin="round"/>
      <line x1="13" y1="10" x2="35" y2="10" stroke="#78350F" strokeWidth="2.5" strokeLinecap="round"/>
      <line x1="24" y1="30" x2="24" y2="42" stroke="#78350F" strokeWidth="2.5" strokeLinecap="round"/>
      <line x1="15" y1="42" x2="33" y2="42" stroke="#78350F" strokeWidth="2.5" strokeLinecap="round"/>
      <path d="M16,16Q18,12 20,16" fill="none" stroke="white" strokeWidth="1.5" strokeLinecap="round" opacity=".7"/>
    </svg>
  )
}
