import { IconProps } from '../types'

export function BeerAFIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M11,44L9,14L39,14L37,44Z" fill="#FEF9C3" stroke="#78350F" strokeWidth="2.5" strokeLinejoin="round"/>
      <path d="M8,14Q11,5 15,9Q18,2 23,7Q28,2 31,9Q35,5 40,14Z" fill="white" stroke="#78350F" strokeWidth="2.5" strokeLinejoin="round"/>
      <line x1="9" y1="44" x2="39" y2="44" stroke="#78350F" strokeWidth="2.5" strokeLinecap="round"/>
      <path d="M34,40Q40,34 40,28Q34,30 32,40Z" fill="#86EFAC" stroke="#166534" strokeWidth="1.5"/>
      <line x1="32" y1="40" x2="40" y2="28" stroke="#166534" strokeWidth="1.5" strokeLinecap="round"/>
      <circle cx="18" cy="28" r="1.2" fill="white" opacity=".6"/>
    </svg>
  )
}
