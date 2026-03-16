import { IconProps } from '../types'

export function FriesIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <path d="M12,28L14,44L34,44L36,28Z" fill="#EF4444" stroke="#991B1B" strokeWidth="2.5" strokeLinejoin="round"/>
      <path d="M14,44L13,32L19,32L18,44Z" fill="#F87171" opacity=".5"/>
      <rect x="14" y="10" width="4.5" height="20" rx="2.2" fill="#FDE68A" stroke="#92400E" strokeWidth="2" strokeLinejoin="round"/>
      <rect x="21" y="8" width="4.5" height="22" rx="2.2" fill="#FBBF24" stroke="#92400E" strokeWidth="2" strokeLinejoin="round"/>
      <rect x="28" y="10" width="4.5" height="20" rx="2.2" fill="#FDE68A" stroke="#92400E" strokeWidth="2" strokeLinejoin="round"/>
      <rect x="10" y="14" width="5" height="16" rx="2.5" fill="#FBBF24" stroke="#92400E" strokeWidth="2" strokeLinejoin="round"/>
      <rect x="33" y="13" width="5" height="17" rx="2.5" fill="#FBBF24" stroke="#92400E" strokeWidth="2" strokeLinejoin="round"/>
      <circle cx="16" cy="18" r="1" fill="white" opacity=".7"/>
      <circle cx="23" cy="14" r="1" fill="white" opacity=".7"/>
      <circle cx="30" cy="17" r="1" fill="white" opacity=".7"/>
    </svg>
  )
}
