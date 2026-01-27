import { IconProps } from '../types'

export function WaterSmallIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M9 20V8c0-1 1-2 2-2h2c1 0 2 1 2 2v12c0 1-.5 2-3 2s-3-1-3-2z" fill="#60a5fa" fillOpacity="0.2"/>
      <circle cx="11" cy="13" r="0.6" fill="currentColor" opacity="0.3"/>
      <circle cx="13" cy="11" r="0.5" fill="currentColor" opacity="0.3"/>
      <circle cx="12" cy="16" r="0.5" fill="currentColor" opacity="0.3"/>
      <text x="12" y="5" textAnchor="middle" fill="currentColor" fontSize="4" opacity="0.6">0,33</text>
    </svg>
  )
}
