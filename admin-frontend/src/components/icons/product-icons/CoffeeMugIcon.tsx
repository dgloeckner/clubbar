import { IconProps } from '../types'

export function CoffeeMugIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M5 8h10v11c0 1.5-2 3-5 3s-5-1.5-5-3V8z" fill="#92400e" fillOpacity="0.3"/>
      <path d="M15 11c3 0 4 1.5 4 3s-1 3-4 3"/>
      <path d="M8 6c.3-1-.3-2 0-3" opacity="0.4"/>
      <path d="M11 6c.3-1-.3-2 0-3" opacity="0.4"/>
    </svg>
  )
}
