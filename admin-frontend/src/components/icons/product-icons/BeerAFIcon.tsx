import { IconProps } from '../types'

export function BeerAFIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M7 20L6 8c0-1 .5-2 2-2h8c1.5 0 2 1 2 2l-1 12c0 1-1 2-5 2s-5-1-5-2z" fill="#fbbf24" fillOpacity="0.3"/>
      <ellipse cx="12" cy="8" rx="5" ry="1.5" fill="#fef3c7"/>
      <circle cx="18" cy="16" r="4" fill="#22c55e" stroke="none"/>
      <text x="18" y="17.5" textAnchor="middle" fill="white" fontSize="5" fontWeight="bold">0%</text>
    </svg>
  )
}
