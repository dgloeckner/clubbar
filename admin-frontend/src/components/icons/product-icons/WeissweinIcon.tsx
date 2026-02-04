import { IconProps } from '../types'

export function WeissweinIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M7 3c.5 4 1.5 7 5 9 3.5-2 4.5-5 5-9z" fill="#fde68a" fillOpacity="0.4"/>
      <line x1="7" y1="3" x2="17" y2="3"/>
      <line x1="12" y1="12" x2="12" y2="19"/>
      <ellipse cx="12" cy="20" rx="3.5" ry="1.5"/>
    </svg>
  )
}
