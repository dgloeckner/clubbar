/**
 * IconSelect Component - Custom dropdown with icon preview
 *
 * Allows selection of icons from a list with visual preview.
 * Displays selected icon in trigger button.
 *
 * Implements E2E Testing Pattern 005: Using Test IDs
 * Implements E2E Testing Pattern 006: Page Object Model
 *
 * Usage:
 *   <IconSelect
 *     value={selectedIcon}
 *     onChange={setSelectedIcon}
 *     iconType="product"
 *     testId="products-form-icon-select"
 *     label="Icon (optional)"
 *   />
 */

import { useState, useRef, useEffect } from 'react'
import { getProductIcon, getCategoryIcon, PRODUCT_ICON_NAMES, CATEGORY_ICON_NAMES } from '../icons/IconRegistry'

interface IconSelectProps {
  value: string | null
  onChange: (iconName: string | null) => void
  iconType: 'product' | 'category'
  testId: string
  label?: string
  required?: boolean
}

export function IconSelect({
  value,
  onChange,
  iconType,
  testId,
  label,
  required = false,
}: IconSelectProps) {
  const [isOpen, setIsOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  // Get available icon names based on type
  const iconNames = iconType === 'product' ? PRODUCT_ICON_NAMES : CATEGORY_ICON_NAMES

  // Get icon component function
  const getIcon = iconType === 'product' ? getProductIcon : getCategoryIcon

  // Close dropdown when clicking outside
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const handleSelect = (iconName: string | null) => {
    onChange(iconName)
    setIsOpen(false)
  }

  const SelectedIcon = getIcon(value)

  return (
    <div ref={containerRef} style={{ position: 'relative', marginBottom: '12px' }}>
      {label && (
        <label style={{ display: 'block', marginBottom: '6px', fontSize: '14px', fontWeight: '500', color: '#e2e8f0' }}>
          {label}
          {required && <span style={{ color: '#ef4444', marginLeft: '4px' }}>*</span>}
        </label>
      )}

      {/* Trigger Button */}
      <button
        type="button"
        data-testid={`${testId}-trigger`}
        onClick={() => setIsOpen(!isOpen)}
        style={{
          width: '100%',
          padding: '10px 12px',
          border: '1px solid #4b5563',
          borderRadius: '6px',
          backgroundColor: '#1e293b',
          color: '#e2e8f0',
          fontSize: '14px',
          cursor: 'pointer',
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
          justifyContent: 'space-between',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          <SelectedIcon size={18} />
          <span>{value || 'Select icon...'}</span>
        </div>
        <svg
          width="16"
          height="16"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          style={{ transform: isOpen ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform 0.2s' }}
        >
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>

      {/* Dropdown Container */}
      {isOpen && (
        <div
          data-testid={`${testId}-dropdown`}
          style={{
            position: 'absolute',
            top: '100%',
            left: 0,
            right: 0,
            marginTop: '4px',
            backgroundColor: '#1e293b',
            border: '1px solid #4b5563',
            borderRadius: '6px',
            zIndex: 1000,
            boxShadow: '0 10px 15px -3px rgba(0, 0, 0, 0.3)',
            maxHeight: '300px',
            overflowY: 'auto',
          }}
        >
          {/* Clear selection option */}
          <button
            type="button"
            data-testid={`${testId}-option-clear`}
            onClick={() => handleSelect(null)}
            style={{
              width: '100%',
              padding: '12px',
              border: 'none',
              backgroundColor: value === null ? '#334155' : 'transparent',
              color: '#e2e8f0',
              fontSize: '14px',
              cursor: 'pointer',
              textAlign: 'left',
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
              transition: 'background-color 0.2s',
            }}
            onMouseEnter={(e) => {
              if (value !== null) (e.target as HTMLElement).style.backgroundColor = '#334155'
            }}
            onMouseLeave={(e) => {
              if (value !== null) (e.target as HTMLElement).style.backgroundColor = 'transparent'
            }}
          >
            <span style={{ width: '18px', height: '18px' }} />
            <span>(None - use default)</span>
          </button>

          {/* Icon options */}
          {iconNames.map((iconName: string) => {
            const Icon = getIcon(iconName)
            return (
              <button
                key={iconName}
                type="button"
                data-testid={`${testId}-option-${iconName}`}
                onClick={() => handleSelect(iconName)}
                style={{
                  width: '100%',
                  padding: '12px',
                  border: 'none',
                  backgroundColor: value === iconName ? '#334155' : 'transparent',
                  color: '#e2e8f0',
                  fontSize: '14px',
                  cursor: 'pointer',
                  textAlign: 'left',
                  display: 'flex',
                  alignItems: 'center',
                  gap: '8px',
                  transition: 'background-color 0.2s',
                }}
                onMouseEnter={(e) => {
                  if (value !== iconName) (e.target as HTMLElement).style.backgroundColor = '#334155'
                }}
                onMouseLeave={(e) => {
                  if (value !== iconName) (e.target as HTMLElement).style.backgroundColor = 'transparent'
                }}
              >
                <Icon size={18} />
                <span>{iconName}</span>
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}
