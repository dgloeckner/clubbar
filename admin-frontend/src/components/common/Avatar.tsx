/**
 * Avatar Component
 * Displays user avatar with gradient background and initials
 */

import { theme } from '../../styles/design-system'

export interface AvatarProps {
  name: string
  variant?: 'blue' | 'green' | 'orange' | 'pink' | 'gray'
  size?: 'sm' | 'md' | 'lg'
  inactive?: boolean
  testId?: string
}

export function Avatar({ name, variant = 'blue', size = 'md', inactive = false, testId }: AvatarProps) {
  // Extract initials from name
  const initials = name
    .split(' ')
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('')

  const gradient = theme.avatars.gradients[variant]
  const sizePixels = theme.avatars.sizes[size]

  return (
    <div
      data-testid={testId}
      style={{
        width: sizePixels,
        height: sizePixels,
        borderRadius: theme.borderRadius.full,
        background: gradient,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        color: 'white',
        fontSize: size === 'sm' ? theme.typography.fontSize.sm : size === 'md' ? theme.typography.fontSize.base : theme.typography.fontSize.lg,
        fontWeight: theme.typography.fontWeight.semibold,
        flexShrink: 0,
        opacity: inactive ? 0.5 : 1,
        filter: inactive ? 'grayscale(100%)' : 'none',
        transition: `all ${theme.transitions.default}`,
      }}
    >
      {initials}
    </div>
  )
}
