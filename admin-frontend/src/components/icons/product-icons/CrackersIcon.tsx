import { IconProps } from '../types'

export function CrackersIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" {...props}>
      <rect x="16" y="8" width="22" height="22" rx="3" fill="#FDE68A" stroke="#92400E" strokeWidth="2.5" transform="rotate(12 27 19)"/>
      <circle cx="23" cy="14" r="1.3" fill="#92400E" opacity=".4" transform="rotate(12 27 19)"/>
      <circle cx="29" cy="14" r="1.3" fill="#92400E" opacity=".4" transform="rotate(12 27 19)"/>
      <circle cx="23" cy="20" r="1.3" fill="#92400E" opacity=".4" transform="rotate(12 27 19)"/>
      <circle cx="29" cy="20" r="1.3" fill="#92400E" opacity=".4" transform="rotate(12 27 19)"/>
      <rect x="7" y="22" width="24" height="24" rx="3" fill="#FBBF24" stroke="#92400E" strokeWidth="2.5"/>
      <line x1="19" y1="22" x2="19" y2="46" stroke="#92400E" strokeWidth="1.5" opacity=".4"/>
      <line x1="7" y1="34" x2="31" y2="34" stroke="#92400E" strokeWidth="1.5" opacity=".4"/>
      <circle cx="13" cy="28" r="1.3" fill="#92400E" opacity=".4"/>
      <circle cx="25" cy="28" r="1.3" fill="#92400E" opacity=".4"/>
      <circle cx="13" cy="40" r="1.3" fill="#92400E" opacity=".4"/>
      <circle cx="25" cy="40" r="1.3" fill="#92400E" opacity=".4"/>
    </svg>
  )
}
