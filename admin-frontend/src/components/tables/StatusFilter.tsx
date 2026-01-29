/**
 * Status Filter Component
 * Dropdown for filtering members by status (all/active/inactive)
 * Based on CategoryFilter styling and interaction pattern
 *
 * Props:
 * - value: Selected status ('all' | 'active' | 'inactive')
 * - onChange: Callback when selection changes
 * - testId: Base test ID for elements
 */

import { useEffect, useRef, useState } from 'react'

interface StatusFilterProps {
  value: 'all' | 'active' | 'inactive'
  onChange: (status: 'all' | 'active' | 'inactive') => void
  testId?: string
}

function ChevronDownIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="6 9 12 15 18 9" />
    </svg>
  )
}

export function StatusFilter({
  value,
  onChange,
  testId = 'status-filter',
}: StatusFilterProps) {
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

  const statusLabels: Record<'all' | 'active' | 'inactive', string> = {
    all: 'All Members',
    active: 'Active Only',
    inactive: 'Inactive Only',
  }

  const selectedLabel = statusLabels[value]

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
        <span>{selectedLabel}</span>
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
            {/* All option */}
            <button
              data-testid={`${testId}-option-all`}
              onClick={() => {
                onChange('all')
                setIsOpen(false)
              }}
              style={{
                width: '100%',
                display: 'flex',
                alignItems: 'center',
                gap: 10,
                padding: '10px 12px',
                background: value === 'all' ? 'rgba(59,130,246,0.15)' : 'transparent',
                border: 'none',
                borderRadius: 8,
                color: value === 'all' ? '#3b82f6' : '#e2e8f0',
                fontSize: 14,
                cursor: 'pointer',
                textAlign: 'left',
                transition: 'all 0.15s',
              }}
            >
              <span style={{ color: value === 'all' ? '#3b82f6' : '#64748b', fontWeight: 500 }}>●</span>
              <span style={{ flex: 1 }}>All Members</span>
            </button>

            {/* Active option */}
            <button
              data-testid={`${testId}-option-active`}
              onClick={() => {
                onChange('active')
                setIsOpen(false)
              }}
              style={{
                width: '100%',
                display: 'flex',
                alignItems: 'center',
                gap: 10,
                padding: '10px 12px',
                background: value === 'active' ? 'rgba(59,130,246,0.15)' : 'transparent',
                border: 'none',
                borderRadius: 8,
                color: value === 'active' ? '#3b82f6' : '#e2e8f0',
                fontSize: 14,
                cursor: 'pointer',
                textAlign: 'left',
                transition: 'all 0.15s',
              }}
            >
              <span style={{ color: value === 'active' ? '#3b82f6' : '#64748b', fontWeight: 500 }}>●</span>
              <span style={{ flex: 1 }}>Active Only</span>
            </button>

            {/* Inactive option */}
            <button
              data-testid={`${testId}-option-inactive`}
              onClick={() => {
                onChange('inactive')
                setIsOpen(false)
              }}
              style={{
                width: '100%',
                display: 'flex',
                alignItems: 'center',
                gap: 10,
                padding: '10px 12px',
                background: value === 'inactive' ? 'rgba(59,130,246,0.15)' : 'transparent',
                border: 'none',
                borderRadius: 8,
                color: value === 'inactive' ? '#3b82f6' : '#e2e8f0',
                fontSize: 14,
                cursor: 'pointer',
                textAlign: 'left',
                transition: 'all 0.15s',
              }}
            >
              <span style={{ color: value === 'inactive' ? '#3b82f6' : '#64748b', fontWeight: 500 }}>●</span>
              <span style={{ flex: 1 }}>Inactive Only</span>
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
