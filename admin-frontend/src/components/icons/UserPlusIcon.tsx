import { IconProps } from './types'

/**
 * UserPlusIcon — the self-registration inbox (#782).
 *
 * A person with a plus: somebody asking to join, which is what a pending
 * registration is. Deliberately not `UsersIcon` — that one is the roster of
 * people who already are members, and the whole point of this section is that
 * these are not members yet.
 */
export function UserPlusIcon({ size = 20, ...props }: IconProps) {
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
      <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
      <circle cx="8.5" cy="7" r="4" />
      <line x1="20" y1="8" x2="20" y2="14" />
      <line x1="23" y1="11" x2="17" y2="11" />
    </svg>
  )
}
