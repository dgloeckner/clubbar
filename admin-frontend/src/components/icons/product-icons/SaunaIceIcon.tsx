import { IconProps } from '../types'

export function SaunaIceIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M12,44L10,24L38,24L36,44Z" fill="#BAE6FD" stroke="#0C4A6E" strokeWidth="2.5" strokeLinejoin="round"/>
      <path d="M10,24Q10,16 24,13Q38,16 38,24" fill="none" stroke="#0C4A6E" strokeWidth="2.5" strokeLinecap="round"/>
      <rect x="13" y="26" width="9" height="9" rx="2" fill="white" stroke="#0C4A6E" strokeWidth="1.5"/>
      <rect x="24" y="26" width="9" height="9" rx="2" fill="white" stroke="#0C4A6E" strokeWidth="1.5"/>
      <rect x="17" y="35" width="9" height="7" rx="2" fill="white" stroke="#0C4A6E" strokeWidth="1.5"/>
    </svg>
  )
}
