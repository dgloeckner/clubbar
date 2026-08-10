// admin-frontend/src/components/layout/MobileToolbar.tsx
import { useState, useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { tableColors } from '../../styles/tableTokens'

interface SortOption {
  value: string
  label: string
  direction: 'asc' | 'desc'
}

interface MobileToolbarProps {
  search?: {
    value: string
    onChange: (value: string) => void
    placeholder?: string
    testId?: string
  }
  sort?: {
    options: SortOption[]
    value: string
    onChange: (value: string) => void
    testId?: string
  }
  filterCount?: number
  onFilterToggle?: () => void
  showFilters?: boolean
  filterContent?: React.ReactNode
  onCreate?: () => void
  createTestId?: string
  testId?: string
}

function ChevronDownIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="6 9 12 15 18 9" />
    </svg>
  )
}

function ArrowUpIcon() {
  return (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <line x1="12" y1="19" x2="12" y2="5" />
      <polyline points="5 12 12 5 19 12" />
    </svg>
  )
}

function ArrowDownIcon() {
  return (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <line x1="12" y1="5" x2="12" y2="19" />
      <polyline points="19 12 12 19 5 12" />
    </svg>
  )
}

function FilterIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
    </svg>
  )
}

function PlusIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
      <line x1="12" y1="5" x2="12" y2="19" />
      <line x1="5" y1="12" x2="19" y2="12" />
    </svg>
  )
}

