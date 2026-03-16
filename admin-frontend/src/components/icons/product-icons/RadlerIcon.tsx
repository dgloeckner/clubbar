import { IconProps } from '../types'

export function RadlerIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M11,44L9,14L39,14L37,44Z" fill="#FEF08A" stroke="#92400E" strokeWidth="2.5" strokeLinejoin="round"/>
      <path d="M8,14Q11,5 15,9Q18,2 23,7Q28,2 31,9Q35,5 40,14Z" fill="white" stroke="#92400E" strokeWidth="2.5" strokeLinejoin="round"/>
      <line x1="9" y1="44" x2="39" y2="44" stroke="#92400E" strokeWidth="2.5" strokeLinecap="round"/>
      <circle cx="37" cy="12" r="7" fill="#FDE047" stroke="#92400E" strokeWidth="2"/>
      <circle cx="37" cy="12" r="4" fill="#FEF9C3" stroke="#92400E" strokeWidth="1"/>
      <line x1="37" y1="5" x2="37" y2="19" stroke="#92400E" strokeWidth="1"/>
      <line x1="30" y1="12" x2="44" y2="12" stroke="#92400E" strokeWidth="1"/>
      <circle cx="18" cy="28" r="1.2" fill="white" opacity=".6"/>
    </svg>
  )
}
