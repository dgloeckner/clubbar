import { IconProps } from '../types'

export function SaunaShowerIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <circle cx="12" cy="12" r="10" fill="#2563eb" stroke="#1d4ed8"/>
      <circle cx="12" cy="12" r="8" fill="none" stroke="#93c5fd" strokeWidth="0.5"/>
      <path d="M8 5v3h5" stroke="#dbeafe" strokeWidth="1.2"/>
      <ellipse cx="14" cy="8" rx="2.5" ry="1" fill="#dbeafe" fillOpacity="0.3" stroke="#dbeafe" strokeWidth="0.8"/>
      <line x1="12" y1="10" x2="12" y2="13" stroke="#dbeafe" strokeWidth="0.8" opacity="0.8"/>
      <line x1="14" y1="10" x2="14" y2="14" stroke="#dbeafe" strokeWidth="0.8" opacity="0.7"/>
      <line x1="16" y1="10" x2="15.5" y2="12" stroke="#dbeafe" strokeWidth="0.8" opacity="0.6"/>
      <line x1="10" y1="9.5" x2="10.5" y2="11.5" stroke="#dbeafe" strokeWidth="0.8" opacity="0.5"/>
      <circle cx="11" cy="16" r="0.6" fill="#dbeafe" opacity="0.5"/>
      <circle cx="14" cy="17" r="0.5" fill="#dbeafe" opacity="0.4"/>
      <circle cx="9" cy="18" r="0.4" fill="#dbeafe" opacity="0.3"/>
      <circle cx="15.5" cy="15.5" r="0.4" fill="#dbeafe" opacity="0.4"/>
    </svg>
  )
}
