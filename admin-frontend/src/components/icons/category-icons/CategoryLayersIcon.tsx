import { IconProps } from '../types'

export function CategoryLayersIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <polygon points="12,2 22,8.5 12,15 2,8.5"/>
      <polyline points="2,12.5 12,19 22,12.5"/>
      <polyline points="2,16.5 12,23 22,16.5"/>
    </svg>
  )
}
