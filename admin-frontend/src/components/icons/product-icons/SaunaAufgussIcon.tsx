import { IconProps } from '../types'

export function SaunaAufgussIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <circle cx="12" cy="12" r="10" fill="#92400e" stroke="#78350f"/>
      <circle cx="12" cy="12" r="8" fill="none" stroke="#d97706" strokeWidth="0.5"/>
      <path d="M7 11l1 7c0 1 1.5 1.5 4 1.5s4-.5 4-1.5l1-7z" fill="#fef3c7" fillOpacity="0.25" stroke="#fef3c7" strokeWidth="1"/>
      <path d="M7.5 13h9" stroke="#fef3c7" strokeWidth="0.5" opacity="0.6"/>
      <path d="M7.8 16h8.4" stroke="#fef3c7" strokeWidth="0.5" opacity="0.6"/>
      <path d="M9 11c0-2 1.5-3 3-3s3 1 3 3" fill="none" stroke="#fef3c7" strokeWidth="1"/>
      <line x1="15" y1="5" x2="18" y2="12" stroke="#fef3c7" strokeWidth="1.2"/>
      <ellipse cx="18.3" cy="12.5" rx="1.5" ry="1" fill="#fef3c7" fillOpacity="0.3" stroke="#fef3c7" strokeWidth="0.8"/>
    </svg>
  )
}
