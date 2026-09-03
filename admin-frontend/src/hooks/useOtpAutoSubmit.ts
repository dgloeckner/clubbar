/**
 * Submit a six-digit OTP the moment its last digit lands — and never submit the
 * same code twice.
 *
 * The second half is not a nicety, it is what makes the first half safe. A
 * duplicate submission is expensive on this backend: `AuthController::mfa()`
 * refuses a code whose TOTP time-step was already consumed and routes that
 * refusal through the same `rejectMfaCode()` path as a wrong code, so a replay
 * counts against `MFA_MAX_ATTEMPTS = 5` *and* against the windowed rate limiter
 * (5 failures / 15 min, per IP and per account). `stepUp()` guards the same way.
 * Three ways to lose an attempt for nothing follow from that, and one guard
 * closes all three:
 *
 *   1. A bare `useEffect` would loop. Its readiness dependency flips back the
 *      instant the request settles, the code has not changed, so it fires
 *      again — five attempts burned in a second and the account locked out.
 *   2. Every E2E page object fills the code and *then* clicks the button. With
 *      auto-submit in place that click is a second submission of the same code.
 *   3. An admin who reaches for the button out of habit after auto-submit has
 *      already fired would pay for the habit.
 *
 * So the attempted value is recorded in a ref *synchronously, before* `submit`
 * is called, and the same guarded callback is what the button and Enter call.
 * The ref is dropped whenever the field is not six digits long, which re-arms
 * the hook on an edit — without that, correcting a typo back to the original
 * value would leave a field that nothing could submit.
 *
 * **The trigger is the code field, and only the code field.** `ready` gates,
 * it never fires: a change in readiness alone submits nothing. That matters
 * where readiness includes another field the admin is still typing — in the
 * step-up dialog the gate is the password, so firing on the readiness edge
 * would send the form on the first character of it, spend the code, and come
 * back 401. Being a gate rather than a trigger is also why `ready` may exclude
 * the code's own completeness: the hook already requires that.
 */

import { useCallback, useEffect, useRef } from 'react'

/** A complete code: exactly six digits, which is what the backend validates. */
const COMPLETE = /^\d{6}$/

/**
 * What an OTP field keeps of whatever was typed, pasted or autofilled into it.
 *
 * Everything that is not a digit is dropped and the first six are kept, so a
 * code copied out of an authenticator app in the spacing those apps render —
 * "123 456", "123-456", a trailing newline from a terminal — completes the
 * field in one change rather than sitting there one character over the limit.
 * A `maxLength` of 6 cannot do this: it counts characters, so a pasted
 * "123 456" arrives truncated to "123 45" and reads as a wrong code.
 */
export function otpFromInput(raw: string): string {
  return raw.replace(/\D/g, '').slice(0, 6)
}

export function useOtpAutoSubmit(code: string, submit: () => void, ready: boolean): () => void {
  // The last value handed to `submit`, or null when the hook is armed.
  const attemptedRef = useRef<string | null>(null)

  // `submit` and `ready` are read through refs so that the effect below can
  // depend on the code alone. A re-render mid-request — a spinner, an error
  // banner, a keystroke in a neighbouring field — must not re-trigger anything.
  const submitRef = useRef(submit)
  submitRef.current = submit
  const readyRef = useRef(ready)
  readyRef.current = ready

  const attempt = useCallback(() => {
    if (!readyRef.current) return
    if (!COMPLETE.test(code)) return
    if (attemptedRef.current === code) return

    attemptedRef.current = code
    submitRef.current()
  }, [code])

  useEffect(() => {
    if (!COMPLETE.test(code)) {
      // Re-arm: the admin is editing, so whatever was attempted is history.
      attemptedRef.current = null
      return
    }

    attempt()
  }, [code, attempt])

  return attempt
}
