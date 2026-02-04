import { IconProps } from '../types'

export function SaunaWellnessIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <circle cx="12" cy="12" r="10" fill="#e11d48" stroke="#be123c"/>
      <circle cx="12" cy="12" r="8" fill="none" stroke="#fda4af" strokeWidth="0.5"/>
      <path d="M12 18l-5-5c-1.5-1.5-2-3.5-.5-5.5 1-1.3 3-1.5 4.5-.5l1 1 1-1c1.5-1 3.5-.8 4.5.5 1.5 2 1 4-.5 5.5z" fill="#ffe4e6" fillOpacity="0.3" stroke="#ffe4e6" strokeWidth="1"/>
      <path d="M6 12h3l1-2 1.5 4 1.5-4 1 2h4" stroke="#ffe4e6" strokeWidth="1" fill="none"/>
    </svg>
  )
}
