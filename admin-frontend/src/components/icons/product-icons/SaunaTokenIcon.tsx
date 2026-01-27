import { IconProps } from '../types'

export function SaunaTokenIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <circle cx="12" cy="12" r="10" fill="#d97706" stroke="#b45309"/>
      <circle cx="12" cy="12" r="8" fill="none" stroke="#fbbf24" strokeWidth="0.5"/>
      <path d="M8 14q-1-2 0-4t-1-4" stroke="#fef3c7" strokeWidth="1.5"/>
      <path d="M12 15q-1-2 0-4t-1-4" stroke="#fef3c7" strokeWidth="1.5"/>
      <path d="M16 14q-1-2 0-4t-1-4" stroke="#fef3c7" strokeWidth="1.5"/>
    </svg>
  )
}
