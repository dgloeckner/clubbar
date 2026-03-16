import { IconProps } from '../types'

export function AppleJuiceIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M13,43L11,14L37,14L35,43Z" fill="#FDE68A" stroke="#92400E" strokeWidth="2.5" strokeLinejoin="round"/>
      <line x1="10" y1="14" x2="38" y2="14" stroke="#92400E" strokeWidth="2.5" strokeLinecap="round"/>
      <line x1="12" y1="43" x2="36" y2="43" stroke="#92400E" strokeWidth="2.5" strokeLinecap="round"/>
      <circle cx="24" cy="8" r="5" fill="#86EFAC" stroke="#166534" strokeWidth="2"/>
      <path d="M22,3Q25,0 28,2" fill="none" stroke="#166534" strokeWidth="1.5" strokeLinecap="round"/>
      <line x1="24" y1="3" x2="24" y2="6" stroke="#166534" strokeWidth="1.5"/>
      <circle cx="19" cy="30" r="1.5" fill="white" opacity=".6"/>
      <circle cx="28" cy="24" r="1.2" fill="white" opacity=".5"/>
    </svg>
  )
}
