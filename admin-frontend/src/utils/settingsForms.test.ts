import { describe, expect, it } from 'vitest'
import {
  VALIDATION_INVALID_EMAIL,
  VALIDATION_REQUIRED,
  validateCreateAdminForm,
  validateCreateTerminalForm,
} from './settingsForms'

describe('validateCreateAdminForm', () => {
  it('accepts a complete form', () => {
    expect(
      validateCreateAdminForm({ email: 'treasurer@example.com', display_name: 'Treasurer', roles: ['kassenwart'] }),
    ).toEqual({})
  })

  it('rejects an empty form rather than letting the API do it', () => {
    expect(validateCreateAdminForm({ email: '', display_name: '', roles: [] })).toEqual({
      email: VALIDATION_REQUIRED,
      display_name: VALIDATION_REQUIRED,
      roles: VALIDATION_REQUIRED,
    })
  })

  it('treats whitespace as empty', () => {
    expect(validateCreateAdminForm({ email: '   ', display_name: '  ', roles: ['admin'] })).toEqual({
      email: VALIDATION_REQUIRED,
      display_name: VALIDATION_REQUIRED,
    })
  })

  it('rejects an address that is obviously not one', () => {
    for (const email of ['treasurer', 'treasurer@example', 'treasurer example.com', '@example.com']) {
      expect(validateCreateAdminForm({ email, display_name: 'Treasurer', roles: ['admin'] })).toEqual({
        email: VALIDATION_INVALID_EMAIL,
      })
    }
  })

  it('leaves the authoritative rule to the backend', () => {
    // Unusual but legal enough to send — the API decides.
    expect(
      validateCreateAdminForm({
        email: "o'brien+bar@sub.example.co.uk",
        display_name: "O'Brien",
        roles: ['admin'],
      }),
    ).toEqual({})
  })

  /**
   * No default is pre-checked (Q3 of the role-assignment design session): a
   * form submitted with no role picked at all must be caught here, the same
   * as an empty email — never silently sent as `roles: []`, which the backend
   * would refuse anyway, or silently defaulted to `admin`.
   */
  it('requires at least one role to be selected', () => {
    expect(
      validateCreateAdminForm({ email: 'treasurer@example.com', display_name: 'Treasurer', roles: [] }),
    ).toEqual({
      roles: VALIDATION_REQUIRED,
    })
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