export function MobileToolbar({
  search,
  sort,
  filterCount = 0,
  onFilterToggle,
  showFilters = false,
  filterContent,
  onCreate,
  createTestId,
  testId = 'mobile-toolbar',
}: MobileToolbarProps) {
  const { t } = useTranslation()
  const [showSort, setShowSort] = useState(false)
  const sortRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (sortRef.current && !sortRef.current.contains(e.target as Node)) {
        setShowSort(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const selectedSort = sort?.options.find((o) => o.value === sort.value)

  const buttonStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    gap: '4px',
    padding: '8px 10px',
    background: 'rgba(255,255,255,0.04)',
    border: '1px solid rgba(255,255,255,0.08)',
    borderRadius: '7px',
    color: theme.colors.text.primary,
    fontSize: '13px',
    cursor: 'pointer',
    whiteSpace: 'nowrap',
    flexShrink: 0,
  }

  return (
    <div data-testid={testId} style={{ marginBottom: '12px' }}>
      {/* Top row: search + sort + filter */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
          padding: '10px 14px',
          background: theme.mobileCard.bg,
          borderRadius: showFilters ? '10px 10px 0 0' : '10px',
          border: `1px solid ${theme.mobileCard.border}`,
          borderBottom: showFilters ? '1px solid rgba(255,255,255,0.04)' : undefined,
        }}
      >
        {/* Search */}
        {search && (
          <div style={{ position: 'relative', flex: 1, minWidth: 0 }}>
            <svg
              width="14" height="14" viewBox="0 0 16 16" fill="none"
              style={{ position: 'absolute', left: '8px', top: '50%', transform: 'translateY(-50%)', opacity: 0.35 }}
            >
              <circle cx="7" cy="7" r="5.5" stroke="white" strokeWidth="1.5" />
              <path d="M11 11l3.5 3.5" stroke="white" strokeWidth="1.5" strokeLinecap="round" />
            </svg>
            <input
              type="text"
              value={search.value}
              onChange={(e) => search.onChange(e.target.value)}
              placeholder={search.placeholder || t('common.searchPlaceholder')}
              data-testid={search.testId || `${testId}-search`}
              style={{
                width: '100%',
                padding: '8px 10px 8px 28px',
                borderRadius: '7px',
                border: '1px solid rgba(255,255,255,0.08)',
                background: 'rgba(255,255,255,0.04)',
                color: tableColors.cellText,
                fontSize: '13px',
                outline: 'none',
              }}
            />
          </div>
        )}

        {/* Sort dropdown */}
        {sort && (
          <div ref={sortRef} style={{ position: 'relative' }}>
            <button
              data-testid={sort.testId || `${testId}-sort`}
              onClick={() => setShowSort(!showSort)}
              style={buttonStyle}
            >
              {selectedSort?.direction === 'asc' ? <ArrowUpIcon /> : <ArrowDownIcon />}
              <ChevronDownIcon />
            </button>

            {showSort && (
              <div
                data-testid={`${testId}-sort-dropdown`}
                style={{
                  position: 'absolute',
                  top: '100%',
                  right: 0,
                  marginTop: '6px',
                  minWidth: '180px',
                  background: theme.colors.bg.card,
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: theme.borderRadius.md,
                  boxShadow: '0 10px 40px rgba(0,0,0,0.4)',
                  zIndex: 1000,
                  padding: '6px',
                }}
              >
                {sort.options.map((option) => (
                  <button
                    key={option.value}
                    data-testid={`${testId}-sort-option-${option.value}`}
                    onClick={() => {
                      sort.onChange(option.value)
                      setShowSort(false)
                    }}
                    style={{
                      width: '100%',
                      display: 'flex',
                      alignItems: 'center',
                      gap: '8px',
                      padding: '10px 12px',
                      background: sort.value === option.value ? 'rgba(59,130,246,0.15)' : 'transparent',
                      border: 'none',
                      borderRadius: '8px',
                      color: sort.value === option.value ? theme.colors.semantic.primary : theme.colors.text.primary,
                      fontSize: '13px',
                      cursor: 'pointer',
                      textAlign: 'left',
                    }}
                  >
                    <span style={{ color: sort.value === option.value ? theme.colors.semantic.primary : theme.colors.text.muted }}>
                      {option.direction === 'asc' ? <ArrowUpIcon /> : <ArrowDownIcon />}
                    </span>
                    <span>{option.label}</span>
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Filter toggle */}
        {onFilterToggle && (
          <button
            data-testid={`${testId}-filter-toggle`}
            onClick={onFilterToggle}
            style={{
              ...buttonStyle,
              background: showFilters ? 'rgba(59,130,246,0.15)' : buttonStyle.background,
              borderColor: showFilters ? 'rgba(59,130,246,0.3)' : 'rgba(255,255,255,0.08)',
              color: showFilters ? theme.colors.semantic.primary : theme.colors.text.primary,
            }}
          >
            <FilterIcon />
            {filterCount > 0 && (
              <span
                data-testid={`${testId}-filter-badge`}
                style={{
                  background: theme.colors.semantic.primary,
                  color: 'white',
                  borderRadius: '9999px',
                  padding: '0 6px',
                  fontSize: '11px',
                  fontWeight: 600,
                  lineHeight: '18px',
                  minWidth: '18px',
                  textAlign: 'center',
                }}
              >
                {filterCount}
              </span>
            )}
          </button>
        )}

        {/* Create button */}
        {onCreate && (
          <button
            data-testid={createTestId || `${testId}-create`}
            onClick={onCreate}
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              padding: '8px 10px',
              background: theme.colors.semantic.primary,
              border: 'none',
              borderRadius: '7px',
              color: 'white',
              cursor: 'pointer',
              flexShrink: 0,
            }}
          >
            <PlusIcon />
          </button>
        )}
      </div>

      {/* Expanded filters */}
      {showFilters && filterContent && (
        <div
          data-testid={`${testId}-filters`}
          style={{
            padding: '12px 14px',
            background: theme.mobileCard.bg,
            borderRadius: '0 0 10px 10px',
            border: `1px solid ${theme.mobileCard.border}`,
            borderTop: 'none',
            display: 'flex',
            flexDirection: 'column',
            gap: '10px',
          }}
        >
          {filterContent}
        </div>
      )}
    </div>
  )
}
