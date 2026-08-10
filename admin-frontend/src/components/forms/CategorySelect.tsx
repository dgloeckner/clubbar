/**
 * CategorySelect Component - Custom dropdown for category selection
 *
 * Allows selection of product categories with styling matching IconSelect.
 * Displays selected category name in trigger button.
 *
 * Implements E2E Testing Pattern 005: Using Test IDs
 *
 * Usage:
 *   <CategorySelect
 *     categories={categories}
 *     value={selectedCategoryId}
 *     onChange={setSelectedCategoryId}
 *     testId="products-form-category-select"
 *     label="Category *"
 *     required
 *   />
 */

import { useState, useRef, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { tableColors } from '../../styles/tableTokens'

interface Category {
  id: string
  names: { [lang: string]: string }
  is_active: boolean
}

interface CategorySelectProps {
  categories: Category[]
  value: string
  onChange: (categoryId: string) => void
  testId: string
  label?: string
  required?: boolean
}

export function CategorySelect({
  categories,
  value,
  onChange,
  testId,
  label,
  required = false,
}: CategorySelectProps) {
  const { t } = useTranslation()
  const [isOpen, setIsOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

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

  const handleSelect = (categoryId: string) => {
    onChange(categoryId)
    setIsOpen(false)
  }

  const selectedCategory = categories.find((cat) => cat.id === value)
  const selectedLabel =
    selectedCategory?.names?.de || selectedCategory?.names?.en || t('products.selectCategory')

  return (
    <div ref={containerRef} style={{ position: 'relative', marginBottom: '16px' }}>
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
          justifyContent: 'space-between',
        }}
      >
        <span>{selectedLabel}</span>
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
          {/* Category options */}
          {categories.map((category) => (
            <button
              key={category.id}
              type="button"
              data-testid={`${testId}-option-${category.id}`}
              onClick={() => handleSelect(category.id)}
              style={{
                width: '100%',
                padding: '12px',
                border: 'none',
                backgroundColor: value === category.id ? theme.colors.border.light : 'transparent',
                color: tableColors.cellText,
                fontSize: '14px',
                cursor: 'pointer',
                textAlign: 'left',
                transition: 'background-color 0.2s',
              }}
              onMouseEnter={(e) => {
                if (value !== category.id) (e.target as HTMLElement).style.backgroundColor = theme.colors.border.light
              }}
              onMouseLeave={(e) => {
                if (value !== category.id) (e.target as HTMLElement).style.backgroundColor = 'transparent'
              }}
            >
              {category.names.de || category.names.en || 'Unnamed'}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
