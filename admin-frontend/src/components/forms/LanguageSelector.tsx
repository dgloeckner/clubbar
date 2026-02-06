/**
 * Language Selector Component
 * Dropdown for selecting preferred language (de/en)
 * Based on StatusFilter styling and interaction pattern
 *
 * Props:
 * - value: Selected language code ('de' | 'en')
 * - onChange: Callback when selection changes
 * - testId: Base test ID for elements
 * - required: Whether field is required (default true)
 */

import { useEffect, useRef, useState } from 'react'

interface LanguageSelectorProps {
  value: 'de' | 'en'
  onChange: (language: 'de' | 'en') => void
  testId?: string
  required?: boolean
}

function ChevronDownIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="6 9 12 15 18 9" />
    </svg>
  )
}

export function LanguageSelector({
  value,
  onChange,
  testId = 'language-selector',
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  required: _required = true,
}: LanguageSelectorProps) {
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

  const languageLabels: Record<'de' | 'en', string> = {
    de: 'Deutsch',
    en: 'English',
  }

  const selectedLabel = languageLabels[value]

  return (
    <div style={{ position: 'relative' }} ref={dropdownRef}>
      <button
        data-testid={`${testId}-trigger`}
        onClick={() => setIsOpen(!isOpen)}
        type="button"
        style={{
          width: '100%',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 8,
          padding: '12px 16px',
          background: '#0d1829',
          border: `1px solid ${isOpen ? 'rgba(59,130,246,0.5)' : '#2d3748'}`,
          borderRadius: 8,
          color: '#e2e8f0',
          fontSize: 14,
          cursor: 'pointer',
          transition: 'all 0.15s',
          fontFamily: 'inherit',
        }}
      >
        <span>{selectedLabel}</span>
        <span
          style={{
            color: '#64748b',
            transform: isOpen ? 'rotate(180deg)' : '',
            transition: 'transform 0.2s',
            display: 'flex',
            flexShrink: 0,
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
            left: 0,
            right: 0,
            marginTop: 8,
            background: '#1a2744',
            border: '1px solid #2d3748',
            borderRadius: 12,
            boxShadow: '0 10px 40px rgba(0,0,0,0.4)',
            zIndex: 1000,
          }}
        >
          <div style={{ padding: 6 }}>
            {/* Deutsch option */}
            <button
              data-testid={`${testId}-option-de`}
              onClick={() => {
                onChange('de')
                setIsOpen(false)
              }}
              type="button"
              style={{
                width: '100%',
                display: 'flex',
                alignItems: 'center',
                gap: 10,
                padding: '10px 12px',
                background: value === 'de' ? 'rgba(59,130,246,0.15)' : 'transparent',
                border: 'none',
                borderRadius: 8,
                color: value === 'de' ? '#3b82f6' : '#e2e8f0',
                fontSize: 14,
                cursor: 'pointer',
                textAlign: 'left',
                transition: 'all 0.15s',
              }}
            >
              <span style={{ color: value === 'de' ? '#3b82f6' : '#64748b', fontWeight: 500 }}>●</span>
              <span style={{ flex: 1 }}>Deutsch</span>
            </button>

            {/* English option */}
            <button
              data-testid={`${testId}-option-en`}
              onClick={() => {
                onChange('en')
                setIsOpen(false)
              }}
              type="button"
              style={{
                width: '100%',
                display: 'flex',
                alignItems: 'center',
                gap: 10,
                padding: '10px 12px',
                background: value === 'en' ? 'rgba(59,130,246,0.15)' : 'transparent',
                border: 'none',
                borderRadius: 8,
                color: value === 'en' ? '#3b82f6' : '#e2e8f0',
                fontSize: 14,
                cursor: 'pointer',
                textAlign: 'left',
                transition: 'all 0.15s',
              }}
            >
              <span style={{ color: value === 'en' ? '#3b82f6' : '#64748b', fontWeight: 500 }}>●</span>
              <span style={{ flex: 1 }}>English</span>
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
