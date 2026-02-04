import { IconProps } from '../types'

export function LimonadeIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M7 20L6 6c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 14c0 1-1 2-5 2s-5-1-5-2z" fill="#fef08a" fillOpacity="0.5"/>
      <line x1="14" y1="2" x2="15" y2="12" stroke="#ef4444" strokeWidth="1.5"/>
      <circle cx="9" cy="13" r="0.5" fill="currentColor" opacity="0.3"/>
      <circle cx="12" cy="17" r="0.4" fill="currentColor" opacity="0.25"/>
      <circle cx="11" cy="10" r="0.4" fill="currentColor" opacity="0.2"/>
      <circle cx="13" cy="14" r="0.3" fill="currentColor" opacity="0.2"/>
    </svg>
  )
}
