import { IconProps } from '../types'

export function LemonadeIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M13,43L11,14L37,14L35,43Z" fill="#FEF08A" stroke="#713F12" strokeWidth="2.5" strokeLinejoin="round"/>
      <line x1="10" y1="14" x2="38" y2="14" stroke="#713F12" strokeWidth="2.5" strokeLinecap="round"/>
      <line x1="12" y1="43" x2="36" y2="43" stroke="#713F12" strokeWidth="2.5" strokeLinecap="round"/>
      <circle cx="37" cy="12" r="7" fill="#FDE047" stroke="#713F12" strokeWidth="2"/>
      <circle cx="37" cy="12" r="4" fill="#FEF9C3" stroke="#713F12" strokeWidth="1"/>
      <line x1="37" y1="5" x2="37" y2="19" stroke="#713F12" strokeWidth="1"/>
      <line x1="30" y1="12" x2="44" y2="12" stroke="#713F12" strokeWidth="1"/>
      <line x1="26" y1="5" x2="22" y2="43" stroke="#F97316" strokeWidth="2.5" strokeLinecap="round"/>
      <circle cx="17" cy="28" r="1.5" fill="white" opacity=".7"/>
    </svg>
  )
}
