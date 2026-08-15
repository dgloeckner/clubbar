import { IconProps } from './index'

export function MailIcon({ size = 20, ...props }: IconProps) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      {...props}
    >
      {/* Envelope — the mail queue (#407) */}
      <rect x="2" y="4" width="20" height="16" rx="2" />
      <polyline points="2 7 12 14 22 7" />
    </svg>
  )
}
