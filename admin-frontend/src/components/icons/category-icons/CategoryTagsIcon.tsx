import { IconProps } from '../types'

export function CategoryTagsIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M2 6a1 1 0 0 1 1-1h6l9 9a1 1 0 0 1 0 1.41l-5.59 5.59a1 1 0 0 1-1.41 0L2 12V6z"/>
      <circle cx="6" cy="9" r="1" fill="currentColor"/>
      <path d="M7 3h6l9 9a1 1 0 0 1 0 1.41" opacity="0.5"/>
    </svg>
  )
}
