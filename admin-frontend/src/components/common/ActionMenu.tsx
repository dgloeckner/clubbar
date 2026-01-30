/**
 * ActionMenu Component
 * Dropdown menu with action items
 */

import { ReactNode, useRef, useEffect, useState } from 'react'
import { theme } from '../../styles/design-system'

export interface ActionMenuItem {
  label: string
  icon?: ReactNode
  onClick: () => void
  variant?: 'default' | 'danger'
}

export interface ActionMenuProps {
  items: ActionMenuItem[]
  testId?: string
}

export function ActionMenu({ items, testId }: ActionMenuProps) {
  const [isOpen, setIsOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)

  // Close menu on outside click
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }

    if (isOpen) {
      document.addEventListener('mousedown', handleClickOutside)
      return () => {
        document.removeEventListener('mousedown', handleClickOutside)
      }
    }
  }, [isOpen])

  const handleItemClick = (onClick: () => void) => {
    onClick()
    setIsOpen(false)
  }

  return (
    <div
      ref={menuRef}
      style={{
        position: 'relative',
        display: 'inline-block',
      }}
      data-testid={testId}
    >
      {/* Menu Button */}
      <button
        onClick={() => setIsOpen(!isOpen)}
        style={{
          background: 'transparent',
          border: 'none',
          padding: theme.spacing.sm,
          cursor: 'pointer',
          color: theme.colors.text.secondary,
          fontSize: theme.typography.fontSize.lg,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          transition: `all ${theme.transitions.default}`,
        }}
        onMouseEnter={(e) => {
          e.currentTarget.style.color = theme.colors.text.primary
        }}
        onMouseLeave={(e) => {
          e.currentTarget.style.color = theme.colors.text.secondary
        }}
      >
        ⋮
      </button>

      {/* Dropdown Menu */}
      {isOpen && (
        <div
          style={{
            position: 'absolute',
            right: 0,
            top: '100%',
            marginTop: theme.spacing.sm,
            background: theme.colors.bg.secondary,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
            boxShadow: theme.shadows.lg,
            zIndex: 1000,
            minWidth: '160px',
            overflow: 'hidden',
          }}
        >
          {items.map((item, index) => (
            <button
              key={index}
              onClick={() => handleItemClick(item.onClick)}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: theme.spacing.md,
                width: '100%',
                padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                background: 'transparent',
                border: 'none',
                color: item.variant === 'danger' ? theme.colors.semantic.danger : theme.colors.text.primary,
                textAlign: 'left',
                cursor: 'pointer',
                fontSize: theme.typography.fontSize.sm,
                transition: `all ${theme.transitions.default}`,
                borderBottom: index < items.length - 1 ? `1px solid ${theme.colors.border.light}` : 'none',
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.background = theme.colors.bg.hover
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.background = 'transparent'
              }}
            >
              {item.icon && <span style={{ display: 'flex', alignItems: 'center' }}>{item.icon}</span>}
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
