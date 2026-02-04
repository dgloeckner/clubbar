import { IconProps } from '../types'

export function SaunaWhiskIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <circle cx="12" cy="12" r="10" fill="#16a34a" stroke="#15803d"/>
      <circle cx="12" cy="12" r="8" fill="none" stroke="#86efac" strokeWidth="0.5"/>
      <rect x="10.5" y="15" width="3" height="5" rx="0.8" fill="#fef3c7" fillOpacity="0.3" stroke="#dcfce7" strokeWidth="0.8"/>
      <g stroke="#dcfce7" strokeWidth="0.8" fill="none">
        <path d="M12 15V7"/>
        <path d="M12 15L9 7.5"/>
        <path d="M12 15L15 7.5"/>
        <path d="M12 15L7.5 9"/>
        <path d="M12 15L16.5 9"/>
      </g>
      <g fill="#dcfce7" fillOpacity="0.6" stroke="none">
        <ellipse cx="12" cy="6.5" rx="1.2" ry="0.6"/>
        <ellipse cx="9" cy="7" rx="1" ry="0.5" transform="rotate(-15,9,7)"/>
        <ellipse cx="15" cy="7" rx="1" ry="0.5" transform="rotate(15,15,7)"/>
        <ellipse cx="7.5" cy="8.5" rx="1" ry="0.5" transform="rotate(-30,7.5,8.5)"/>
        <ellipse cx="16.5" cy="8.5" rx="1" ry="0.5" transform="rotate(30,16.5,8.5)"/>
      </g>
    </svg>
  )
}
