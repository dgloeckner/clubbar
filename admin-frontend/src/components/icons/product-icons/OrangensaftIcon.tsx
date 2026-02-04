import { IconProps } from '../types'

export function OrangensaftIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M7 20L6 6c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 14c0 1-1 2-5 2s-5-1-5-2z" fill="#f97316" fillOpacity="0.4"/>
      <circle cx="17" cy="5" r="3" fill="#fb923c" stroke="#ea580c" strokeWidth="1"/>
      <circle cx="17" cy="5" r="0.8" fill="#fed7aa" stroke="none"/>
      <circle cx="10" cy="12" r="0.5" fill="currentColor" opacity="0.3"/>
      <circle cx="13" cy="15" r="0.5" fill="currentColor" opacity="0.3"/>
    </svg>
  )
}
