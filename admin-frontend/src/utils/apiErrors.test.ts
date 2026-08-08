import { AxiosError, AxiosHeaders } from 'axios'
import { describe, expect, it } from 'vitest'
import { getApiErrorMessage, getApiFieldErrors } from './apiErrors'

/** An axios rejection carrying the body the backend actually returns. */
function apiError(status: number, data: unknown): AxiosError {
  const error = new AxiosError('Request failed with status code ' + status)
  error.response = {
    status,
    statusText: '',
    data,
    headers: {},
    config: { headers: new AxiosHeaders() },
  }
  return error
}

describe('getApiFieldErrors', () => {
  it('reads the `messages` shape the module controllers return', () => {
    // AdminUsers/Controllers/AdminController::store on a duplicate email
    const err = apiError(422, {
      error: 'validation_failed',
      messages: { email: ['Email already exists'] },
    })

    expect(getApiFieldErrors(err)).toEqual({ email: 'Email already exists' })
  })

  it('reads the `errors` shape ValidationException returns', () => {
    const err = apiError(422, {
      error: 'validation_failed',
      message: 'Validation failed',
      errors: { creditor_iban: ['Invalid IBAN'] },
    })

    expect(getApiFieldErrors(err)).toEqual({ creditor_iban: 'Invalid IBAN' })
  })

  it('keeps only the first message per field', () => {
    const err = apiError(422, { messages: { name: ['Name is required', 'Name is too short'] } })

    expect(getApiFieldErrors(err)).toEqual({ name: 'Name is required' })
  })

  it('accepts a bare string instead of a list', () => {
    const err = apiError(422, { messages: { device_id: 'Device ID already exists' } })

    expect(getApiFieldErrors(err)).toEqual({ device_id: 'Device ID already exists' })
  })

  it('drops fields with no usable message', () => {
    const err = apiError(422, { messages: { name: [], email: ['  '], locale: [42] } })

    expect(getApiFieldErrors(err)).toEqual({})
  })

  it('is empty for an error with no per-field detail', () => {
    expect(getApiFieldErrors(apiError(500, { error: 'internal_error', message: 'Boom' }))).toEqual({})
    expect(getApiFieldErrors(new Error('Network Error'))).toEqual({})
    expect(getApiFieldErrors(undefined)).toEqual({})
  })
})

describe('getApiErrorMessage', () => {
  it('prefers what the API said', () => {
    const err = apiError(409, { error: 'business_rule_violation', message: 'Terminal is in use' })

    expect(getApiErrorMessage(err, 'fallback')).toBe('Terminal is in use')
  })

  it('names the rejected field when the body carries no message', () => {
    // The shape that made every duplicate-email failure show nothing at all
    const err = apiError(422, {
      error: 'validation_failed',
      messages: { email: ['Email already exists'] },
    })

    expect(getApiErrorMessage(err, 'Failed to create admin user')).toBe('Email already exists')
  })

  it('joins several rejected fields', () => {
    const err = apiError(422, {
      messages: { name: ['Name is required'], device_id: ['Device ID is required'] },
    })

    expect(getApiErrorMessage(err, 'fallback')).toBe('Name is required Device ID is required')
  })

  it('falls back when the response says nothing usable', () => {
    expect(getApiErrorMessage(apiError(500, { error: 'internal_error' }), 'fallback')).toBe('fallback')
    expect(getApiErrorMessage(apiError(500, { message: '   ' }), 'fallback')).toBe('fallback')
    expect(getApiErrorMessage(apiError(502, '<html>Bad Gateway</html>'), 'fallback')).toBe('fallback')
  })

  it('falls back for a failure that never reached the API', () => {
    expect(getApiErrorMessage(new Error('Network Error'), 'fallback')).toBe('fallback')
    expect(getApiErrorMessage(null, 'fallback')).toBe('fallback')
  })
})
