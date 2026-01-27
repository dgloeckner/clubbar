import { IconProps } from '../types'

export function AppleJuiceIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M7 20L6 6c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 14c0 1-1 2-5 2s-5-1-5-2z" fill="#fb923c" fillOpacity="0.4"/>
      <circle cx="17" cy="5" r="2.5" fill="#22c55e"/>
      <path d="M17 2.5q1-1 1 0" stroke="#166534" strokeWidth="1" fill="none"/>
      <circle cx="10" cy="12" r="0.5" fill="currentColor" opacity="0.3"/>
      <circle cx="13" cy="15" r="0.5" fill="currentColor" opacity="0.3"/>
    </svg>
  )
}
