import { IconProps } from '../types'

export function SaunaTowelIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <circle cx="12" cy="12" r="10" fill="#0d9488" stroke="#0f766e"/>
      <circle cx="12" cy="12" r="8" fill="none" stroke="#5eead4" strokeWidth="0.5"/>
      <rect x="5" y="9" width="14" height="8" rx="1.5" fill="#ecfdf5" fillOpacity="0.25" stroke="#ecfdf5" strokeWidth="1"/>
      <ellipse cx="18" cy="13" rx="1" ry="3.5" fill="#0d9488" stroke="#ecfdf5" strokeWidth="0.8"/>
      <path d="M18 10c-1 .5-1 1.5-.5 2.5" stroke="#ecfdf5" strokeWidth="0.5" fill="none"/>
      <line x1="6" y1="11.5" x2="17" y2="11.5" stroke="#5eead4" strokeWidth="0.5" opacity="0.6"/>
      <line x1="6" y1="14.5" x2="17" y2="14.5" stroke="#5eead4" strokeWidth="0.5" opacity="0.6"/>
    </svg>
  )
}
