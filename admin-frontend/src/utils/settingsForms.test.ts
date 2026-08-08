import { describe, expect, it } from 'vitest'
import {
  VALIDATION_INVALID_EMAIL,
  VALIDATION_REQUIRED,
  validateCreateAdminForm,
  validateCreateTerminalForm,
} from './settingsForms'

describe('validateCreateAdminForm', () => {
  it('accepts a complete form', () => {
    expect(validateCreateAdminForm({ email: 'treasurer@example.com', display_name: 'Treasurer' })).toEqual({})
  })

  it('rejects an empty form rather than letting the API do it', () => {
    expect(validateCreateAdminForm({ email: '', display_name: '' })).toEqual({
      email: VALIDATION_REQUIRED,
      display_name: VALIDATION_REQUIRED,
    })
  })

  it('treats whitespace as empty', () => {
    expect(validateCreateAdminForm({ email: '   ', display_name: '  ' })).toEqual({
      email: VALIDATION_REQUIRED,
      display_name: VALIDATION_REQUIRED,
    })
  })

  it('rejects an address that is obviously not one', () => {
    for (const email of ['treasurer', 'treasurer@example', 'treasurer example.com', '@example.com']) {
      expect(validateCreateAdminForm({ email, display_name: 'Treasurer' })).toEqual({
        email: VALIDATION_INVALID_EMAIL,
      })
    }
  })

  it('leaves the authoritative rule to the backend', () => {
    // Unusual but legal enough to send — the API decides.
    expect(validateCreateAdminForm({ email: "o'brien+bar@sub.example.co.uk", display_name: "O'Brien" })).toEqual({})
  })
})

describe('validateCreateTerminalForm', () => {
  it('accepts a complete form', () => {
    expect(validateCreateTerminalForm({ name: 'Bar Terminal', device_id: 'terminal-01' })).toEqual({})
  })

  it('requires both fields', () => {
    expect(validateCreateTerminalForm({ name: '', device_id: '' })).toEqual({
      name: VALIDATION_REQUIRED,
      device_id: VALIDATION_REQUIRED,
    })
    expect(validateCreateTerminalForm({ name: 'Bar Terminal', device_id: '  ' })).toEqual({
      device_id: VALIDATION_REQUIRED,
    })
  })
})
