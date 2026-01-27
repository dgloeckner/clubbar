import { IconProps } from '../types'

export function CategoryFolderIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...props}>
      <path d="M2 4a1 1 0 0 1 1-1h4l2 2h10a1 1 0 0 1 1 1v3H2V4z"/>
      <path d="M2 9h20v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V9z"/>
      <path d="M6 13h4"/>
      <path d="M6 17h8"/>
    </svg>
  )
}
