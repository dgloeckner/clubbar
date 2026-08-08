/**
 * Authentication error codes the login flow reacts to.
 *
 * The backend bounds guessing on both factors (#78): five wrong TOTP codes
 * destroy the MFA-pending session, and five failed attempts from an IP or
 * against an account inside 15 minutes return 429. Two of those outcomes leave
 * the browser holding a pending session that no longer exists, so the UI has to
 * fall back to the password step instead of asking for another code forever.
 */

/** API error codes that mean the MFA-pending session is gone. */
const MFA_STEP_ENDING_ERRORS = new Set([
  'mfa_attempts_exceeded',
  'mfa_session_expired',
  'too_many_attempts',
])

const ERROR_TRANSLATION_KEYS: Record<string, string> = {
  too_many_attempts: 'auth.tooManyAttempts',
  mfa_attempts_exceeded: 'auth.mfaAttemptsExceeded',
  mfa_session_expired: 'auth.mfaSessionExpired',
}

/** True when the code step cannot continue and the user must sign in again. */
export function endsMfaStep(errorCode?: string): boolean {
  return !!errorCode && MFA_STEP_ENDING_ERRORS.has(errorCode)
}

/**
 * Translation key for an API error code, or undefined when the code has no
 * localized wording of its own and the server message should be shown instead.
 */
export function authErrorKey(errorCode?: string): string | undefined {
  return errorCode ? ERROR_TRANSLATION_KEYS[errorCode] : undefined
}
