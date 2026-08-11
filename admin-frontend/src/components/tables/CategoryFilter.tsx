/**
 * Category Filter Component
 * Dropdown for filtering products by category
 * Based on SortDropdown styling
 *
 * Props:
 * - categories: Array of available categories
 * - value: Selected category ID (null for "all")
 * - onChange: Callback when selection changes (passes category ID or null)
 * - testId: Base test ID for elements
 */

import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { tableColors } from '../../styles/tableTokens'

interface Category {
  id: string
  names: { [lang: string]: string }
  is_active: boolean
}

interface CategoryFilterProps {
  categories: Category[]
  value: string | null
  onChange: (categoryId: string | null) => void
  testId?: string
}

function ChevronDownIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="6 9 12 15 18 9" />
    </svg>
  )
}

export function CategoryFilter({
  categories,
  value,
  onChange,
  testId = 'category-filter',
}: CategoryFilterProps) {
  const { t, i18n } = useTranslation()
  const [isOpen, setIsOpen] = useState(false)
  const dropdownRef = useRef<HTMLDivElement>(null)
  const lang = i18n.language

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const selectedLabel =
    value && categories.length > 0
      ? categories.find((cat) => cat.id === value)?.names?.[lang] ||
        categories.find((cat) => cat.id === value)?.names?.de ||
        categories.find((cat) => cat.id === value)?.names?.en ||
        t('common.category')
      : t('products.allCategories')

  return (
    <div style={{ position: 'relative' }} ref={dropdownRef}>
      <button
        data-testid={`${testId}-trigger`}
        onClick={() => setIsOpen(!isOpen)}
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 8,
          padding: '8px 12px',
          background: theme.colors.bg.input,
          border: `1px solid ${isOpen ? theme.activeTint.primaryBorder : theme.colors.border.input}`,
          borderRadius: 8,
          color: tableColors.cellText,
          fontSize: 14,
          cursor: 'pointer',
          transition: 'all 0.15s',
        }}
      >
        <span>{selectedLabel}</span>
        <span
          style={{
            color: theme.colors.text.muted,
            transform: isOpen ? 'rotate(180deg)' : '',
            transition: 'transform 0.2s',
            display: 'flex',
          }}
        >
          <ChevronDownIcon />
        </span>
      </button>

      {isOpen && (
        <div
          data-testid={`${testId}-dropdown`}
          style={{
            position: 'absolute',
            top: '100%',
            right: 0,
            marginTop: 8,
            minWidth: 200,
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.input}`,
            borderRadius: 12,
            boxShadow: '0 10px 40px rgba(0,0,0,0.4)',
            zIndex: 1000,
          }}
        >
          <div style={{ padding: 6 }}>
            {/* All Categories option */}
            <button
              data-testid={`${testId}-option-all`}
              onClick={() => {
                onChange(null)
                setIsOpen(false)
              }}
              style={{
                width: '100%',
                display: 'flex',
                alignItems: 'center',
                gap: 10,
                padding: '10px 12px',
                background: value === null ? theme.activeTint.primary : 'transparent',
                border: 'none',
                borderRadius: 8,
                color: value === null ? theme.colors.semantic.primary : tableColors.cellText,
                fontSize: 14,
                cursor: 'pointer',
                textAlign: 'left',
                transition: 'all 0.15s',
              }}
            >
              <span style={{ color: value === null ? theme.colors.semantic.primary : theme.colors.text.muted, fontWeight: 500 }}>●</span>
              <span style={{ flex: 1 }}>{t('products.allCategories')}</span>
            </button>

            {/* Category options */}
            {categories.map((category) => (
              <button
                key={category.id}
                data-testid={`${testId}-option-${category.id}`}
                onClick={() => {
                  onChange(category.id)
                  setIsOpen(false)
                }}
                style={{
                  width: '100%',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 10,
                  padding: '10px 12px',
                  background: value === category.id ? theme.activeTint.primary : 'transparent',
                  border: 'none',
                  borderRadius: 8,
                  color: value === category.id ? theme.colors.semantic.primary : tableColors.cellText,
                  fontSize: 14,
                  cursor: 'pointer',
                  textAlign: 'left',
                  transition: 'all 0.15s',
                }}
              >
                <span style={{ color: value === category.id ? theme.colors.semantic.primary : theme.colors.text.muted, fontWeight: 500 }}>●</span>
                <span style={{ flex: 1 }}>{category.names[lang] || category.names.de || category.names.en || t('common.name')}</span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
