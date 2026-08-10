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
import { useTranslation } from 'react-i18next'
import { getProductIcon, getCategoryIcon, PRODUCT_ICON_NAMES, CATEGORY_ICON_NAMES } from '../icons/IconRegistry'
import { theme } from '../../styles/design-system'
import { tableColors } from '../../styles/tableTokens'

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
  const { t } = useTranslation()
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
        <label style={{ display: 'block', marginBottom: '6px', fontSize: '14px', fontWeight: '500', color: tableColors.cellText }}>
          {label}
          {required && <span style={{ color: theme.colors.semantic.danger, marginLeft: '4px' }}>*</span>}
        </label>
      )}

      {/* Trigger Button */}
      <button
        type="button"
        data-testid={`${testId}-trigger`}
        // The selection, readable without parsing the label — that label is now
        // translated, and a test that matched its wording matched one language.
        data-value={value ?? ''}
        onClick={() => setIsOpen(!isOpen)}
        style={{
          width: '100%',
          padding: '10px 12px',
          border: `1px solid ${theme.colors.border.muted}`,
          borderRadius: '6px',
          backgroundColor: theme.colors.bg.inputAlt,
          color: tableColors.cellText,
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
          <span>{value || t('products.selectIcon')}</span>
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
            backgroundColor: theme.colors.bg.inputAlt,
            border: `1px solid ${theme.colors.border.muted}`,
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
              backgroundColor: value === null ? theme.colors.border.light : 'transparent',
              color: tableColors.cellText,
              fontSize: '14px',
              cursor: 'pointer',
              textAlign: 'left',
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
              transition: 'background-color 0.2s',
            }}
            onMouseEnter={(e) => {
              if (value !== null) (e.target as HTMLElement).style.backgroundColor = theme.colors.border.light
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
                  backgroundColor: value === iconName ? theme.colors.border.light : 'transparent',
                  color: tableColors.cellText,
                  fontSize: '14px',
                  cursor: 'pointer',
                  textAlign: 'left',
                  display: 'flex',
                  alignItems: 'center',
                  gap: '8px',
                  transition: 'background-color 0.2s',
                }}
                onMouseEnter={(e) => {
                  if (value !== iconName) (e.target as HTMLElement).style.backgroundColor = theme.colors.border.light
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
