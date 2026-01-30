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
          width: '32px',
          height: '32px',
          borderRadius: '8px',
          border: 'none',
          background: 'transparent',
          color: theme.colors.text.secondary,
          cursor: 'pointer',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          transition: `all ${theme.transitions.default}`,
        }}
        onMouseEnter={(e) => {
          e.currentTarget.style.background = 'rgba(71,85,105,0.3)'
          e.currentTarget.style.color = theme.colors.text.primary
        }}
        onMouseLeave={(e) => {
          e.currentTarget.style.background = 'transparent'
          e.currentTarget.style.color = theme.colors.text.secondary
        }}
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <circle cx="12" cy="12" r="1" />
          <circle cx="19" cy="12" r="1" />
          <circle cx="5" cy="12" r="1" />
        </svg>
      </button>

      {/* Dropdown Menu */}
      {isOpen && (
        <div
          style={{
            position: 'absolute',
            right: 0,
            top: '100%',
            marginTop: '4px',
            background: '#1a2744',
            border: '1px solid rgba(71,85,105,0.4)',
            borderRadius: '10px',
            boxShadow: '0 10px 40px rgba(0,0,0,0.4)',
            zIndex: 1000,
            minWidth: '180px',
            padding: '4px',
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
                gap: '10px',
                width: '100%',
                padding: '10px 12px',
                background: 'transparent',
                border: 'none',
                color: item.variant === 'danger' ? theme.colors.semantic.danger : theme.colors.text.primary,
                textAlign: 'left',
                cursor: 'pointer',
                fontSize: '13px',
                transition: `background 0.1s`,
                borderRadius: '6px',
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.background = item.variant === 'danger' ? 'rgba(239, 68, 68, 0.1)' : 'rgba(59, 130, 246, 0.1)'
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.background = 'transparent'
              }}
            >
              {item.icon && (
                <span
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    color: item.variant === 'danger' ? theme.colors.semantic.danger : theme.colors.text.secondary,
                    width: '14px',
                    height: '14px',
                  }}
                >
                  {item.icon}
                </span>
              )}
              {item.label}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
