import { describe, expect, it } from 'vitest'
import { authErrorKey, endsMfaStep } from './authErrors'

describe('endsMfaStep', () => {
  it('ends the step when the attempt cap destroyed the pending session', () => {
    expect(endsMfaStep('mfa_attempts_exceeded')).toBe(true)
  })

  it('ends the step when the pending session expired or was never issued', () => {
    expect(endsMfaStep('mfa_session_expired')).toBe(true)
  })

  it('ends the step when the endpoint is rate-limited', () => {
    expect(endsMfaStep('too_many_attempts')).toBe(true)
  })

  it('keeps the step open for a merely wrong code — a fat-fingered digit is retryable', () => {
    expect(endsMfaStep('invalid_credentials')).toBe(false)
  })

  it('keeps the step open when the failure carried no error code', () => {
    expect(endsMfaStep(undefined)).toBe(false)
  })
})

describe('authErrorKey', () => {
  it('gives lockout outcomes their own wording', () => {
    expect(authErrorKey('too_many_attempts')).toBe('auth.tooManyAttempts')
    expect(authErrorKey('mfa_attempts_exceeded')).toBe('auth.mfaAttemptsExceeded')
    expect(authErrorKey('mfa_session_expired')).toBe('auth.mfaSessionExpired')
  })

  it('leaves unmapped codes to the server message', () => {
    expect(authErrorKey('invalid_credentials')).toBeUndefined()
    expect(authErrorKey(undefined)).toBeUndefined()
  })
})
