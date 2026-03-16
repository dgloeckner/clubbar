import { IconProps } from '../types'

export function RotweinIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M10,8Q10,30 24,34Q38,30 38,8Z" fill="#9F1239" stroke="#500724" strokeWidth="2.5" strokeLinejoin="round"/>
      <line x1="10" y1="8" x2="38" y2="8" stroke="#500724" strokeWidth="2.5" strokeLinecap="round"/>
      <line x1="24" y1="34" x2="24" y2="42" stroke="#500724" strokeWidth="2.5" strokeLinecap="round"/>
      <line x1="14" y1="42" x2="34" y2="42" stroke="#500724" strokeWidth="2.5" strokeLinecap="round"/>
      <path d="M13,16Q15,12 17,16" fill="none" stroke="#FCA5A5" strokeWidth="1.5" strokeLinecap="round" opacity=".6"/>
    </svg>
  )
}
