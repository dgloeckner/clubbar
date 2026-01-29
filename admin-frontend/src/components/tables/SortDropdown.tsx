/**
 * Sort Dropdown Component
 * Dropdown for selecting sort options with direction indicators
 * Based on prototypes/Pagination.jsx SortDropdown
 *
 * Props:
 * - options: Array of { value: string, label: string, direction: 'asc' | 'desc' }
 * - value: Currently selected option value
 * - onChange: Callback when selection changes
 * - label: Placeholder label
 * - testId: Base test ID for elements
 */

import { useEffect, useRef, useState } from 'react'

interface SortOption {
  value: string
  label: string
  direction: 'asc' | 'desc'
}

interface SortDropdownProps {
  options: SortOption[]
  value: string
  onChange: (value: string) => void
  label?: string
  testId?: string
}

function ArrowUpIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <line x1="12" y1="19" x2="12" y2="5" />
      <polyline points="5 12 12 5 19 12" />
    </svg>
  )
}

function ArrowDownIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <line x1="12" y1="5" x2="12" y2="19" />
      <polyline points="19 12 12 19 5 12" />
    </svg>
  )
}

function ChevronDownIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="6 9 12 15 18 9" />
    </svg>
  )
}

export function SortDropdown({
  options,
  value,
  onChange,
  label = 'Sortieren',
  testId = 'sort-dropdown',
}: SortDropdownProps) {
  const [isOpen, setIsOpen] = useState(false)
  const dropdownRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setIsOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const selectedOption = options.find((opt) => opt.value === value)

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
          background: '#0d1829',
          border: `1px solid ${isOpen ? 'rgba(59,130,246,0.5)' : '#2d3748'}`,
          borderRadius: 8,
          color: '#e2e8f0',
          fontSize: 14,
          cursor: 'pointer',
          transition: 'all 0.15s',
        }}
      >
        {selectedOption?.direction === 'asc' ? <ArrowUpIcon /> : <ArrowDownIcon />}
        <span>{selectedOption?.label || label}</span>
        <span
          style={{
            color: '#64748b',
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
            background: '#1a2744',
            border: '1px solid #2d3748',
            borderRadius: 12,
            boxShadow: '0 10px 40px rgba(0,0,0,0.4)',
            zIndex: 1000,
          }}
        >
          <div style={{ padding: 6 }}>
            {options.map((option) => (
              <button
                key={option.value}
                data-testid={`${testId}-option-${option.value}`}
                onClick={() => {
                  onChange(option.value)
                  setIsOpen(false)
                }}
                style={{
                  width: '100%',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 10,
                  padding: '10px 12px',
                  background: value === option.value ? 'rgba(59,130,246,0.15)' : 'transparent',
                  border: 'none',
                  borderRadius: 8,
                  color: value === option.value ? '#3b82f6' : '#e2e8f0',
                  fontSize: 14,
                  cursor: 'pointer',
                  textAlign: 'left',
                  transition: 'all 0.15s',
                }}
              >
                <span style={{ color: value === option.value ? '#3b82f6' : '#64748b' }}>
                  {option.direction === 'asc' ? <ArrowUpIcon /> : <ArrowDownIcon />}
                </span>
                <span style={{ flex: 1 }}>{option.label}</span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
