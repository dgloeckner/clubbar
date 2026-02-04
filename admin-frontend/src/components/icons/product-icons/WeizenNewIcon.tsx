import { IconProps } from '../types'

export function WeizenNewIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M10 22c-1.2 0-1.5-.5-1.5-1l-1-5c0-1.5.5-3 1.5-4.5.7-1 .7-2 0-3L9 7.5V7h6v.5l0 1c-.7 1-.7 2 0 3 1 1.5 1.5 3 1.5 4.5l-1 5c0 .5-.3 1-1.5 1z" fill="#f59e0b" fillOpacity="0.3"/>
      <ellipse cx="12" cy="22" rx="2" ry="0.7"/>
      <ellipse cx="12" cy="6.5" rx="3.8" ry="2.2" fill="#fef3c7"/>
      <ellipse cx="12" cy="5" rx="3" ry="2" fill="white"/>
    </svg>
  )
}
